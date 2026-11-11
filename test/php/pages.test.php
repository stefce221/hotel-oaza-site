<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/cpay/lib/pages.php';

/**
 * Runs PHP code as a script of its own (headers can only be set before any output) and returns what it printed.
 * $php is the command that gets the script's path as its last word: the running PHP unless a test names another.
 */
function pages_test_script(string $body, array $php = [PHP_BINARY]): array
{
    $script = (string) tempnam(sys_get_temp_dir(), 'oaza-pages-');
    $library = var_export(dirname(__DIR__, 2) . '/src/cpay/lib/pages.php', true);
    file_put_contents($script, "<?php\ndeclare(strict_types=1);\nrequire $library;\n$body");
    try {
        $process = proc_open([...$php, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        truthy(is_resource($process), 'PHP started');
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return ['out' => $out, 'err' => $err];
    } finally {
        unlink($script);
    }
}

/** php-cgi beside the running PHP, or null when it is not installed. The command line drops every header; php-cgi prints them. */
function pages_test_cgi(): ?string
{
    $cgi = dirname(PHP_BINARY) . '/php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    return is_file($cgi) ? $cgi : null;
}

/** Runs PHP code under php-cgi and returns the header lines it sent (as a web server would get them) and the body. */
function pages_test_response(string $cgi, string $body): array
{
    $run = pages_test_script($body, [$cgi, '-d', 'cgi.force_redirect=0', '-d', 'display_errors=1']);
    $parts = explode("\r\n\r\n", $run['out'], 2);
    return ['headers' => explode("\r\n", $parts[0]), 'body' => $parts[1] ?? '', 'err' => $run['err']];
}

/** Checks that each of the five headers every answer carries was sent exactly once, with this content type and no other. */
function pages_test_sent_headers(string $contentType, array $headers, string $label): void
{
    $expected = [
        'Content-Type: ' . $contentType . '; charset=utf-8',
        'Cache-Control: no-store',
        'X-Robots-Tag: noindex',
        "Content-Security-Policy: frame-ancestors 'none'",
        'X-Content-Type-Options: nosniff',
    ];
    foreach ($expected as $line) {
        $name = strtolower(strstr($line, ':', true));
        $sent = array_values(array_filter($headers, static fn (string $header): bool => str_starts_with(strtolower($header), $name . ':')));
        same([$line], $sent, "$label, $name");
    }
}

test('esc escapes the five characters that matter in HTML', function (): void {
    same('&lt;&gt;&amp;&quot;&#039;', esc('<>&"\''));
    same('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', esc('<script>alert("x")</script>'));
    same('&quot;&gt;&lt;script&gt;', esc('"><script>'), 'what a visitor might type as a reservation number');
    same('Наплатено: 21.802 ден.', esc('Наплатено: 21.802 ден.'), 'Cyrillic stays as it is');
    same('', esc(''));
});

test('esc replaces invalid UTF-8 instead of returning nothing', function (): void {
    same("a\u{FFFD}b", esc("a\xFFb"));
    same("\u{FFFD}&lt;", esc("\xC3<"), 'a cut-off character does not hide the next one');
});

test('page_shell is a complete page: language, charset, viewport, noindex, title, style, body', function (): void {
    $page = page_shell('Наплата', '<h1>Наплата</h1>');
    truthy(str_starts_with($page, "<!doctype html>\n<html lang=\"mk\">\n<head>\n"), 'doctype and language first');
    truthy(str_contains($page, '<meta charset="utf-8">'), 'charset');
    truthy(str_contains($page, '<meta name="viewport" content="width=device-width, initial-scale=1">'), 'viewport');
    truthy(str_contains($page, '<meta name="robots" content="noindex">'), 'noindex');
    truthy(str_contains($page, '<title>Наплата</title>'), 'title');
    truthy(str_contains($page, "</head>\n<body>\n<h1>Наплата</h1>\n</body>\n</html>\n"), 'the body as given, then the end of the page');
    same(1, substr_count($page, '<style>'), 'one style element');
    truthy(strpos($page, '<meta charset="utf-8">') < strpos($page, '<title>'), 'the charset comes before any text');
});

test('page_shell keeps the look of the cPay pages', function (): void {
    $page = page_shell('x', '');
    foreach (['system-ui', 'max-width:36rem', '#1d2a30', '#0a6f99'] as $part) {
        truthy(str_contains($page, $part), $part);
    }
});

test('page_shell escapes the title and the language', function (): void {
    $page = page_shell('</title><script>alert(1)</script>', '<p>x</p>', '"><script>');
    truthy(str_contains($page, '<title>&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;</title>'), 'title');
    truthy(str_contains($page, '<html lang="&quot;&gt;&lt;script&gt;">'), 'language');
    same(0, substr_count($page, '<script>'), 'no script element');
    truthy(str_contains(page_shell("a'b & c", ''), '<title>a&#039;b &amp; c</title>'), 'quote and ampersand');
});

test('page_shell takes another language and more style', function (): void {
    $page = page_shell('Payment', '<p>x</p>', 'en', 'table{width:100%}');
    truthy(str_contains($page, '<html lang="en">'), 'language');
    truthy((bool) preg_match('~<style>[^<]*#0a6f99[^<]*table\{width:100%\}</style>~', $page), 'the extra style follows the basic one');
});

test('page_shell refuses style that could end the style element', function (): void {
    foreach (['</style><script>alert(1)</script>', 'a{color:red}</STYLE>', "b{}</sTyLe\n>", '</style'] as $css) {
        try {
            page_shell('x', '', 'mk', $css);
            truthy(false, 'should have thrown');
        } catch (InvalidArgumentException $e) {
            same('page_css', $e->getMessage());
        }
    }
});

test('page_shell takes style with a "<" that cannot end the style element', function (): void {
    foreach (['@media (width <= 600px){body{margin:1rem}}', 'a::before{content:"<"}', 'a::after{content:"</b>"}'] as $css) {
        truthy(str_contains(page_shell('x', '', 'mk', $css), $css . '</style>'), $css);
    }
});

test('page_headers: no-store, noindex, no framing, nosniff, and the content type in UTF-8', function (): void {
    same([
        'Content-Type: text/html; charset=utf-8',
        'Cache-Control: no-store',
        'X-Robots-Tag: noindex',
        "Content-Security-Policy: frame-ancestors 'none'",
        'X-Content-Type-Options: nosniff',
    ], page_headers('text/html'));
    same('Content-Type: text/plain; charset=utf-8', page_headers('text/plain')[0]);
    same(array_slice(page_headers('text/html'), 1), array_slice(page_headers('text/plain'), 1), 'the same headers for text');
});

test('send_page sets the status, prints the page and does not end the request', function (): void {
    $run = pages_test_script(<<<'PHP'
send_page(503, page_shell('T', '<p>x</p>'));
fwrite(STDERR, 'status ' . http_response_code());
PHP);
    same(page_shell('T', '<p>x</p>'), $run['out']);
    same('status 503', $run['err'], 'the code after send_page still runs, without a warning');
});

test('send_text sets the status and prints the text as it is', function (): void {
    $run = pages_test_script(<<<'PHP'
send_text(200, 'OK');
fwrite(STDERR, 'status ' . http_response_code());
PHP);
    same('OK', $run['out']);
    same('status 200', $run['err']);
});

test('send_page and send_text really send the status and the five headers (seen under php-cgi)', function (): void {
    $cgi = pages_test_cgi();
    if ($cgi === null) {
        skip('there is no php-cgi beside ' . PHP_BINARY . ', so the headers that send_page and send_text send are not checked');
    }
    $page = pages_test_response($cgi, "send_page(503, page_shell('T', '<p>x</p>'));");
    same(page_shell('T', '<p>x</p>'), $page['body']);
    same('', $page['err']);
    truthy(in_array('Status: 503 Service Unavailable', $page['headers'], true), 'the status of the page: ' . implode(' | ', $page['headers']));
    pages_test_sent_headers('text/html', $page['headers'], 'page');

    $text = pages_test_response($cgi, "send_text(200, 'OK');");
    same('OK', $text['body']);
    same('', $text['err']);
    same([], array_values(preg_grep('/\AStatus:(?! 200\b)/i', $text['headers'])), 'the status of the text is 200');
    pages_test_sent_headers('text/plain', $text['headers'], 'text');
});
