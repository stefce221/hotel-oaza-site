<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api/lib/ratelimit.php';

test('rate limit: the sixth enquiry within the hour is refused, per visitor', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'oaza-rate-');
    for ($i = 0; $i < 5; $i++) {
        same(false, rate_limited($file, 'a', 5, 3600, 1000 + $i), 'enquiry ' . ($i + 1));
    }
    same(true, rate_limited($file, 'a', 5, 3600, 1010), 'the sixth');
    same(false, rate_limited($file, 'b', 5, 3600, 1010), 'another visitor');
    same(false, rate_limited($file, 'a', 5, 3600, 4602), 'once the first ones are an hour old');
    unlink($file);
});

test('rate limit: entries older than the window are dropped from the file', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'oaza-rate-');
    rate_limited($file, 'old', 5, 3600, 1000);
    rate_limited($file, 'new', 5, 3600, 5000);
    same(['new'], array_keys(json_decode((string) file_get_contents($file), true)));
    unlink($file);
});

test('rate limit: a damaged file starts afresh', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'oaza-rate-');
    file_put_contents($file, 'not json');
    same(false, rate_limited($file, 'a', 5, 3600, 1000));
    unlink($file);
});
