<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api/lib/request.php';

test('same_origin accepts this host, with or without a port', function (): void {
    truthy(same_origin(['HTTP_ORIGIN' => 'https://www.oazahotel.com.mk', 'HTTP_HOST' => 'www.oazahotel.com.mk']), 'live host');
    truthy(same_origin(['HTTP_ORIGIN' => 'http://127.0.0.1:8765', 'HTTP_HOST' => '127.0.0.1:8765']), 'test server');
});

test('same_origin falls back to Referer when there is no Origin', function (): void {
    truthy(same_origin(['HTTP_REFERER' => 'https://www.oazahotel.com.mk/en/contact/', 'HTTP_HOST' => 'www.oazahotel.com.mk']), 'referer');
});

test('same_origin refuses other sites and requests that name none', function (): void {
    $host = ['HTTP_HOST' => 'www.oazahotel.com.mk'];
    truthy(!same_origin($host + ['HTTP_ORIGIN' => 'https://evil.example']), 'other site');
    truthy(!same_origin($host + ['HTTP_ORIGIN' => 'https://www.oazahotel.com.mk.evil.example']), 'look-alike');
    truthy(!same_origin($host + ['HTTP_ORIGIN' => 'null']), 'null origin');
    truthy(!same_origin($host), 'neither header');
});

test('is_form_post accepts only form-encoded bodies', function (): void {
    truthy(is_form_post(['CONTENT_TYPE' => 'application/x-www-form-urlencoded;charset=UTF-8']), 'form');
    truthy(!is_form_post(['CONTENT_TYPE' => 'application/json']), 'json');
    truthy(!is_form_post([]), 'none');
});

test('visitor_address keeps an IPv4 address and groups IPv6 by its /64', function (): void {
    same('203.0.113.7', visitor_address('203.0.113.7'), 'IPv4');
    $a = visitor_address('2001:db8:1:2:aaaa:bbbb:cccc:dddd');
    same($a, visitor_address('2001:db8:1:2::1'), 'same /64');
    same('20010db800010002/64', $a, 'format');
    truthy($a !== visitor_address('2001:db8:1:3::1'), 'another /64');
    same(visitor_address('2001:0DB8:1:2::'), $a, 'spelling does not matter');
});

test('visitor_address handles IPv4-mapped IPv6, empty and garbage input', function (): void {
    same('203.0.113.7', visitor_address('::ffff:203.0.113.7'), 'a mapped address is the IPv4 visitor');
    same('unknown', visitor_address(''), 'empty');
    same('unknown', visitor_address('not an address'), 'garbage');
    same('unknown', visitor_address('999.1.1.1'), 'out of range');
});
