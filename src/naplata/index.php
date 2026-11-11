<?php
/* Hotel Oaza — GET /naplata/: the staff page that starts a cPay charge. Everything it does lives in cpay/lib, which is closed to the web. */
declare(strict_types=1);

require dirname(__DIR__) . '/cpay/lib/staff-app.php';

staff_index();
