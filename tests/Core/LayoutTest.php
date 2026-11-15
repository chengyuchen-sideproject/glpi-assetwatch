<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use GlpiPlugin\Assetwatch\Core\RackLayout;
use GlpiPlugin\Assetwatch\Core\RoomMap;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    public function testRackBlocksTopDown(): void
    {
        $layout = RackLayout::blocks(10, [
            ['key' => 'srv2u', 'position' => 3, 'units' => 2],   // U3-U4
            ['key' => 'sw', 'position' => 10, 'units' => 1],     // U10
            ['key' => 'halfA', 'position' => 1, 'units' => 1],   // U1, side by side
            ['key' => 'halfB', 'position' => 1, 'units' => 1],
        ]);
        $summary = array_map(static fn (array $b): string => $b['top'] . '-' . $b['bottom'] . ':' . implode('+', $b['keys']), $layout['blocks']);
        $this->assertSame(['10-10:sw', '9-5:', '4-3:srv2u', '2-2:', '1-1:halfA+halfB'], $summary);
        $this->assertSame(5, $layout['blocks'][1]['units']);
        $this->assertSame(4, $layout['used_units']);
        $this->assertSame([], $layout['outside']);
    }

    public function testRackBlocksAdjacentAssetsStaySeparate(): void
    {
        $layout = RackLayout::blocks(4, [
            ['key' => 'a', 'position' => 1, 'units' => 2],
            ['key' => 'b', 'position' => 3, 'units' => 2],
        ]);
        $this->assertSame([['b'], ['a']], array_column($layout['blocks'], 'keys'));
    }

    public function testRackBlocksOutside(): void
    {
        $layout = RackLayout::blocks(4, [
            ['key' => 'top', 'position' => 4, 'units' => 2],   // U4-U5 in a 4U rack
            ['key' => 'zero', 'position' => 0, 'units' => 1],
        ]);
        $this->assertSame(['top', 'zero'], $layout['outside']);
        $this->assertSame(['top'], $layout['blocks'][0]['keys']);
        $this->assertSame(1, $layout['used_units']);
        $this->assertSame([], RackLayout::blocks(0, [])['blocks']);
    }

    public function testRoomPositions(): void
    {
        $this->assertSame(['x' => 3, 'y' => 12], RoomMap::parsePosition(' 3, 12 '));
        foreach ([null, '', '0,1', '3', 'a,b', '1,2,3'] as $bad) {
            $this->assertNull(RoomMap::parsePosition($bad), var_export($bad, true));
        }
    }

    public function testRoomGridGrowsAndRejectsClashes(): void
    {
        $grid = RoomMap::grid(4, 3, [7 => '2,2', 8 => '6,1', 9 => '2,2', 10 => null]);
        $this->assertSame(6, $grid['cols']);
        $this->assertSame(3, $grid['rows']);
        $this->assertSame([7 => ['x' => 2, 'y' => 2], 8 => ['x' => 6, 'y' => 1]], $grid['placed']);
        $this->assertSame([9, 10], $grid['unplaced']);
        $this->assertSame(1, RoomMap::grid(0, 0, [])['cols']);
        // a 10 x 10 room with one rack in the corner: one free cell after it
        $grid = RoomMap::grid(10, 10, [1 => '1,1', 2 => '3,2']);
        $this->assertSame([4, 3], [$grid['cols'], $grid['rows']]);
        $this->assertSame([3, 2], [RoomMap::grid(3, 2, [1 => '3,2'])['cols'], RoomMap::grid(3, 2, [1 => '3,2'])['rows']]);
        // no rack placed: the room size as set
        $this->assertSame(10, RoomMap::grid(10, 10, [1 => null])['cols']);
    }

    public function testRoomMetrics(): void
    {
        $m = RoomMap::metrics(0, 30, 42, 0, 0);
        $this->assertSame(71, $m['units_pct']);
        $this->assertNull($m['power_pct']);
        $this->assertSame(['issues' => 'ok', 'units' => 'warn', 'power' => 'none'], $m['levels']);

        $m = RoomMap::metrics(3, 40, 42, 4000, 5000);
        $this->assertSame(['issues' => 'bad', 'units' => 'bad', 'power' => 'bad'], $m['levels']);
        $this->assertSame('warn', RoomMap::issuesLevel(2));
        $this->assertSame('ok', RoomMap::percentLevel(59, 60, 80));
        $this->assertSame('warn', RoomMap::percentLevel(60, 60, 80));
    }
}
