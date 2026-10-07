<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use DateTimeImmutable;
use GlpiPlugin\Assetwatch\Core\DigestBuilder;
use GlpiPlugin\Assetwatch\Core\RackChange;
use GlpiPlugin\Assetwatch\Core\StatusTracker;
use PHPUnit\Framework\TestCase;

final class StatusTrackerTest extends TestCase
{
    private const TYPES = ['no_report', 'disk_low'];

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function alert(int $id, int $items_id, string $type, string $key, array $extra = []): array
    {
        return $extra + [
            'id'                 => $id,
            'itemtype'           => 'Computer',
            'items_id'           => $items_id,
            'alert_type'         => $type,
            'alert_key'          => $key,
            'status'             => StatusTracker::STATUS_ACTIVE,
            'date_last_notified' => '2026-10-07 10:00:00',
            'date_ack_until'     => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(int $items_id, string $type, string $key): array
    {
        return ['itemtype' => 'Computer', 'items_id' => $items_id, 'alert_type' => $type, 'alert_key' => $key];
    }

    public function testOpenRefreshAndResolve(): void
    {
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $open = [
            $this->alert(1, 10, 'no_report', ''),
            $this->alert(2, 11, 'disk_low', '/'),
        ];
        $findings = [
            $this->finding(10, 'no_report', ''),
            $this->finding(12, 'disk_low', 'C:'),
        ];
        $r = StatusTracker::reconcile($open, $findings, self::TYPES, $now, 3);
        $this->assertSame([$this->finding(12, 'disk_low', 'C:')], $r['open']);
        $this->assertSame([1], array_map(static function ($p) {
            return $p['alert']['id'];
        }, $r['refresh']));
        $this->assertSame([2], array_column($r['resolve'], 'id'));
        $this->assertSame([], $r['remind']);
    }

    public function testReminderAfterConfiguredDays(): void
    {
        $now = new DateTimeImmutable('2026-10-10 10:00:00');
        $open = [$this->alert(1, 10, 'no_report', '')];
        $r = StatusTracker::reconcile($open, [$this->finding(10, 'no_report', '')], self::TYPES, $now, 3);
        $this->assertCount(1, $r['remind']);

        $earlier = new DateTimeImmutable('2026-10-10 09:59:59');
        $r = StatusTracker::reconcile($open, [$this->finding(10, 'no_report', '')], self::TYPES, $earlier, 3);
        $this->assertCount(0, $r['remind']);
        $this->assertCount(1, $r['refresh']);
    }

    public function testZeroReminderDaysDisablesReminders(): void
    {
        $now = new DateTimeImmutable('2027-01-01 00:00:00');
        $r = StatusTracker::reconcile([$this->alert(1, 10, 'no_report', '')], [$this->finding(10, 'no_report', '')], self::TYPES, $now, 0);
        $this->assertCount(0, $r['remind']);
    }

    public function testAcknowledgedIsSilentUntilEndDate(): void
    {
        $ack = $this->alert(1, 10, 'disk_low', '/', [
            'status'             => StatusTracker::STATUS_ACKNOWLEDGED,
            'date_last_notified' => '2026-09-01 00:00:00',
            'date_ack_until'     => '2026-10-15 00:00:00',
        ]);
        $finding = [$this->finding(10, 'disk_low', '/')];

        $r = StatusTracker::reconcile([$ack], $finding, self::TYPES, new DateTimeImmutable('2026-10-14 23:00:00'), 3);
        $this->assertCount(0, $r['remind']);

        $r = StatusTracker::reconcile([$ack], $finding, self::TYPES, new DateTimeImmutable('2026-10-15 00:00:00'), 3);
        $this->assertCount(1, $r['remind']);

        $forever = $ack;
        $forever['date_ack_until'] = null;
        $r = StatusTracker::reconcile([$forever], $finding, self::TYPES, new DateTimeImmutable('2030-01-01 00:00:00'), 3);
        $this->assertCount(0, $r['remind']);
    }

    public function testAcknowledgedAlertStillResolves(): void
    {
        $ack = $this->alert(1, 10, 'disk_low', '/', ['status' => StatusTracker::STATUS_ACKNOWLEDGED]);
        $r = StatusTracker::reconcile([$ack], [], self::TYPES, new DateTimeImmutable('2026-10-07 12:00:00'), 3);
        $this->assertSame([1], array_column($r['resolve'], 'id'));
    }

    public function testDisabledTypesAreLeftUntouched(): void
    {
        $open = [$this->alert(1, 10, 'disk_low', '/')];
        $r = StatusTracker::reconcile($open, [$this->finding(11, 'disk_low', '/')], ['no_report'], new DateTimeImmutable(), 3);
        $this->assertSame(['open' => [], 'remind' => [], 'refresh' => [], 'resolve' => []], $r);
    }

    public function testDuplicateOpenAlertsAreCollapsed(): void
    {
        $open = [$this->alert(1, 10, 'no_report', ''), $this->alert(2, 10, 'no_report', '')];
        $r = StatusTracker::reconcile($open, [$this->finding(10, 'no_report', '')], self::TYPES, new DateTimeImmutable('2026-10-07 12:00:00'), 3);
        $this->assertSame([2], array_column($r['resolve'], 'id'));
        $this->assertCount(1, $r['refresh']);
        $this->assertSame([], $r['open']);
    }

    public function testDigestGroupsByEntityAndGroupAndSorts(): void
    {
        $entries = [
            ['kind' => 'resolved', 'entities_id' => 0, 'groups_id_tech' => 5, 'item_name' => 'b', 'alert_type' => 'disk_low', 'alert_key' => '/'],
            ['kind' => 'new', 'entities_id' => 0, 'groups_id_tech' => 5, 'item_name' => 'z', 'alert_type' => 'no_report', 'alert_key' => ''],
            ['kind' => 'new', 'entities_id' => 0, 'groups_id_tech' => 5, 'item_name' => 'a', 'alert_type' => 'no_report', 'alert_key' => ''],
            ['kind' => 'reminder', 'entities_id' => 0, 'groups_id_tech' => 7, 'item_name' => 'c', 'alert_type' => 'no_report', 'alert_key' => ''],
        ];
        $digests = DigestBuilder::build($entries);
        $this->assertCount(2, $digests);
        $this->assertSame(5, $digests[0]['groups_id_tech']);
        $this->assertSame([2, 0, 1], [$digests[0]['nb_new'], $digests[0]['nb_reminder'], $digests[0]['nb_resolved']]);
        $this->assertSame(['a', 'z', 'b'], array_column($digests[0]['entries'], 'item_name'));
        $this->assertSame(7, $digests[1]['groups_id_tech']);
        $this->assertSame(1, $digests[1]['nb_reminder']);
    }

    public function testRackChangeDescriptions(): void
    {
        $a = ['rack' => 'R01', 'position' => 12, 'orientation' => 0, 'hpos' => 0];
        $b = ['rack' => 'R02', 'position' => 3, 'orientation' => 1, 'hpos' => 2];
        $this->assertSame(['action' => 'added', 'old' => '', 'new' => 'R01 / U12'], RackChange::describe(null, $a));
        $this->assertSame(['action' => 'removed', 'old' => 'R01 / U12', 'new' => ''], RackChange::describe($a, null));
        $this->assertSame(['action' => 'moved', 'old' => 'R01 / U12', 'new' => 'R02 / U3 (rear, right)'], RackChange::describe($a, $b));
        // Only cosmetic fields changed -> nothing to report.
        $this->assertNull(RackChange::describe($a, $a + ['bgcolor' => '#ff0000']));
        $this->assertNull(RackChange::describe(null, null));
    }
}
