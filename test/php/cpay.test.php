<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/placeholder.php';

test('from_cpay is true only for an address listed in cpay.push_ips', function (): void {
    $config = ['cpay' => ['push_ips' => ['80.77.147.43']]];
    truthy(from_cpay($config, ['REMOTE_ADDR' => '80.77.147.43']), 'the listed address');
    truthy(from_cpay($config, ['REMOTE_ADDR' => '::ffff:80.77.147.43']), 'the same address written as IPv4-mapped IPv6');
    truthy(!from_cpay($config, ['REMOTE_ADDR' => '80.77.147.44']), 'a neighbour');
    truthy(!from_cpay($config, ['REMOTE_ADDR' => '']), 'an empty address');
    truthy(!from_cpay($config, []), 'no REMOTE_ADDR');
    truthy(
        !from_cpay($config, ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '80.77.147.43']),
        'a forwarded header is never trusted',
    );
});

test('from_cpay is false when the config lists no cPay address', function (): void {
    $server = ['REMOTE_ADDR' => '80.77.147.43'];
    truthy(!from_cpay([], $server), 'no cpay section');
    truthy(!from_cpay(['cpay' => []], $server), 'no list');
    truthy(!from_cpay(['cpay' => ['push_ips' => []]], $server), 'an empty list');
    truthy(!from_cpay(['cpay' => ['push_ips' => ['unknown']]], ['REMOTE_ADDR' => 'garbage']), 'an unreadable address never matches');
});
