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
