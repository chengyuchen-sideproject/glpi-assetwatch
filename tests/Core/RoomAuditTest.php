<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use DateTimeImmutable;
use GlpiPlugin\Assetwatch\Core\Cidr;
use GlpiPlugin\Assetwatch\Core\RoomAudit;
use GlpiPlugin\Assetwatch\Core\Settings;
use PHPUnit\Framework\TestCase;

final class RoomAuditTest extends TestCase
{
    private DateTimeImmutable $now;
    private Settings $settings;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-15 12:00:00');
        // default no-report threshold 36 h, type 2 = 72 h, probably removed after 30 days
        $this->settings = Settings::fromArray(['noreport_type_hours' => '{"2": 72}']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function computer(array $overrides = []): array
    {
        return $overrides + [
            'itemtype'          => 'Computer',
            'dcrooms_id'        => 1,
            'racked'            => true,
            'inventoried'       => true,
            'last_inventory'    => '2026-11-15 08:00:00',
            'computertypes_id'  => 0,
            'disk_low'          => false,
            'tech_groups'       => ['Server Team'],
            'ips'               => ['10.1.2.3'],
            'serial_duplicates' => 0,
        ];
    }

    public function testStatusOrder(): void
    {
        $s = fn (array $o): string => RoomAudit::status($this->computer($o), $this->now, $this->settings);

        $this->assertSame(RoomAudit::STATUS_OK, $s([]));
        $this->assertSame(RoomAudit::STATUS_DISK_LOW, $s(['disk_low' => true]));
        // 60 h > 36 h
        $this->assertSame(RoomAudit::STATUS_OFFLINE, $s(['last_inventory' => '2026-11-13 00:00:00']));
        // type threshold 72 h: 60 h is fine
        $this->assertSame(RoomAudit::STATUS_OK, $s(['last_inventory' => '2026-11-13 00:00:00', 'computertypes_id' => 2]));
        // offline wins over disk low
        $this->assertSame(RoomAudit::STATUS_OFFLINE, $s(['last_inventory' => '2026-11-13 00:00:00', 'disk_low' => true]));
        // exactly 30 days = probably removed
        $this->assertSame(RoomAudit::STATUS_RETIRED, $s(['last_inventory' => '2026-10-16 12:00:00']));
        $this->assertSame(RoomAudit::STATUS_OFFLINE, $s(['last_inventory' => '2026-10-16 13:00:00']));
        $this->assertSame(RoomAudit::STATUS_NO_AGENT, $s(['inventoried' => false]));
        $this->assertSame(RoomAudit::STATUS_NO_AGENT, $s(['last_inventory' => null]));
        $this->assertSame(RoomAudit::STATUS_NO_AGENT, $s(['last_inventory' => '0000-00-00 00:00:00']));
    }

    public function testRetireThresholdIsConfigurable(): void
    {
        $settings = Settings::fromArray(['retire_days' => '7']);
        $item = $this->computer(['last_inventory' => '2026-11-07 12:00:00']);
        $this->assertSame(RoomAudit::STATUS_RETIRED, RoomAudit::status($item, $this->now, $settings));
    }

    public function testOtherAssetTypesUseDefaultThreshold(): void
    {
        // computertypes_id is ignored for non computers: 60 h > 36 h default
        $switch = $this->computer(['itemtype' => 'NetworkEquipment', 'last_inventory' => '2026-11-13 00:00:00', 'computertypes_id' => 2]);
        $this->assertSame(RoomAudit::STATUS_OFFLINE, RoomAudit::status($switch, $this->now, $this->settings));
        $pdu = ['itemtype' => 'PDU', 'inventoried' => false, 'last_inventory' => null];
        $this->assertSame(RoomAudit::STATUS_NO_AGENT, RoomAudit::status($pdu, $this->now, $this->settings));
    }

    public function testIssues(): void
    {
        $net = Cidr::parseList('10.1.0.0/16')['networks'];
        $issues = fn (array $item, string $status, array $n = []): array => array_keys(RoomAudit::issues($item, $status, $n));

        $this->assertSame([], $issues($this->computer(), RoomAudit::STATUS_OK, $net));
        // computer in a rack, never reported
        $this->assertSame([RoomAudit::ISSUE_NEVER_REPORTED], $issues($this->computer(['inventoried' => false]), RoomAudit::STATUS_NO_AGENT));
        // a PDU without agent is normal
        $this->assertSame([], $issues(['itemtype' => 'PDU', 'racked' => true], RoomAudit::STATUS_NO_AGENT, $net));
        $this->assertSame([RoomAudit::ISSUE_RETIRED], $issues($this->computer(), RoomAudit::STATUS_RETIRED));
        $this->assertSame([RoomAudit::ISSUE_UNRACKED], $issues($this->computer(['racked' => false]), RoomAudit::STATUS_OK));
        $this->assertSame([RoomAudit::ISSUE_DUPLICATE_SERIAL], $issues($this->computer(['serial_duplicates' => 1]), RoomAudit::STATUS_OK));
        $this->assertSame([RoomAudit::ISSUE_NO_TECH_GROUP], $issues($this->computer(['tech_groups' => []]), RoomAudit::STATUS_OK));
        // no technical group on a switch is not reported (alerts only concern computers)
        $this->assertSame([], $issues($this->computer(['itemtype' => 'NetworkEquipment', 'tech_groups' => []]), RoomAudit::STATUS_OK));
    }

    public function testSubnetMismatch(): void
    {
        $net = Cidr::parseList('10.1.0.0/16, 2001:db8:1::/48')['networks'];
        $detail = fn (array $ips, string $type = 'Computer', array $n = []) => RoomAudit::issues(
            $this->computer(['ips' => $ips, 'itemtype' => $type]),
            RoomAudit::STATUS_OK,
            $n === [] ? $net : $n
        )[RoomAudit::ISSUE_SUBNET_MISMATCH] ?? null;

        $this->assertNull($detail(['10.1.9.9']));
        // one address inside the room networks is enough (management + backup NICs)
        $this->assertNull($detail(['192.168.5.5', '10.1.0.7']));
        $this->assertSame('192.168.5.5, 10.2.0.1', $detail(['192.168.5.5', '10.2.0.1', '127.0.0.1', 'fe80::1']));
        $this->assertNull($detail(['2001:db8:1:5::10']));
        $this->assertSame('2001:db8:2::10', $detail(['2001:db8:2::10']));
        // no usable address: nothing to compare
        $this->assertNull($detail([]));
        $this->assertNull($detail(['127.0.0.1', '169.254.3.3']));
        $this->assertSame('192.168.5.5', $detail(['192.168.5.5'], 'NetworkEquipment'));
        $this->assertNull($detail(['192.168.5.5'], 'PDU'));
        // room without configured networks: not checked
        $this->assertArrayNotHasKey(
            RoomAudit::ISSUE_SUBNET_MISMATCH,
            RoomAudit::issues($this->computer(['ips' => ['192.168.5.5']]), RoomAudit::STATUS_OK, [])
        );
    }

    public function testEvaluateCountsAndUsesRoomNetworks(): void
    {
        $items = [
            $this->computer(),
            $this->computer(['dcrooms_id' => 2, 'ips' => ['10.1.2.3']]),         // room 2 expects 172.16/12
            $this->computer(['last_inventory' => '2026-09-01 00:00:00']),       // retired
            $this->computer(['racked' => false, 'tech_groups' => []]),          // unracked + no group
            ['itemtype' => 'PDU', 'dcrooms_id' => 1, 'racked' => true, 'inventoried' => false],
        ];
        $networks = [
            1 => Cidr::parseList('10.1.0.0/16')['networks'],
            2 => Cidr::parseList('172.16.0.0/12')['networks'],
        ];
        $result = RoomAudit::evaluate($items, $networks, $this->now, $this->settings);

        $this->assertSame(['ok' => 3, 'disk_low' => 0, 'offline' => 0, 'retired' => 1, 'no_agent' => 1], $result['status_counts']);
        $this->assertSame(1, $result['issue_counts'][RoomAudit::ISSUE_SUBNET_MISMATCH]);
        $this->assertSame(1, $result['issue_counts'][RoomAudit::ISSUE_RETIRED]);
        $this->assertSame(1, $result['issue_counts'][RoomAudit::ISSUE_UNRACKED]);
        $this->assertSame(1, $result['issue_counts'][RoomAudit::ISSUE_NO_TECH_GROUP]);
        $this->assertSame(3, $result['items_with_issues']);
        $this->assertSame('ok', $result['items'][0]['status']);
        $this->assertSame([], $result['items'][0]['issues']);
        $this->assertSame(['subnet_mismatch' => '10.1.2.3'], $result['items'][1]['issues']);

        // Keeping part of the items (one rack): counts follow, items renumbered.
        $part = RoomAudit::summarize([3 => $result['items'][2], 4 => $result['items'][4]]);
        $this->assertSame(['ok' => 0, 'disk_low' => 0, 'offline' => 0, 'retired' => 1, 'no_agent' => 1], $part['status_counts']);
        $this->assertSame(1, $part['items_with_issues']);
        $this->assertSame([0, 1], array_keys($part['items']));
    }
}
