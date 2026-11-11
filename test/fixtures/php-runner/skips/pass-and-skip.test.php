<?php
/* A fixture of test/php.test.mjs, run by test/php/run.php with OAZA_PHP_TESTS pointing here; not part of the PHP suite. */
declare(strict_types=1);

test('fixture: a test that passes', function (): void {
    same(1, 1);
});

test('fixture: a test that cannot run here', function (): void {
    skip('the fixture\'s reason');
});
