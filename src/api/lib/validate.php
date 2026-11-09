<?php
/* Hotel Oaza — enquiry rules on the server. They match src/assets/js/enquiry-rules.js;
   test/fixtures/enquiry-cases.json runs against both. PHP 8.0+, default extensions only. */
declare(strict_types=1);

const ENQUIRY_TYPES = ['stay', 'wedding', 'conference', 'group'];
const ENQUIRY_EVENT_TYPES = ['wedding', 'conference'];
const ENQUIRY_GUESTS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10+'];
const ENQUIRY_EVENT_GUESTS = ['30', '150', '250'];
const ENQUIRY_ROOMS = ['any', 'single', 'double', 'twin', 'apartment'];
const ENQUIRY_OVERNIGHT = ['no', 'yes'];
const ENQUIRY_LIMITS = ['name' => 100, 'email' => 200, 'phone' => 40, 'company' => 150, 'message' => 2000];
const ENQUIRY_UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign'];

/** Characters, not bytes, like the browser's count; invalid UTF-8 counts as too long. */
function char_count(string $value): int
{
    $count = preg_match_all('/./us', $value);
    return $count === false ? PHP_INT_MAX : $count;
}

/** trim() for Unicode: also strips no-break and other Unicode spaces and a byte-order mark, like the browser's trim. */
function utrim(string $value): string
{
    $out = preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $value);
    return $out === null ? trim($value) : $out;
}

/** One line of text: each run of control characters (CR, LF, tab, NUL, …) becomes one space; trimmed.
    Byte-wise is safe: UTF-8 multi-byte sequences never contain bytes below 0x80. */
function clean_line(string $value): string
{
    return utrim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
}

/** Multi-line text: line breaks kept as \n, tabs kept, other control characters dropped; trimmed. */
function clean_text(string $value): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $value);
    return utrim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text));
}

function is_iso_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value)) {
        return false;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year);
}

/** A campaign value: one clean line, cut to 100 characters. */
function utm_value(string $value): string
{
    return preg_match('/^.{0,100}/us', clean_line($value), $match) ? $match[0] : '';
}

/** Today in Skopje, as YYYY-MM-DD. */
function today_in_skopje(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Skopje')))->format('Y-m-d');
}

/**
 * The cleaned fields an enquiry of its type carries.
 * @param array<string, mixed> $input the POST fields
 * @return array<string, mixed>
 */
function enquiry_data(array $input): array
{
    $text = static fn (string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
    $type = $text('type');
    $utm = array_combine(ENQUIRY_UTM_KEYS, array_map(static fn (string $key): string => utm_value($text($key)), ENQUIRY_UTM_KEYS));
    $data = [
        'type' => $type,
        'name' => clean_line($text('name')),
        'email' => clean_line($text('email')),
        'phone' => clean_line($text('phone')),
        'message' => clean_text($text('message')),
        'arrival' => $text('arrival'),
        'lang' => $text('lang') === 'en' ? 'en' : 'mk',
        'page' => preg_match('/^[a-z0-9-]{1,30}\z/', $text('page')) ? $text('page') : '',
        'utm' => array_filter($utm, static fn (string $value): bool => $value !== ''),
    ];
    $conference = $type === 'conference' ? ['company' => clean_line($text('company')), 'overnight' => $text('overnight')] : [];
    $byType = in_array($type, ENQUIRY_EVENT_TYPES, true)
        ? ['eventGuests' => $text('eventGuests')]
        : ['departure' => $text('departure'), 'guests' => $text('guests'), 'room' => $text('room')];
    return $data + $conference + $byType;
}

function length_error(string $value, string $field, bool $required): ?string
{
    if ($value === '') {
        return $required ? 'required' : null;
    }
    return char_count($value) > ENQUIRY_LIMITS[$field] ? 'long' : null;
}

function email_error(string $email): ?string
{
    return length_error($email, 'email', true)
        ?? (filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'email' : null);
}

/** @param string[] $allowed */
function choice_error(string $value, array $allowed): ?string
{
    return in_array($value, $allowed, true) ? null : 'choice';
}

/** An optional date: when given, a real date from today on. */
function date_error(string $value, string $today): ?string
{
    return $value !== '' && (!is_iso_date($value) || $value < $today) ? 'date' : null;
}

function departure_error(string $departure, string $arrival, string $today): ?string
{
    if ($departure === '') {
        return null;
    }
    if (date_error($departure, $today) !== null) {
        return 'date';
    }
    $arrivalOk = $arrival !== '' && date_error($arrival, $today) === null;
    return $arrivalOk && $departure <= $arrival ? 'dates' : null;
}

/**
 * @param array<string, mixed> $input the POST fields
 * @param string $today YYYY-MM-DD
 * @return array{data: array<string, mixed>, errors: array<string, string>}
 *         errors as field => required | email | long | date | dates | choice
 */
function validate_enquiry(array $input, string $today): array
{
    $data = enquiry_data($input);
    $errors = [
        'type' => choice_error($data['type'], ENQUIRY_TYPES),
        'name' => length_error($data['name'], 'name', true),
        'email' => email_error($data['email']),
        'phone' => length_error($data['phone'], 'phone', false),
        'message' => length_error($data['message'], 'message', false),
        'arrival' => date_error($data['arrival'], $today),
    ];
    if ($data['type'] === 'conference') {
        $errors += [
            'company' => length_error($data['company'], 'company', false),
            'overnight' => choice_error($data['overnight'], ENQUIRY_OVERNIGHT),
        ];
    }
    $errors += in_array($data['type'], ENQUIRY_EVENT_TYPES, true)
        ? ['eventGuests' => choice_error($data['eventGuests'], ENQUIRY_EVENT_GUESTS)]
        : [
            'departure' => departure_error($data['departure'], $data['arrival'], $today),
            'guests' => choice_error($data['guests'], ENQUIRY_GUESTS),
            'room' => choice_error($data['room'], ENQUIRY_ROOMS),
        ];
    return ['data' => $data, 'errors' => array_filter($errors, static fn (?string $code): bool => $code !== null)];
}
