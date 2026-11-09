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

/**
 * What identifies a visitor in the rate limit: an IPv4 address as it is, an IPv6 address by its /64 network
 * (one connection usually owns the whole /64, so rotating addresses must not reset the count), else "unknown".
 */
function visitor_address(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return $ip;
    }
    $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;
    if ($packed === false) {
        return 'unknown';
    }
    if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) { /* ::ffff:a.b.c.d is an IPv4 visitor */
        return (string) inet_ntop(substr($packed, 12));
    }
    return bin2hex(substr($packed, 0, 8)) . '/64';
}

/** The salted hash that stands for one visitor in the rate limit files; the address itself is never stored. */
function visitor_key(string $salt, array $server): string
{
    return hash('sha256', $salt . '|' . visitor_address((string) ($server['REMOTE_ADDR'] ?? '')));
}
