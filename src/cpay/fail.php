<?php
/* Registered with CaSys as the cPay failure address: a verified notice here marks the payment as not paid. */
declare(strict_types=1);

require __DIR__ . '/lib/notice.php';

cpay_notice('fail');
