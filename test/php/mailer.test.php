<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api/lib/mailer.php';

const TEST_MAIL_CONFIG = [
    'transport' => 'file',
    'from' => 'web@oazahotel.com.mk',
    'from_name' => 'Hotel Oaza website',
    'to' => 'info@oazahotel.com.mk',
];
const TEST_MAIL_MESSAGE = [
    'subject' => 'Барање од веб-страницата: Сместување — Test Guest',
    'body' => "Line 1\nLine 2\n",
    'reply_to' => 'guest@example.com',
    'reply_name' => 'Test Guest',
];

test('mailer: PHPMailer is a vendored 6.x release', function (): void {
    $version = trim((string) file_get_contents(__DIR__ . '/../../src/api/vendor/phpmailer/VERSION'));
    truthy(str_starts_with($version, '6.'), "VERSION is 6.x ($version)");
    same($version, PHPMailer\PHPMailer\PHPMailer::VERSION);
});

test('mailer: plain UTF-8 text to reception, with the guest as Reply-To', function (): void {
    $mailer = build_mailer(TEST_MAIL_CONFIG, TEST_MAIL_MESSAGE);
    $mailer->preSend();
    $mime = $mailer->getSentMIMEMessage();
    same('info@oazahotel.com.mk', $mailer->getToAddresses()[0][0]);
    truthy(str_contains($mime, 'Reply-To: Test Guest <guest@example.com>'), 'Reply-To header');
    truthy(str_contains($mime, 'From: Hotel Oaza website <web@oazahotel.com.mk>'), 'From header');
    truthy(str_contains($mime, 'Content-Type: text/plain; charset=utf-8'), 'plain text in UTF-8');
});

test('mailer: the file transport appends one JSON line per message', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'oaza-mail-');
    send_enquiry(TEST_MAIL_CONFIG + ['file' => $file], TEST_MAIL_MESSAGE);
    send_enquiry(TEST_MAIL_CONFIG + ['file' => $file], TEST_MAIL_MESSAGE);
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    unlink($file);
    same(2, count($lines));
    same([
        'to' => 'info@oazahotel.com.mk',
        'reply_to' => 'guest@example.com',
        'subject' => TEST_MAIL_MESSAGE['subject'],
        'body' => TEST_MAIL_MESSAGE['body'],
    ], json_decode($lines[0], true));
});

test('mailer: SMTP waits end after 10 s, well before the browser gives up', function (): void {
    $smtp = ['transport' => 'smtp', 'host' => 'mail.example.mk', 'port' => 465, 'secure' => 'ssl', 'username' => 'u', 'password' => 'p'] + TEST_MAIL_CONFIG;
    $mailer = build_mailer($smtp, TEST_MAIL_MESSAGE); /* builds only; nothing connects until send() */
    same(10, $mailer->Timeout, 'connect and socket timeout');
    same(10, $mailer->getSMTPInstance()->Timelimit, 'SMTP reply time limit');
});
