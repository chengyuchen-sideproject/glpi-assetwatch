<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use GlpiPlugin\Assetwatch\Core\AlertText;
use PHPUnit\Framework\TestCase;

final class AlertTextTest extends TestCase
{
    public function testSummaries(): void
    {
        $this->assertSame(
            '50 h without inventory (last: 2026-10-05 10:00:00, threshold: 36 h)',
            AlertText::summary('no_report', ['hours_since' => 50, 'last_inventory' => '2026-10-05 10:00:00', 'threshold_hours' => 36])
        );
        $this->assertSame(
            '[/var] 8.0 GB free / 100.0 GB (8.0%)',
            AlertText::summary('disk_low', ['mountpoint' => '/var', 'free_gb' => 8, 'total_gb' => 100, 'free_percent' => 8])
        );
        $this->assertSame(
            'memory 32 GB -> 16 GB; drive - SSD [S/N D2]; ip + 10.0.0.6',
            AlertText::summary('hardware', ['changes' => [
                ['field' => 'memory', 'old' => '32 GB', 'new' => '16 GB'],
                ['field' => 'drive', 'old' => 'SSD [S/N D2]', 'new' => ''],
                ['field' => 'ip', 'old' => '', 'new' => '10.0.0.6'],
            ]])
        );
        $this->assertSame('R01 / U12 -> R02 / U3 (glpi)', AlertText::summary('rack', ['old' => 'R01 / U12', 'new' => 'R02 / U3', 'user' => 'glpi']));
        $this->assertSame('', AlertText::summary('unknown', []));
    }

    public function testTruncateIsMultibyteSafe(): void
    {
        $text = str_repeat('機', 300);
        $result = AlertText::truncate($text);
        $this->assertSame(255, mb_strlen($result, 'UTF-8'));
        $this->assertStringEndsWith('...', $result);
        $this->assertSame('short', AlertText::truncate('short'));
    }
}
