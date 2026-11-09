<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api/lib/message.php';

/** validate_enquiry()'s data for a valid test enquiry, with some fields changed. */
function sample_enquiry(array $change): array
{
    $input = array_merge([
        'type' => 'stay', 'name' => 'Test Guest', 'email' => 'guest@example.com', 'phone' => '', 'message' => '',
        'arrival' => '2030-05-20', 'departure' => '2030-05-23', 'guests' => '2', 'room' => 'double',
        'eventGuests' => '150', 'company' => '', 'overnight' => 'no', 'lang' => 'en', 'page' => 'contact',
    ], $change);
    $result = validate_enquiry($input, '2026-11-06');
    same([], $result['errors'], 'the sample enquiry is valid');
    return $result['data'];
}

/** @param string[] $lines */
function body_has_lines(string $body, array $lines): void
{
    foreach ($lines as $line) {
        truthy(str_contains($body, $line . "\n"), "the body has \"$line\"");
    }
}

test('message: the subject names the type and the guest', function (): void {
    same('Барање од веб-страницата: Свадба или прослава — Test Guest', build_message(sample_enquiry(['type' => 'wedding']))['subject']);
});

test('message: a stay lists dates, guests and room in Macedonian', function (): void {
    $body = build_message(sample_enquiry([]))['body'];
    body_has_lines($body, [
        'Вид: Сместување', 'Пристигнување: 20.05.2030', 'Заминување: 23.05.2030', 'Број на гости: 2',
        'Соба: Соба со брачен кревет', 'Јазик на гостинот: EN', 'Страница: contact',
    ]);
    foreach (['Компанија', 'Телефон', 'Кампања', 'Порака'] as $absent) {
        truthy(!str_contains($body, $absent), "no \"$absent\" line when empty");
    }
});

test('message: a conference lists company, overnight and event guests', function (): void {
    $body = build_message(sample_enquiry(['type' => 'conference', 'company' => 'Test Company', 'overnight' => 'yes', 'phone' => '070 000 000']))['body'];
    body_has_lines($body, [
        'Вид: Конференција или семинар', 'Телефон: 070 000 000', 'Компанија: Test Company',
        'Ноќевање: Да, за учесниците', 'Датум на настанот: 20.05.2030', 'Број на гости: До 150 гости',
    ]);
    truthy(!str_contains($body, 'Заминување'), 'no departure line for an event');
});

test('message: the guest\'s message and the campaign are included', function (): void {
    $body = build_message(sample_enquiry(['message' => "Two rooms\nfor three nights", 'utm_source' => 'facebook', 'utm_campaign' => 'spring']))['body'];
    truthy(str_contains($body, "Порака:\nTwo rooms\nfor three nights\n"), 'message block');
    truthy(str_contains($body, "Кампања: utm_source=facebook · utm_campaign=spring\n"), 'campaign line');
});

test('message: the guest is the Reply-To, and header values never carry line breaks', function (): void {
    $message = build_message(sample_enquiry(['name' => "Test\r\nBcc: other@example.com"]));
    same('guest@example.com', $message['reply_to']);
    same('Test Bcc: other@example.com', $message['reply_name']);
    truthy(!preg_match('/[\r\n]/', $message['subject'] . $message['reply_name']), 'no CR or LF in headers');
    same("x y", header_safe("x\r\ny"));
});

test('message: option labels match the Macedonian dictionary', function (): void {
    $mk = [];
    foreach (glob(__DIR__ . '/../../src/i18n/mk/*.json') as $file) {
        $mk += json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
    $keys = ['type' => 'form.type.%s', 'eventGuests' => 'form.eg.%s', 'overnight' => 'form.overnight.%s'];
    foreach ($keys as $group => $pattern) {
        foreach (MAIL_LABELS[$group] as $value => $label) {
            same($mk[sprintf($pattern, $value)] ?? null, $label, "$group.$value");
        }
    }
    foreach (MAIL_LABELS['room'] as $value => $label) {
        same($mk[$value === 'any' ? 'form.room.any' : "room.$value.name"] ?? null, $label, "room.$value");
    }
});
