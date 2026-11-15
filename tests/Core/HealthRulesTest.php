<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use DateTimeImmutable;
use GlpiPlugin\Assetwatch\Core\AlertText;
use GlpiPlugin\Assetwatch\Core\HealthRules;
use GlpiPlugin\Assetwatch\Core\ProbeRule;
use GlpiPlugin\Assetwatch\Core\RoomAudit;
use GlpiPlugin\Assetwatch\Core\Settings;
use PHPUnit\Framework\TestCase;

final class HealthRulesTest extends TestCase
{
    public function testProbeClassifyAndCount(): void
    {
        $this->assertSame('ok', ProbeRule::classify(true, true, false));
        $this->assertSame('agent_down', ProbeRule::classify(true, false, true));
        $this->assertSame('unreachable', ProbeRule::classify(true, false, false));
        $this->assertSame('ok', ProbeRule::classify(false, false, true));       // device without agent
        $this->assertSame('unreachable', ProbeRule::classify(false, false, false));

        $s1 = ProbeRule::next(null, 'unreachable', '2026-11-15 10:00:00');
        $this->assertSame(['state' => 'unreachable', 'fail_count' => 1, 'date_first_fail' => '2026-11-15 10:00:00', 'date_last_ok' => null], $s1);
        $this->assertNull(ProbeRule::alertType($s1, 2), 'one failure is not enough');
        $s2 = ProbeRule::next($s1, 'agent_down', '2026-11-15 10:05:00');
        $this->assertSame(2, $s2['fail_count']);
        $this->assertSame('2026-11-15 10:00:00', $s2['date_first_fail'], 'failing since the first failure');
        $this->assertSame(AlertText::TYPE_AGENT_DOWN, ProbeRule::alertType($s2, 2));
        $ok = ProbeRule::next($s2, 'ok', '2026-11-15 10:10:00');
        $this->assertSame(['state' => 'ok', 'fail_count' => 0, 'date_first_fail' => null, 'date_last_ok' => '2026-11-15 10:10:00'], $ok);
        $this->assertNull(ProbeRule::alertType($ok, 1));
        $this->assertSame(AlertText::TYPE_UNREACHABLE, ProbeRule::alertType(ProbeRule::next($ok, 'unreachable', 'x'), 1));
    }

    public function testProbeStormGuardAndAddresses(): void
    {
        $this->assertFalse(ProbeRule::isStorm(array_fill(0, 9, 'unreachable')), 'too few targets to judge');
        $this->assertTrue(ProbeRule::isStorm(array_merge(array_fill(0, 8, 'unreachable'), ['ok', 'ok'])));
        $this->assertFalse(ProbeRule::isStorm(array_merge(array_fill(0, 7, 'unreachable'), ['ok', 'ok', 'ok'])));
        $this->assertSame(['10.0.0.1', '2001:db8::1'], ProbeRule::usableIps(['127.0.0.1', '10.0.0.1', ' 10.0.0.1', 'fe80::1', '2001:db8::1', '']));
        $this->assertSame('[2001:db8::1]', ProbeRule::urlHost('2001:db8::1'));
        $this->assertSame('srv01', ProbeRule::urlHost('srv01'));
    }

    public function testReboot(): void
    {
        $this->assertNull(HealthRules::reboot(null, '2026-11-15 03:00:00', ''), 'no previous boot: baseline only');
        $this->assertNull(HealthRules::reboot('2026-11-15 03:00:00', '2026-11-15 03:01:00', ''), 'clock jitter');
        $this->assertSame(
            ['previous_boot' => '2026-11-01 08:00:00', 'last_boot' => '2026-11-15 10:30:00'],
            HealthRules::reboot('2026-11-01 08:00:00', '2026-11-15 10:30:00', '02:00-06:00')
        );
        $this->assertNull(HealthRules::reboot('2026-11-01 08:00:00', '2026-11-15 03:30:00', '02:00-06:00'), 'in quiet hours');
        // window crossing midnight, several windows, junk ignored
        $this->assertSame([[1380, 120], [720, 780]], HealthRules::parseQuietHours('23:00-02:00; 12:00-13:00, junk, 25:00-26:00'));
        $this->assertTrue(HealthRules::inQuietHours(new DateTimeImmutable('2026-11-15 00:30'), '23:00-02:00'));
        $this->assertFalse(HealthRules::inQuietHours(new DateTimeImmutable('2026-11-15 02:00'), '23:00-02:00'));
        $this->assertSame('23:00-02:00, 12:00-13:00', Settings::fromArray(['reboot_quiet_hours' => '23:00-02:00;12:00-13:00,bad'])->rebootQuietHours);
    }

    public function testAntivirus(): void
    {
        $now = new DateTimeImmutable('2026-11-15 12:00:00');
        $findings = HealthRules::antivirus([
            ['name' => 'Defender', 'is_active' => 1, 'is_uptodate' => 1, 'date_expiration' => null],
            ['name' => 'Trend', 'is_active' => 0, 'is_uptodate' => 0, 'date_expiration' => '2026-10-31', 'antivirus_version' => '12', 'signature_version' => '19.1'],
            ['name' => 'Old', 'is_active' => 1, 'is_uptodate' => 1, 'date_expiration' => '2026-11-14'],
            ['name' => '', 'is_active' => 0],
        ], $now);
        $this->assertSame(['Old', 'Trend'], array_keys($findings));
        $this->assertSame(['disabled', 'outdated', 'expired'], $findings['Trend']['problems']);
        $this->assertSame(['expired'], $findings['Old']['problems']);
        $this->assertSame('2026-10-31', $findings['Trend']['expiration']);
    }

