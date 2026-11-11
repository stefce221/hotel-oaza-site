<?php
/* Hotel Oaza — what every page of the cPay addresses and the staff page shares: the escape helper, the page
   shell and the response headers. Every dynamic value in HTML goes through esc(). */
declare(strict_types=1);

/* The look of the cPay pages since the first one. */
const PAGE_BASE_CSS = 'body{font:1rem/1.6 system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:0 1rem;color:#1d2a30}'
    . 'a{color:#0a6f99}hr{margin:2rem 0}';

/** Text made safe for HTML, between tags and inside quoted attributes; bytes that are not UTF-8 become U+FFFD. */
function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * A complete HTML page: lang, charset, viewport, robots noindex, the title, a small inline style plus $css, then $body.
 * $title and $lang are escaped here. $body and $css are the caller's own markup and style: every dynamic value in
 * them must have gone through esc() already. Throws InvalidArgumentException('page_css') for style with "</style"
 * in it (in capitals too), the only thing that can end the style element.
 */
function page_shell(string $title, string $body, string $lang = 'mk', string $css = ''): string
{
    if (stripos($css, '</style') !== false) {
        throw new InvalidArgumentException('page_css');
    }
    return implode("\n", [
        '<!doctype html>',
        '<html lang="' . esc($lang) . '">',
        '<head>',
        '<meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="robots" content="noindex">',
        '<title>' . esc($title) . '</title>',
        '<style>' . PAGE_BASE_CSS . $css . '</style>',
        '</head>',
        '<body>',
        $body,
        '</body>',
        '</html>',
        '',
    ]);
}

/**
 * The headers of every answer: the content type in UTF-8, never cached, never indexed, never shown in a frame,
 * never read as another type.
 * @return string[]
 */
function page_headers(string $contentType): array
{
    return [
        'Content-Type: ' . $contentType . '; charset=utf-8',
        'Cache-Control: no-store',
        'X-Robots-Tag: noindex',
        "Content-Security-Policy: frame-ancestors 'none'",
        'X-Content-Type-Options: nosniff',
    ];
}

/** Status, the headers of page_headers(), then the body. Does not exit. */
function send_body(int $status, string $contentType, string $body): void
{
    http_response_code($status);
    foreach (page_headers($contentType) as $line) {
        header($line);
    }
    echo $body;
}

/** Status, text/html in UTF-8 and the headers of page_headers(), then the page. Does not exit. */
function send_page(int $status, string $html): void
{
    send_body($status, 'text/html', $html);
}

/** The same with text/plain: the short answer cPay's own notices get. Does not exit. */
function send_text(int $status, string $text): void
{
    send_body($status, 'text/plain', $text);
}
