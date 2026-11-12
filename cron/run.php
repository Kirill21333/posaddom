<?php
// Запуск фоновых заданий:  php cron/run.php [задание|all]
//   check_deadlines - предупреждения по сроку (осталось < 25 %) и просрочки   (каждые 5 минут)
//   process_queue   - повтор отправки очереди интеграций                      (каждые 5 минут)
//   cleanup         - удаление фото закрытых заявок с истёкшим сроком хранения (раз в сутки)
//   backup          - резервная копия БД (BackupService)                       (раз в сутки)
//   all             - check_deadlines + process_queue + cleanup
// Пример crontab:  */5 * * * * php /var/www/posaddom/cron/run.php all
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Только для командной строки');
}
require __DIR__ . '/../src/bootstrap.php';

use App\Services\Cron;

$job = $argv[1] ?? 'all';
try {
    $res = $job === 'all' ? Cron::runAll() : Cron::run($job);
    echo '[' . date('Y-m-d H:i:s') . "] $job: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
