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
    'cpay' => [
        /* The address cPay (CaSys) sends its payment notices from, as CaSys gave it in January 2025.
           Notices from it are kept without the hourly cap that applies to everyone else. IPv4 only;
           change it only if CaSys names a new address. */
        'push_ips' => ['80.77.147.43'],

        /* The lines below are for the staff page /naplata/, where reception charges Booking.com cards.
           The page says "Наплатата не е поставена до крај" until the three empty values are filled in. */

        /* The hotel's merchant number at cPay. Digits only, between the quotes. */
        'merchant_id' => '',
        /* The hotel's merchant name, letter for letter as CaSys registered it.
           Only Latin letters, digits, spaces and the signs @ - / ? = & are accepted. */
        'merchant_name' => '',
        /* The cPay checksum key: the secret CaSys gave the hotel.
           The owner types it in here, in the copy of this file that is on the server.
           It never goes into git and never into an e-mail.
           Keep the key between the single quotes; never use double quotes: a $ in the key would cut it short. */
        'key' => '',
        /* The two addresses cPay reports every payment to. They are registered with CaSys: do not change them. */
        'ok_url' => 'https://www.oazahotel.com.mk/cpay/ok.php',
        'fail_url' => 'https://www.oazahotel.com.mk/cpay/fail.php',
        /* The largest amount one charge may have, in whole denars. The page refuses a larger amount:
           it is most likely a typing mistake. A plain number: no quotes, no dots, no commas. */
        'max_amount' => 300000,
    ],
];
