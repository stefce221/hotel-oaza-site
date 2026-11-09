<?php
/* Hotel Oaza — server config. Copy to /home/oazahote/private/config.php on MKhost (never into
   public_html, never into git) and fill in the empty values. See docs/owner-checklist.md. */
return [
    /* Rate-limit file, error log and the cPay inbox live here. */
    'private_dir' => '/home/oazahote/private',
    'mail' => [
        'transport' => 'smtp',
        'host' => 'mail.oazahotel.com.mk',
        'port' => 465,
        /* 'ssl' (port 465) or 'tls' (port 587). */
        'secure' => 'ssl',
        'username' => '',
        'password' => '',
        /* The mailbox that sends the enquiries, e.g. a dedicated web@ address on oazahotel.com.mk. */
        'from' => '',
        'from_name' => 'Хотел Оаза — веб-страница',
        'to' => 'info@oazahotel.com.mk',
    ],
    'rate' => [
        /* Enquiries per visitor per window: a whole number from 1; the window is in seconds, from 60. */
        'limit' => 5,
        'window' => 3600,
        /* A long random string; create one with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;" */
        'salt' => '',
    ],
];
