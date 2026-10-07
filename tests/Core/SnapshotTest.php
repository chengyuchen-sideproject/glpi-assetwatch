<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use GlpiPlugin\Assetwatch\Core\Settings;
use GlpiPlugin\Assetwatch\Core\Snapshot;
use GlpiPlugin\Assetwatch\Core\SnapshotDiff;
use PHPUnit\Framework\TestCase;

final class SnapshotTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function server(array $overrides = []): array
    {
        $parts = $overrides + [
            'computer'   => ['serial' => 'SN-001', 'name' => 'web01'],
            'memories'   => [['size' => 16384], ['size' => 16384]],
            'processors' => [['designation' => 'Intel Xeon Silver 4210'], ['designation' => 'Intel Xeon Silver 4210']],
            'drives'     => [
                ['serial' => 'D1', 'designation' => 'Samsung SSD', 'capacity' => 953869],
                ['serial' => 'D2', 'designation' => 'Samsung SSD', 'capacity' => 953869],
            ],
            'ports' => [
                ['name' => 'eth0', 'mac' => '00:1A:2B:3C:4D:5E'],
                ['name' => 'docker0', 'mac' => '02:42:ac:11:00:01'],
                ['name' => 'lo', 'mac' => '00:00:00:00:00:00'],
            ],
            'ips' => [
                ['ip' => '10.0.0.5', 'port_name' => 'eth0'],
                ['ip' => '172.17.0.1', 'port_name' => 'docker0'],
                ['ip' => '127.0.0.1', 'port_name' => 'lo'],
                ['ip' => 'fe80::1', 'port_name' => 'eth0'],
            ],
        ];
        return Snapshot::build(
            $parts['computer'],
            $parts['memories'],
            $parts['processors'],
            $parts['drives'],
            $parts['ports'],
            $parts['ips'],
            Settings::fromArray([])
        );
    }

    public function testBuildNormalizesAndFilters(): void
    {
        $snap = $this->server();
        $this->assertSame('SN-001', $snap['serial']);
        $this->assertSame(32768, $snap['memory_mb']);
        $this->assertSame(['Intel Xeon Silver 4210' => 2], $snap['cpus']);
        $this->assertSame(['sn:D1', 'sn:D2'], array_keys($snap['drives']));
        $this->assertSame('Samsung SSD (931.5 GB)', $snap['drives']['sn:D1']);
        $this->assertSame(['00:1a:2b:3c:4d:5e'], $snap['macs']);
        $this->assertSame(['10.0.0.5'], $snap['ips']);
    }

    public function testDrivesWithoutSerialGetStableKeys(): void
    {
        $snap = $this->server(['drives' => [
            ['serial' => '', 'designation' => 'VMware Virtual disk', 'capacity' => 102400],
            ['serial' => null, 'designation' => 'VMware Virtual disk', 'capacity' => 102400],
        ]]);
        $this->assertSame(
            ['nosn:VMware Virtual disk (100 GB)#1', 'nosn:VMware Virtual disk (100 GB)#2'],
            array_keys($snap['drives'])
        );
    }

    public function testIdenticalSnapshotsHaveNoChanges(): void
    {
        $this->assertSame(['hardware' => [], 'identity' => []], SnapshotDiff::compare($this->server(), $this->server()));
    }

    public function testMemoryDecreaseAndDriveRemoval(): void
    {
        $new = $this->server([
            'memories' => [['size' => 16384]],
            'drives'   => [['serial' => 'D1', 'designation' => 'Samsung SSD', 'capacity' => 953869]],
        ]);
        $diff = SnapshotDiff::compare($this->server(), $new);
        $this->assertSame([
            ['field' => 'memory', 'direction' => 'decreased', 'old' => '32 GB', 'new' => '16 GB'],
            ['field' => 'drive', 'direction' => 'removed', 'old' => 'Samsung SSD (931.5 GB) [S/N D2]', 'new' => ''],
        ], $diff['hardware']);
        $this->assertSame([], $diff['identity']);
    }

    public function testCpuAndDriveAdditionsAreReported(): void
    {
        $new = $this->server([
            'processors' => [['designation' => 'Intel Xeon Gold 6230']],
            'drives'     => [
                ['serial' => 'D1', 'designation' => 'Samsung SSD', 'capacity' => 953869],
                ['serial' => 'D2', 'designation' => 'Samsung SSD', 'capacity' => 953869],
                ['serial' => 'D3', 'designation' => 'WD HDD', 'capacity' => 4 * 1024 * 1024],
            ],
        ]);
        $diff = SnapshotDiff::compare($this->server(), $new);
        $this->assertSame([
            ['field' => 'cpu', 'direction' => 'added', 'old' => '', 'new' => 'Intel Xeon Gold 6230 x1'],
            ['field' => 'cpu', 'direction' => 'removed', 'old' => 'Intel Xeon Silver 4210 x2', 'new' => ''],
            ['field' => 'drive', 'direction' => 'added', 'old' => '', 'new' => 'WD HDD (4 TB) [S/N D3]'],
        ], $diff['hardware']);
    }

    public function testIdentityChanges(): void
    {
        $new = $this->server([
            'computer' => ['serial' => 'SN-999', 'name' => 'WEB01'],
            'ports'    => [['name' => 'eth0', 'mac' => '00:1a:2b:3c:4d:ff']],
            'ips'      => [['ip' => '10.0.0.6', 'port_name' => 'eth0']],
        ]);
        $diff = SnapshotDiff::compare($this->server(), $new);
        $this->assertSame([], $diff['hardware']);
        // Hostname differs only by case -> ignored.
        $this->assertSame([
            ['field' => 'serial', 'direction' => 'changed', 'old' => 'SN-001', 'new' => 'SN-999'],
            ['field' => 'mac', 'direction' => 'removed', 'old' => '00:1a:2b:3c:4d:5e', 'new' => ''],
            ['field' => 'mac', 'direction' => 'added', 'old' => '', 'new' => '00:1a:2b:3c:4d:ff'],
            ['field' => 'ip', 'direction' => 'removed', 'old' => '10.0.0.5', 'new' => ''],
            ['field' => 'ip', 'direction' => 'added', 'old' => '', 'new' => '10.0.0.6'],
        ], $diff['identity']);
    }

    public function testVirtualInterfacesDoNotCauseIdentityChanges(): void
    {
        $new = $this->server([
            'ports' => [
                ['name' => 'eth0', 'mac' => '00:1A:2B:3C:4D:5E'],
                ['name' => 'veth12ab', 'mac' => '0a:0b:0c:0d:0e:0f'],
                ['name' => 'br-1234', 'mac' => '02:42:00:00:00:02'],
            ],
            'ips' => [
                ['ip' => '10.0.0.5', 'port_name' => 'eth0'],
                ['ip' => '172.18.0.1', 'port_name' => 'br-1234'],
            ],
        ]);
        $this->assertSame([], SnapshotDiff::compare($this->server(), $new)['identity']);
    }

    public function testPartialInventoryIsNotReportedAsRemoval(): void
    {
        $partial = $this->server(['memories' => [], 'processors' => [], 'drives' => [], 'ports' => [], 'ips' => []]);
        $diff = SnapshotDiff::compare($this->server(), $partial);
        $this->assertSame(['hardware' => [], 'identity' => []], $diff);

        // And the stored baseline keeps the previous hardware / network.
        $stored = Snapshot::mergeForStorage($this->server(), $partial);
        $this->assertSame(32768, $stored['memory_mb']);
        $this->assertSame(['00:1a:2b:3c:4d:5e'], $stored['macs']);
    }

    public function testEmptyNewSerialIsNotAChange(): void
    {
        $new = $this->server(['computer' => ['serial' => '', 'name' => 'web01']]);
        $this->assertSame([], SnapshotDiff::compare($this->server(), $new)['identity']);
    }

    public function testChecksCanBeDisabled(): void
    {
        $new = $this->server(['memories' => [['size' => 1024]], 'computer' => ['serial' => 'X', 'name' => 'y']]);
        $this->assertSame([], SnapshotDiff::compare($this->server(), $new, false, true)['hardware']);
        $this->assertSame([], SnapshotDiff::compare($this->server(), $new, true, false)['identity']);
    }

    public function testMacAndIpHelpers(): void
    {
        $this->assertSame('aa:bb:cc:dd:ee:ff', Snapshot::normalizeMac('AA-BB-CC-DD-EE-FF'));
        $this->assertSame('', Snapshot::normalizeMac('ff:ff:ff:ff:ff:ff'));
        $this->assertSame('', Snapshot::normalizeMac('junk'));
        $this->assertTrue(Snapshot::isUsefulIp('2001:db8::1'));
        $this->assertFalse(Snapshot::isUsefulIp('169.254.3.4'));
        $this->assertFalse(Snapshot::isUsefulIp('not-an-ip'));
        $this->assertSame('512 MB', Snapshot::formatMb(512));
    }
}
