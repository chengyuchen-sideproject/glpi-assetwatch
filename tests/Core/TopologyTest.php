<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use GlpiPlugin\Assetwatch\Core\Topology;
use PHPUnit\Framework\TestCase;

final class TopologyTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function nodes(): array
    {
        return [
            'NetworkEquipment:1' => ['is_switch' => true, 'expect_link' => true, 'sort' => 'R01|958'],   // core, U41
            'NetworkEquipment:2' => ['is_switch' => true, 'expect_link' => true, 'sort' => 'R02|957'],
            'NetworkEquipment:3' => ['is_switch' => true, 'expect_link' => true, 'sort' => 'R03|957'],   // nothing on it
            'Computer:10'        => ['is_switch' => false, 'expect_link' => true, 'sort' => 'R01|989'],
            'Computer:11'        => ['is_switch' => false, 'expect_link' => true, 'sort' => 'R01|990'],
            'Computer:12'        => ['is_switch' => false, 'expect_link' => true, 'sort' => 'R02|995'],
            'Computer:13'        => ['is_switch' => false, 'expect_link' => true, 'sort' => 'R02|996'],
            'PDU:20'             => ['is_switch' => false, 'expect_link' => false, 'sort' => 'R01|998'],
        ];
    }

    public function testMergeSourcesAndOrient(): void
    {
        $graph = Topology::build($this->nodes(), [
            // GLPI port connection, switch side first
            ['a' => 'NetworkEquipment:1', 'b' => 'Computer:10', 'a_port' => 'Gi1/0/3', 'b_port' => 'eth0', 'source' => 'auto'],
            // the same pair typed by hand with another port: the GLPI ports win, the manual id is kept
            ['a' => 'Computer:10', 'b' => 'NetworkEquipment:1', 'b_port' => 'Gi1/0/9', 'source' => 'manual', 'id' => 5],
            // manual only, no port; then the device's second uplink
            ['a' => 'Computer:11', 'b' => 'NetworkEquipment:1', 'source' => 'manual', 'id' => 6],
            ['a' => 'Computer:11', 'b' => 'NetworkEquipment:2', 'b_port' => 'Te1/48', 'source' => 'manual', 'id' => 7],
            // switch to switch, seen from both ends
            ['a' => 'NetworkEquipment:2', 'b' => 'NetworkEquipment:1', 'a_port' => 'Te1/48', 'b_port' => 'Te1/1', 'source' => 'auto'],
            ['a' => 'NetworkEquipment:1', 'b' => 'NetworkEquipment:2', 'a_port' => 'Te1/1', 'b_port' => 'Te1/48', 'source' => 'auto'],
            // computer to computer, unknown node, self link: ignored
            ['a' => 'Computer:12', 'b' => 'Computer:13', 'source' => 'auto'],
            ['a' => 'Computer:12', 'b' => 'NetworkEquipment:99', 'source' => 'manual', 'id' => 8],
            ['a' => 'NetworkEquipment:1', 'b' => 'NetworkEquipment:1', 'source' => 'auto'],
        ]);

        $this->assertSame(['NetworkEquipment:1', 'NetworkEquipment:2', 'NetworkEquipment:3'], $graph['switches']);
        $this->assertSame([[
            'switch' => 'NetworkEquipment:1', 'device_port' => 'eth0', 'switch_port' => 'Gi1/0/3',
            'sources' => ['auto', 'manual'], 'manual_ids' => [5],
        ]], $graph['devices']['Computer:10']);
        $this->assertSame(['NetworkEquipment:1', 'NetworkEquipment:2'], array_column($graph['devices']['Computer:11'], 'switch'));
        $this->assertCount(1, $graph['switch_links']);
        $this->assertSame(['a' => 'NetworkEquipment:1', 'b' => 'NetworkEquipment:2', 'a_port' => 'Te1/1', 'b_port' => 'Te1/48'],
            array_intersect_key($graph['switch_links'][0], array_flip(['a', 'b', 'a_port', 'b_port'])));
        // PDU does not expect a link; switch 3 has nothing; 12 and 13 have no switch
        $this->assertSame(['Computer:12', 'Computer:13', 'NetworkEquipment:3'], $graph['unconnected']);
        $this->assertSame(3, $graph['ignored']);
        // Te1/48 of switch 2 is used by the uplink to switch 1 and by Computer:11
        $this->assertSame([['switch' => 'NetworkEquipment:2', 'port' => 'Te1/48', 'keys' => ['Computer:11', 'NetworkEquipment:1']]], $graph['port_conflicts']);
    }

    public function testManualFirstThenAutoReplacesPorts(): void
    {
        $graph = Topology::build($this->nodes(), [
            ['a' => 'Computer:12', 'b' => 'NetworkEquipment:2', 'b_port' => 'typo', 'source' => 'manual', 'id' => 1],
            ['a' => 'Computer:12', 'b' => 'NetworkEquipment:2', 'a_port' => 'eno1', 'b_port' => 'Gi1/0/7', 'source' => 'auto'],
            // manual without port completes nothing: the auto one already has ports
            ['a' => 'Computer:12', 'b' => 'NetworkEquipment:2', 'source' => 'manual', 'id' => 2],
            // manual entries complete each other's missing port
            ['a' => 'Computer:13', 'b' => 'NetworkEquipment:2', 'source' => 'manual', 'id' => 3],
            ['a' => 'Computer:13', 'b' => 'NetworkEquipment:2', 'b_port' => 'Gi1/0/8', 'source' => 'manual', 'id' => 4],
        ]);
        $this->assertSame('Gi1/0/7', $graph['devices']['Computer:12'][0]['switch_port']);
        $this->assertSame([1, 2], $graph['devices']['Computer:12'][0]['manual_ids']);
        $this->assertSame('Gi1/0/8', $graph['devices']['Computer:13'][0]['switch_port']);
        $this->assertSame(['manual'], $graph['devices']['Computer:13'][0]['sources']);
        $this->assertSame([], $graph['port_conflicts']);
    }

    public function testDuplicatePortIsCaseInsensitive(): void
    {
        $graph = Topology::build($this->nodes(), [
            ['a' => 'Computer:12', 'b' => 'NetworkEquipment:2', 'b_port' => 'gi1/0/1', 'source' => 'manual', 'id' => 1],
            ['a' => 'Computer:13', 'b' => 'NetworkEquipment:2', 'b_port' => 'Gi1/0/1 ', 'source' => 'manual', 'id' => 2],
        ]);
        $this->assertSame(['Computer:12', 'Computer:13'], $graph['port_conflicts'][0]['keys']);
    }

    public function testLayout(): void
    {
        $nodes = $this->nodes();
        $graph = Topology::build($nodes, [
            ['a' => 'Computer:11', 'b' => 'NetworkEquipment:1', 'b_port' => 'Gi1/0/2', 'source' => 'manual', 'id' => 1],
            ['a' => 'Computer:10', 'b' => 'NetworkEquipment:1', 'b_port' => 'Gi1/0/10', 'source' => 'auto'],
            ['a' => 'Computer:11', 'b' => 'NetworkEquipment:2', 'source' => 'manual', 'id' => 2],
            ['a' => 'NetworkEquipment:1', 'b' => 'NetworkEquipment:3', 'source' => 'auto'],
        ]);
        $layout = Topology::layout($graph, $nodes);

        // 3 switch columns + the unconnected one (Computer:12, 13; switch 2 has a device so it is not lonely)
        $this->assertSame(['NetworkEquipment:1', 'NetworkEquipment:2', 'NetworkEquipment:3', Topology::NONE], array_column($layout['columns'], 'key'));
        $by_column = [];
        foreach ($layout['device_boxes'] as $box) {
            $by_column[$box['column']][] = $box['key'];
        }
        $ports = ['', 'Gi1/0/10', 'Gi1/0/2'];
        usort($ports, [Topology::class, 'comparePorts']);
        $this->assertSame(['Gi1/0/2', 'Gi1/0/10', ''], $ports, 'unknown ports last');
        // natural port order: Gi1/0/2 before Gi1/0/10
        $this->assertSame(['Computer:11', 'Computer:10'], $by_column['NetworkEquipment:1']);
        $this->assertSame(['Computer:12', 'Computer:13'], $by_column[Topology::NONE]);
        $this->assertArrayNotHasKey('NetworkEquipment:2', $by_column);

        $kinds = array_count_values(array_column($layout['edges'], 'kind'));
        $this->assertSame(['primary' => 2, 'secondary' => 1, 'switch' => 1], $kinds);
        // arcs above the switches: the top leaves room for the arc between columns 0 and 2
        $this->assertSame(Topology::MARGIN + 24 + 14 * 2, $layout['top']);
        $this->assertGreaterThan($layout['top'] + Topology::SW_H, $layout['height']);
        $this->assertSame(Topology::MARGIN * 2 + 4 * Topology::COL_W - (Topology::COL_W - Topology::BOX_W), $layout['width']);
    }
}
