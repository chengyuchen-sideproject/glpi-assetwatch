<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use GlpiPlugin\Assetwatch\Core\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultsMatchAgreedPlan(): void
    {
        $s = Settings::fromArray([]);
        $this->assertTrue($s->checkNoReport && $s->checkDisk && $s->checkHardware && $s->checkIdentity && $s->checkRack);
        $this->assertSame(36, $s->noReportDefaultHours);
        $this->assertSame(10.0, $s->diskMinFreePercent);
        $this->assertSame(20.0, $s->diskMinFreeGb);
        $this->assertSame(3, $s->reminderDays);
        $this->assertSame(180, $s->retentionDays);
        $this->assertContains('tmpfs', $s->diskExcludedFs);
        $this->assertContains('docker*', $s->portExcludedPatterns);
    }

    public function testTypeHoursOverrideAndFallback(): void
    {
        $s = Settings::fromArray(['noreport_type_hours' => '{"3": 72, "x": 5, "4": -1}']);
        $this->assertSame(72, $s->noReportHoursFor(3));
        $this->assertSame(36, $s->noReportHoursFor(4));
        $this->assertSame(36, $s->noReportHoursFor(0));
    }

    public function testInvalidValuesFallBackOrClamp(): void
    {
        $s = Settings::fromArray([
            'noreport_default_hours' => 'abc',
            'disk_min_free_percent'  => '150',
            'reminder_days'          => '-4',
            'noreport_type_hours'    => 'not json',
            'check_disk'             => '0',
        ]);
        $this->assertSame(36, $s->noReportDefaultHours);
        $this->assertSame(100.0, $s->diskMinFreePercent);
        $this->assertSame(0, $s->reminderDays);
        $this->assertSame([], $s->noReportTypeHours);
        $this->assertFalse($s->checkDisk);
    }

    public function testSplitListHandlesCommasNewlinesAndBlanks(): void
    {
        $this->assertSame(['a', 'b c', 'd'], Settings::splitList(" a ,, b c\r\nd\n"));
    }
}
