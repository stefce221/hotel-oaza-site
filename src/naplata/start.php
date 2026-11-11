<?php
/* Hotel Oaza — POST /naplata/start.php: checks the staff form and sends the browser on to cPay. Everything it does lives in cpay/lib. */
declare(strict_types=1);

require dirname(__DIR__) . '/cpay/lib/staff-app.php';

staff_start();
