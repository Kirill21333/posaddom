<?php
// Повтор отправки очереди интеграций (ГИС ЖКХ, РСО, SMS, e-mail):  php cron/process_queue.php  (каждые 5 минут)
declare(strict_types=1);

$argv = [__FILE__, 'process_queue'];
require __DIR__ . '/run.php';
