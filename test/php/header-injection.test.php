<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api/lib/message.php';
require_once __DIR__ . '/../../src/api/lib/mailer.php';

/* A guest name goes into the subject and Reply-To. Line breaks, and the Unicode ones some mail software
   treats as line breaks, must never start a new header. Builds the message only; nothing is sent. */
const INJECTED_NAMES = [
    "CR LF" => "Ivan\r\nBcc: other@example.com",
    "LF" => "Ivan\nBcc: other@example.com",
    "CR" => "Ivan\rBcc: other@example.com",
    "U+2028" => "Ivan\u{2028}Bcc: other@example.com",
    "U+2029" => "Ivan\u{2029}Bcc: other@example.com",
    "U+0085" => "Ivan\u{0085}Bcc: other@example.com",
];

/** build_message()'s output for a valid enquiry from a guest with this name. */
function message_from(string $name): array
{
    $result = validate_enquiry([
        'type' => 'stay', 'name' => $name, 'email' => 'guest@example.com', 'phone' => '', 'message' => '',
        'arrival' => '2030-05-20', 'departure' => '2030-05-23', 'guests' => '2', 'room' => 'double',
        'lang' => 'en', 'page' => 'contact',
    ], '2026-11-06');
    same([], $result['errors'], 'the enquiry is valid');
    return build_message($result['data']);
}

/** @return string[] the header lines of a MIME message, folded lines joined, split on every kind of line break */
function mime_header_lines(string $mime): array
{
    $headers = explode("\r\n\r\n", str_replace(["\r\n", "\n"], "\r\n", $mime), 2)[0];
    return preg_split('/\r\n|\r|\n|\x{2028}|\x{2029}|\x{0085}/u', (string) preg_replace('/\r\n[ \t]+/', ' ', $headers));
}

foreach (INJECTED_NAMES as $label => $name) {
    test("header injection: a name with $label cannot add a header", function () use ($name): void {
        $message = message_from($name);
        $mail = ['transport' => 'file', 'from' => 'web@oazahotel.com.mk', 'from_name' => 'Hotel Oaza website', 'to' => 'info@oazahotel.com.mk'];
        $mailer = build_mailer($mail, $message);
        $mailer->preSend();
        $lines = mime_header_lines($mailer->getSentMIMEMessage());
        foreach ($lines as $line) {
            truthy(stripos($line, 'Bcc:') !== 0, 'no header line starts with Bcc: (' . substr($line, 0, 30) . ')');
        }
        $withGuest = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'guest@example.com')));
        same(1, count($withGuest), 'the guest address is in one header line');
        truthy(str_starts_with($withGuest[0], 'Reply-To:'), 'and that line is Reply-To');
        same([], $mailer->getBccAddresses(), 'no Bcc recipient');
    });
}
