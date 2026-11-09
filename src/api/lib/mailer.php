<?php
/* Hotel Oaza — sends the enquiry email through PHPMailer: SMTP on the server, a JSON-lines file in tests. */
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/phpmailer/Exception.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/PHPMailer.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/SMTP.php';

/**
 * @param array<string, mixed> $mail config['mail']: transport (smtp|file), from, from_name, to;
 *                                   smtp: host, port, secure, username, password; file: file
 * @param array{subject: string, body: string, reply_to: string, reply_name: string} $message
 */
function build_mailer(array $mail, array $message): PHPMailer
{
    $mailer = new PHPMailer(true);
    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    $mailer->Encoding = PHPMailer::ENCODING_BASE64;
    $mailer->isHTML(false);
    $mailer->setFrom($mail['from'], $mail['from_name']);
    $mailer->addAddress($mail['to']);
    $mailer->addReplyTo($message['reply_to'], $message['reply_name']);
    $mailer->Subject = $message['subject'];
    $mailer->Body = $message['body'];
    if ($mail['transport'] === 'smtp') {
        $mailer->isSMTP();
        $mailer->Host = $mail['host'];
        $mailer->Port = (int) $mail['port'];
        $mailer->SMTPSecure = $mail['secure'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $mail['username'];
        $mailer->Password = $mail['password'];
        $mailer->Timeout = 20;
    }
    return $mailer;
}

/** Sends the email; throws (PHPMailer\PHPMailer\Exception, RuntimeException, JsonException) when it cannot. */
function send_enquiry(array $mail, array $message): void
{
    $mailer = build_mailer($mail, $message);
    if ($mail['transport'] !== 'file') {
        $mailer->send();
        return;
    }
    $mailer->preSend();
    $record = [
        'to' => $mail['to'],
        'reply_to' => $message['reply_to'],
        'subject' => $message['subject'],
        'body' => $message['body'],
    ];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($mail['file'], $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('mail_file');
    }
}
