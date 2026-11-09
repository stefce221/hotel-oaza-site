<?php
/* Hotel Oaza — at most N enquiries per visitor per window. A JSON file in the private folder,
   locked while it is read and rewritten: {"<visitor hash>": [unix times, …]}. */
declare(strict_types=1);

/**
 * Each visitor's attempts after $since; anything malformed is dropped.
 * @return array<string, int[]>
 */
function recent_hits(mixed $stored, int $since): array
{
    $recent = array_map(
        static fn (mixed $times): array => array_values(array_filter(
            is_array($times) ? $times : [],
            static fn (mixed $time): bool => is_int($time) && $time > $since,
        )),
        is_array($stored) ? $stored : [],
    );
    return array_filter($recent, static fn (array $times): bool => $times !== []);
}

/** True (limited, not recorded) when $key already has $limit attempts in the window; otherwise records this one. */
function rate_limited(string $file, string $key, int $limit, int $window, int $now): bool
{
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException('rate_file');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('rate_lock');
        }
        $hits = recent_hits(json_decode((string) stream_get_contents($handle), true), $now - $window);
        $limited = count($hits[$key] ?? []) >= $limit;
        $next = $limited ? $hits : [$key => [...($hits[$key] ?? []), $now]] + $hits;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($next, JSON_THROW_ON_ERROR));
        return $limited;
    } finally {
        fclose($handle);
    }
}
