<?php
/* Hotel Oaza — the enquiry email: plain text, in Macedonian whatever the guest's language. */
declare(strict_types=1);

require_once __DIR__ . '/validate.php';

/* Option labels as the Macedonian form shows them (test/php/message.test.php checks them against src/i18n/mk). */
const MAIL_LABELS = [
    'type' => [
        'stay' => 'Сместување',
        'wedding' => 'Свадба или прослава',
        'conference' => 'Конференција или семинар',
        'group' => 'Групна резервација',
    ],
    'room' => [
        'any' => 'Без предност',
        'single' => 'Еднокреветна соба',
        'double' => 'Соба со брачен кревет',
        'twin' => 'Соба со два кревета',
        'apartment' => 'Апартман',
    ],
    'eventGuests' => ['30' => 'До 30 гости', '150' => 'До 150 гости', '250' => 'До 250 гости'],
    'overnight' => ['no' => 'Не е потребно', 'yes' => 'Да, за учесниците'],
];

/** A header value: CR, LF and other control characters cannot add headers. */
function header_safe(string $value): string
{
    return clean_line($value);
}

/** "2030-05-20" → "20.05.2030"; an empty value stays empty. */
function mk_date(string $iso): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
    return $date === false ? $iso : $date->format('d.m.Y');
}

/**
 * @param array<string, mixed> $data validate_enquiry()'s data (already valid)
 * @return array{subject: string, body: string, reply_to: string, reply_name: string}
 */
function build_message(array $data): array
{
    $type = MAIL_LABELS['type'][$data['type']];
    $event = in_array($data['type'], ENQUIRY_EVENT_TYPES, true);
    $fields = [
        'Вид' => $type,
        'Име' => $data['name'],
        'Е-пошта' => $data['email'],
        'Телефон' => $data['phone'],
        'Компанија' => $data['company'] ?? '',
        'Ноќевање' => isset($data['overnight']) ? MAIL_LABELS['overnight'][$data['overnight']] : '',
        ($event ? 'Датум на настанот' : 'Пристигнување') => mk_date($data['arrival']),
        'Заминување' => mk_date($data['departure'] ?? ''),
        'Број на гости' => $event ? MAIL_LABELS['eventGuests'][$data['eventGuests']] : $data['guests'],
        'Соба' => isset($data['room']) ? MAIL_LABELS['room'][$data['room']] : '',
    ];
    $filled = array_filter($fields, static fn (string $value): bool => $value !== '');
    $campaign = array_map(static fn (string $key, string $value): string => "$key=$value", array_keys($data['utm']), $data['utm']);
    $lines = array_merge(
        ['Ново барање од веб-страницата на Хотел Оаза.', ''],
        array_map(static fn (string $label, string $value): string => "$label: $value", array_keys($filled), $filled),
        $data['message'] === '' ? [] : ['', 'Порака:', $data['message']],
        ['', 'Јазик на гостинот: ' . strtoupper($data['lang']), 'Страница: ' . ($data['page'] === '' ? '—' : $data['page'])],
        $campaign === [] ? [] : ['Кампања: ' . implode(' · ', $campaign)],
        ['', 'Со „Одговори“ одговорот оди директно до гостинот.'],
    );
    return [
        'subject' => header_safe('Барање од веб-страницата: ' . $type . ' — ' . $data['name']),
        'body' => implode("\n", $lines) . "\n",
        'reply_to' => $data['email'],
        'reply_name' => header_safe($data['name']),
    ];
}
