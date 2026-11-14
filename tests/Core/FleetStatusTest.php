<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use DateTimeImmutable;
use GlpiPlugin\Assetwatch\Core\FleetStatus;
use GlpiPlugin\Assetwatch\Core\Settings;
use PHPUnit\Framework\TestCase;

final class FleetStatusTest extends TestCase
{
    public function testSummarizeCountsOfflineDiskLowAndHealthy(): void
    {
        $now = new DateTimeImmutable('2026-11-15 12:00:00');
        $settings = Settings::fromArray(['noreport_type_hours' => '{"2": 72}']);
        $computers = [
            1 => ['last_inventory_update' => '2026-11-15 08:00:00', 'computertypes_id' => 0], // fine
            2 => ['last_inventory_update' => '2026-11-13 00:00:00', 'computertypes_id' => 0], // 60 h > 36 h: offline
            3 => ['last_inventory_update' => '2026-11-13 00:00:00', 'computertypes_id' => 2], // 60 h < 72 h for type 2: fine
            4 => ['last_inventory_update' => null, 'computertypes_id' => 0],                  // never inventoried: not offline
            5 => ['last_inventory_update' => '2026-11-12 00:00:00', 'computertypes_id' => 0], // offline and disk low
        ];
        $status = FleetStatus::summarize($computers, [5, 1, 1, 99], $now, $settings);

        $this->assertSame(5, $status['total']);
        $this->assertSame(2, $status['offline']);
        $this->assertSame([2, 5], $status['offline_ids']);
        // 99 is not monitored (deleted / not dynamic), duplicates counted once
        $this->assertSame(2, $status['disk_low']);
        // problems: 2, 5 (offline) and 1 (disk) -> 3 and 4 are healthy
        $this->assertSame(2, $status['healthy']);
    }

    public function testSummarizeEmptyFleet(): void
    {
        $status = FleetStatus::summarize([], [3], new DateTimeImmutable(), Settings::fromArray([]));
        $this->assertSame(['total' => 0, 'offline' => 0, 'disk_low' => 0, 'healthy' => 0, 'offline_ids' => []], $status);
    }

    public function testPerDayKeepsEmptyDaysAndIgnoresOutOfRange(): void
    {
        $now = new DateTimeImmutable('2026-11-15 09:30:00');
        $days = FleetStatus::perDay(
            ['2026-11-15 01:00:00', '2026-11-15 23:59:59', '2026-11-13 10:00:00', '2026-11-12 10:00:00', '2026-11-16 00:00:00'],
            $now,
            3
        );
        $this->assertSame(['2026-11-13' => 1, '2026-11-14' => 0, '2026-11-15' => 2], $days);
    }
}
