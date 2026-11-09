<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api/lib/validate.php';

/* The cases test/enquiry-cases.test.mjs runs against the browser rules. */
$fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/enquiry-cases.json'), true, 512, JSON_THROW_ON_ERROR);

function expand_case_value(mixed $value): string
{
    return is_string($value) ? $value : str_repeat($value['repeat'], $value['times']) . ($value['then'] ?? '');
}

foreach ($fixture['cases'] as $case) {
    test('validate: ' . $case['name'], function () use ($fixture, $case): void {
        $input = array_merge($fixture['base'], array_map('expand_case_value', $case['change']));
        $errors = validate_enquiry($input, $fixture['today'])['errors'];
        $expected = $case['errors'];
        ksort($errors);
        ksort($expected);
        same($expected, $errors);
    });
}

test('clean_line turns control characters into one space', function (): void {
    same('Test Guest', clean_line("  Test\r\n\0Guest\t"));
});

test('clean_text keeps line breaks and drops other control characters', function (): void {
    same("Line 1\nLine 2\n\nLine 4", clean_text("  Line 1\r\nLine 2\r\rLine 4\x07  "));
});

test('char_count counts characters; invalid UTF-8 counts as too long', function (): void {
    same(3, char_count('жжж'));
    truthy(char_count("\xff") > 2000, 'invalid UTF-8 is too long');
});

test('utm_value is one clean line of at most 100 characters', function (): void {
    same('spring sale', utm_value(" spring\nsale "));
    same(100, char_count(utm_value(str_repeat('ш', 150))));
});

test('validate_enquiry returns cleaned data with page, language and campaign', function (): void {
    $result = validate_enquiry([
        'type' => 'conference', 'name' => "Test\r\nGuest", 'email' => ' guest@example.com ', 'phone' => '',
        'message' => "Hello\r\nthere", 'arrival' => '', 'eventGuests' => '150', 'company' => ' Test Company ',
        'overnight' => 'no', 'lang' => 'en', 'page' => 'conferences',
        'utm_source' => 'facebook', 'utm_medium' => '', 'utm_campaign' => 'spring',
    ], '2026-11-06');
    same([], $result['errors']);
    $data = $result['data'];
    same('Test Guest', $data['name']);
    same('guest@example.com', $data['email']);
    same("Hello\nthere", $data['message']);
    same('Test Company', $data['company']);
    same('en', $data['lang']);
    same('conferences', $data['page']);
    same(['utm_source' => 'facebook', 'utm_campaign' => 'spring'], $data['utm']);
    truthy(!array_key_exists('departure', $data), 'no departure for an event');
});

test('validate_enquiry keeps only what a stay needs and defaults odd hidden values', function (): void {
    $data = validate_enquiry([
        'type' => 'stay', 'name' => 'Test Guest', 'email' => 'guest@example.com', 'guests' => '2', 'room' => 'any',
        'company' => 'Ignored', 'eventGuests' => '250', 'lang' => 'de', 'page' => '../etc',
    ], '2026-11-06')['data'];
    truthy(!array_key_exists('company', $data) && !array_key_exists('eventGuests', $data), 'no event fields');
    same('mk', $data['lang']);
    same('', $data['page']);
});

test('validate_enquiry treats arrays as empty values', function (): void {
    $errors = validate_enquiry(['type' => ['stay'], 'name' => ['x'], 'email' => 'guest@example.com', 'guests' => '2', 'room' => 'any'], '2026-11-06')['errors'];
    same('choice', $errors['type']);
    same('required', $errors['name']);
});

test('today_in_skopje is an ISO date', function (): void {
    truthy(is_iso_date(today_in_skopje()), today_in_skopje());
});