    public function testAgentVersion(): void
    {
        $this->assertSame('1.11', HealthRules::normalizeVersion('GLPI-Agent_v1.11-1'));
        $this->assertSame('', HealthRules::normalizeVersion('none'));
        $this->assertTrue(HealthRules::isAgentOutdated('1.7.3', '1.11'));
        $this->assertFalse(HealthRules::isAgentOutdated('1.11', '1.11'));
        $this->assertFalse(HealthRules::isAgentOutdated('1.12', 'v1.11'));
        $this->assertFalse(HealthRules::isAgentOutdated('1.7', ''), 'no minimum = no check');
        $this->assertFalse(HealthRules::isAgentOutdated('', '1.11'), 'unknown version = no alert');
    }

    public function testDiskForecast(): void
    {
        $now = new DateTimeImmutable('2026-11-15 12:00:00');
        // 100 GB disk, low below min(10 % = 10 GB, 20 GB) = 10 GB; losing 2 GB a day, 40 GB free now -> 15 days
        $this->assertSame(10.0, HealthRules::lowThresholdGb(100, 10, 20));
        $samples = [
            ['date' => '2026-11-10 12:00:00', 'free_gb' => 50.0],
            ['date' => '2026-11-12 12:00:00', 'free_gb' => 46.0],
            ['date' => '2026-11-15 12:00:00', 'free_gb' => 40.0],
        ];
        $f = HealthRules::diskForecast($samples, 10.0, $now, 30);
        $this->assertSame(['days' => 15, 'gb_per_day' => 2.0, 'free_gb' => 40.0, 'threshold_gb' => 10.0, 'samples' => 3], $f);
        $this->assertNull(HealthRules::diskForecast($samples, 10.0, $now, 14), 'later than the horizon');
        $this->assertNull(HealthRules::diskForecast(array_slice($samples, 1), 10.0, $now, 30), 'not enough samples');
        $this->assertNull(HealthRules::diskForecast([
            ['date' => '2026-11-15 08:00:00', 'free_gb' => 50.0],
            ['date' => '2026-11-15 10:00:00', 'free_gb' => 45.0],
            ['date' => '2026-11-15 11:00:00', 'free_gb' => 40.0],
        ], 10.0, $now, 30), 'spread over less than a day');
        $growing = array_map(static fn (array $s): array => ['free_gb' => 100 - $s['free_gb']] + $s, $samples);
        $this->assertNull(HealthRules::diskForecast($growing, 10.0, $now, 30), 'free space growing');
        $this->assertNull(HealthRules::diskForecast($samples, 45.0, $now, 30), 'already below: disk_low handles it');
        $this->assertSame(3, HealthRules::diskForecast(array_merge([['date' => '2026-09-01 00:00:00', 'free_gb' => 90.0]], $samples), 10.0, $now, 30)['samples'], 'older than 30 days ignored');
    }

    public function testSettingsAndAuditStatus(): void
    {
        $s = Settings::fromArray([]);
        $this->assertFalse($s->checkProbe, 'probe is opt-in');
        $this->assertSame([22, 3389, 443, 80], $s->probeTcpPorts);
        $this->assertSame([22, 443], Settings::parsePorts('22; 443 22 0 70000 abc'));
        $this->assertSame('', $s->agentMinVersion);

        $now = new DateTimeImmutable('2026-11-15 12:00:00');
        $computer = ['itemtype' => 'Computer', 'inventoried' => true, 'last_inventory' => '2026-11-15 11:00:00', 'computertypes_id' => 0];
        $this->assertSame(RoomAudit::STATUS_OK, RoomAudit::status($computer, $now, $s));
        $this->assertSame(RoomAudit::STATUS_OFFLINE, RoomAudit::status(['probe_down' => true] + $computer, $now, $s));
        $this->assertSame(RoomAudit::STATUS_OFFLINE, RoomAudit::status(['itemtype' => 'NetworkEquipment', 'inventoried' => false, 'probe_down' => true], $now, $s), 'switch without agent');
        $this->assertSame(RoomAudit::STATUS_NO_AGENT, RoomAudit::status(['itemtype' => 'NetworkEquipment', 'inventoried' => false], $now, $s));
        $this->assertSame(RoomAudit::STATUS_RETIRED, RoomAudit::status(['probe_down' => true, 'last_inventory' => '2026-09-01 00:00:00'] + $computer, $now, $s), 'probably removed wins');

        $this->assertSame('No answer since 2026-11-15 10:00:00 (2 failed checks; last OK: -)', AlertText::summary(AlertText::TYPE_UNREACHABLE, ['since' => '2026-11-15 10:00:00', 'fail_count' => 2]));
        $this->assertSame('[/data] low in about 15 days (40.0 GB free, -2.0 GB/day, threshold 10.0 GB)', AlertText::summary(AlertText::TYPE_DISK_FORECAST, ['mountpoint' => '/data', 'days' => 15, 'free_gb' => 40, 'gb_per_day' => 2, 'threshold_gb' => 10]));
    }
}
