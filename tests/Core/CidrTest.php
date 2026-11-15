<?php

namespace GlpiPlugin\Assetwatch\Tests\Core;

use GlpiPlugin\Assetwatch\Core\Cidr;
use GlpiPlugin\Assetwatch\Core\Settings;
use PHPUnit\Framework\TestCase;

final class CidrTest extends TestCase
{
    public function testParseListNormalizesAndReportsInvalid(): void
    {
        $parsed = Cidr::parseList("10.1.2.3/16\n192.168.1.10; 2001:DB8::1/32 bogus 10.0.0.0/33 10.0.0.0/x");
        $this->assertSame(['10.1.0.0/16', '192.168.1.10/32', '2001:db8::/32'], array_column($parsed['networks'], 'text'));
        $this->assertSame(['bogus', '10.0.0.0/33', '10.0.0.0/x'], $parsed['invalid']);
    }

    public function testContainsAny(): void
    {
        $networks = Cidr::parseList('10.1.0.0/16,10.2.5.0/24,2001:db8::/32,192.168.9.9')['networks'];
        $this->assertTrue(Cidr::containsAny($networks, '10.1.255.255'));
        $this->assertTrue(Cidr::containsAny($networks, '10.2.5.200'));
        $this->assertFalse(Cidr::containsAny($networks, '10.2.6.1'));
        $this->assertTrue(Cidr::containsAny($networks, '192.168.9.9'));
        $this->assertFalse(Cidr::containsAny($networks, '192.168.9.10'));
        $this->assertTrue(Cidr::containsAny($networks, '2001:db8:ffff::1'));
        // IPv4 address never matches an IPv6 network and vice versa
        $this->assertFalse(Cidr::containsAny(Cidr::parseList('::/0')['networks'], '10.0.0.1'));
        $this->assertTrue(Cidr::containsAny(Cidr::parseList('0.0.0.0/0')['networks'], '10.0.0.1'));
        $this->assertFalse(Cidr::containsAny($networks, 'not-an-ip'));
    }

    public function testIgnorable(): void
    {
        foreach (['127.0.0.1', '169.254.1.1', '::1', 'fe80::1', '0.0.0.0', 'garbage'] as $ip) {
            $this->assertTrue(Cidr::isIgnorable($ip), $ip);
        }
        foreach (['10.0.0.1', '192.168.1.1', '2001:db8::1'] as $ip) {
            $this->assertFalse(Cidr::isIgnorable($ip), $ip);
        }
    }

    public function testRoomSubnetsSetting(): void
    {
        $settings = Settings::fromArray(['room_subnets' => '{"3": "10.1.0.0/16\n10.9.0.0/24", "4": "bogus", "0": "1.1.1.1", "x": "2.2.2.2", "5": ""}']);
        $this->assertSame([3 => '10.1.0.0/16,10.9.0.0/24', 4 => 'bogus'], $settings->roomSubnets);
        $networks = $settings->roomNetworks();
        $this->assertSame([3], array_keys($networks));
        $this->assertCount(2, $networks[3]);
        $this->assertSame(30, Settings::fromArray([])->retireDays);
        $this->assertSame(1, Settings::fromArray(['retire_days' => '0'])->retireDays);
        $this->assertSame([], Settings::fromArray(['room_subnets' => 'not json'])->roomSubnets);
    }
}
