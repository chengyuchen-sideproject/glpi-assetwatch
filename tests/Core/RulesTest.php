<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use DateTimeImmutable;
use GlpiPlugin\Assetwatch\Core\DiskRule;
use GlpiPlugin\Assetwatch\Core\NoReportRule;
use GlpiPlugin\Assetwatch\Core\Settings;
use GlpiPlugin\Assetwatch\Core\Wildcard;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    public function testNoReportBelowThresholdIsFine(): void
    {
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $this->assertNull(NoReportRule::evaluate('2026-10-06 00:00:01', 0, $now, Settings::fromArray([])));
    }

    public function testNoReportAtThresholdFires(): void
    {
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $finding = NoReportRule::evaluate('2026-10-06 00:00:00', 0, $now, Settings::fromArray([]));
        $this->assertSame(['last_inventory' => '2026-10-06 00:00:00', 'hours_since' => 36, 'threshold_hours' => 36], $finding);
    }

    public function testNoReportUsesTypeThreshold(): void
    {
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $settings = Settings::fromArray(['noreport_type_hours' => '{"2": 72}']);
        $this->assertNull(NoReportRule::evaluate('2026-10-05 00:00:00', 2, $now, $settings));
        $this->assertNotNull(NoReportRule::evaluate('2026-10-05 00:00:00', 1, $now, $settings));
    }

    public function testNeverInventoriedIsIgnored(): void
    {
        $now = new DateTimeImmutable('2026-10-07 12:00:00');
        $settings = Settings::fromArray([]);
        $this->assertNull(NoReportRule::evaluate(null, 0, $now, $settings));
        $this->assertNull(NoReportRule::evaluate('', 0, $now, $settings));
        $this->assertNull(NoReportRule::evaluate('0000-00-00 00:00:00', 0, $now, $settings));
        $this->assertNull(NoReportRule::evaluate('garbage', 0, $now, $settings));
    }

    public function testDiskNeedsBothThresholds(): void
    {
        $settings = Settings::fromArray([]);
        $disks = [
            // 4 TB with 5% free = 200 GB: percent crossed, GB not -> quiet
            ['mountpoint' => '/data', 'filesystem' => 'xfs', 'total_mb' => 4 * 1024 * 1024, 'free_mb' => 0.05 * 4 * 1024 * 1024],
            // 100 GB with 8 GB free -> alert
            ['mountpoint' => '/', 'filesystem' => 'ext4', 'total_mb' => 102400, 'free_mb' => 8192],
            // 50 GB with 15 GB free (30%) -> quiet
            ['mountpoint' => 'C:', 'filesystem' => 'NTFS', 'total_mb' => 51200, 'free_mb' => 15360],
            // 50 GB with 3 GB free (6%) -> alert, Windows drive letter
            ['mountpoint' => 'D:', 'filesystem' => 'NTFS', 'total_mb' => 51200, 'free_mb' => 3072],
        ];
        $findings = DiskRule::evaluate($disks, $settings);
        $this->assertSame(['/', 'D:'], array_keys($findings));
        $this->assertSame(8.0, $findings['/']['free_gb']);
        $this->assertSame(8.0, $findings['/']['free_percent']);
        $this->assertSame(6.0, $findings['D:']['free_percent']);
        $this->assertSame(100.0, $findings['/']['total_gb']);
    }

    public function testDiskExclusions(): void
    {
        $settings = Settings::fromArray(['disk_excluded_mounts' => '/boot*,/snap/*']);
        $disks = [
            ['mountpoint' => '/run', 'filesystem' => 'tmpfs', 'total_mb' => 1000, 'free_mb' => 0],
            ['mountpoint' => '/snap/core/1', 'filesystem' => 'squashfs', 'total_mb' => 100, 'free_mb' => 0],
            ['mountpoint' => '/boot/efi', 'filesystem' => 'vfat', 'total_mb' => 500, 'free_mb' => 1],
            ['mountpoint' => '/var/lib/docker/overlay2/x', 'filesystem' => 'OVERLAY', 'total_mb' => 1000, 'free_mb' => 0],
            ['mountpoint' => '', 'name' => '/mnt/nameonly', 'filesystem' => 'ext4', 'total_mb' => 1000, 'free_mb' => 1],
            ['mountpoint' => '/zero', 'filesystem' => 'ext4', 'total_mb' => 0, 'free_mb' => 0],
        ];
        $this->assertSame(['/mnt/nameonly'], array_keys(DiskRule::evaluate($disks, $settings)));
    }

    public function testDiskFreeLargerThanTotalIsClamped(): void
    {
        $findings = DiskRule::evaluate([['mountpoint' => '/x', 'total_mb' => 1000, 'free_mb' => 5000]], Settings::fromArray([]));
        $this->assertSame([], $findings);
    }

    public function testWildcard(): void
    {
        $this->assertTrue(Wildcard::matches('docker0', 'docker*'));
        $this->assertTrue(Wildcard::matches('vEthernet (WSL)', 'VETHERNET*'));
        $this->assertTrue(Wildcard::matches('eth1', 'eth?'));
        $this->assertFalse(Wildcard::matches('eth10', 'eth?'));
        $this->assertFalse(Wildcard::matches('mydocker0', 'docker*'));
        $this->assertTrue(Wildcard::matches('a.b', 'a.b'));
        $this->assertFalse(Wildcard::matches('axb', 'a.b'));
    }
}
