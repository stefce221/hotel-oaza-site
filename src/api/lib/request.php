<?php
/* Hotel Oaza — checks on the incoming request. */
declare(strict_types=1);

/** True when Origin (or, without it, Referer) names this host, port included. */
function same_origin(array $server): bool
{
    $source = (string) ($server['HTTP_ORIGIN'] ?? $server['HTTP_REFERER'] ?? '');
    $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
    $parts = parse_url($source);
    if ($host === '' || !is_array($parts) || !isset($parts['host'])) {
        return false;
    }
    $origin = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    return $origin === $host;
}

/** True for a form-encoded body, which is what the enquiry form sends. */
function is_form_post(array $server): bool
{
    return str_starts_with(strtolower((string) ($server['CONTENT_TYPE'] ?? '')), 'application/x-www-form-urlencoded');
}

/** The salted hash that stands for one visitor in the rate limit files; the address itself is never stored. */
function visitor_key(string $salt, array $server): string
{
    return hash('sha256', $salt . '|' . (string) ($server['REMOTE_ADDR'] ?? ''));
}
