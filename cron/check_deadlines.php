<?php
// Контроль сроков заявок:  php cron/check_deadlines.php  (каждые 5 минут)
declare(strict_types=1);

$argv = [__FILE__, 'check_deadlines'];
require __DIR__ . '/run.php';
