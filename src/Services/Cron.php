<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;

/** Фоновые задания: контроль сроков, очередь интеграций, очистка, резервная копия. */
final class Cron
{
    public const JOBS = [
        'check_deadlines' => 'Контроль сроков заявок (предупреждения и просрочки)',
        'process_queue' => 'Повтор отправки очереди интеграций',
        'cleanup' => 'Очистка: фото с истёкшим сроком хранения',
        'backup' => 'Резервная копия БД',
    ];

    public static function run(string $job): array
    {
        $res = match ($job) {
            'check_deadlines' => self::checkDeadlines(),
            'process_queue' => IntegrationService::processPending(),
            'cleanup' => self::cleanup(),
            'backup' => self::backup(),
            default => throw new \InvalidArgumentException("Неизвестное задание: $job"),
        };
        Log::act('cron.' . $job, 'cron', null, json_encode($res, JSON_UNESCAPED_UNICODE), null);
        return $res;
    }

    /** Предупреждения (warn_at достигнут) и отметка просрочки. */
    public static function checkDeadlines(): array
    {
        $now = now();
        $warned = 0;
        $overdue = 0;
        $rows = Db::all("SELECT r.id, r.request_number, r.assignee_id, r.due_at, s.code AS status_code
                         FROM requests r JOIN request_statuses s ON s.id = r.status_id
                         WHERE r.warning_sent = 0 AND r.warn_at <= ? AND s.is_final = 0 AND s.code <> 'done'", [$now]);
        foreach ($rows as $r) {
            if (strtotime($r['due_at']) <= time()) {
                // срок уже вышел: достаточно уведомления о просрочке
                Db::exec('UPDATE requests SET warning_sent = 1 WHERE id = ?', [$r['id']]);
                continue;
            }
            $msg = "Заявка {$r['request_number']}: до срока устранения осталось менее 25 % времени (срок " . date('d.m.Y H:i', strtotime($r['due_at'])) . ')';
            if ($r['assignee_id'] !== null) {
                NotificationService::employee((int)$r['assignee_id'], 'deadline_warning', $msg, (int)$r['id']);
            }
            NotificationService::dispatchers('deadline_warning', $msg, (int)$r['id']);
            Db::exec('UPDATE requests SET warning_sent = 1 WHERE id = ?', [$r['id']]);
            $warned++;
        }
        $rows = Db::all("SELECT r.id, r.request_number, r.assignee_id, r.due_at
                         FROM requests r JOIN request_statuses s ON s.id = r.status_id
                         WHERE r.is_overdue = 0 AND r.due_at < ? AND s.code IN ('new','accepted','assigned','in_progress')", [$now]);
        foreach ($rows as $r) {
            Db::exec('UPDATE requests SET is_overdue = 1, warning_sent = 1, updated_at = ? WHERE id = ?', [$now, $r['id']]);
            $msg = "Заявка {$r['request_number']} просрочена (срок был " . date('d.m.Y H:i', strtotime($r['due_at'])) . ')';
            NotificationService::managers('overdue', $msg, (int)$r['id'], true);
            if ($r['assignee_id'] !== null) {
                NotificationService::employee((int)$r['assignee_id'], 'overdue', $msg, (int)$r['id']);
            }
            $overdue++;
        }
        return ['warned' => $warned, 'overdue' => $overdue];
    }

    /** Фото закрытых заявок, у которых истёк срок хранения (retention_until), удаляются вместе с файлом. */
    public static function cleanup(): array
    {
        $removed = 0;
        $rows = Db::all("SELECT p.id, p.file_path FROM request_photos p
                         JOIN requests r ON r.id = p.request_id JOIN request_statuses s ON s.id = r.status_id
                         WHERE p.retention_until < CURDATE() AND s.code = 'closed'");
        foreach ($rows as $p) {
            Db::exec('DELETE FROM request_photos WHERE id = ?', [$p['id']]);
            @unlink(STORAGE . '/photos/' . $p['file_path']);
            $removed++;
        }
        return ['photos_removed' => $removed];
    }

    /** Резервная копия: делегируется BackupService (раздел администрирования), если он установлен. */
    public static function backup(): array
    {
        if (class_exists('App\\Services\\BackupService') && method_exists('App\\Services\\BackupService', 'create')) {
            return ['file' => \App\Services\BackupService::create()];
        }
        return ['skipped' => 'BackupService не установлен'];
    }

    /** «Ленивый» планировщик: запускает задания не чаще раза в 5 минут из обычного запроса (если не настроен cron). */
    public static function tick(): void
    {
        if (!cfg('app.lazy_cron', true)) {
            return;
        }
        $fh = @fopen(STORAGE . '/logs/cron.tick', 'c+');
        if (!$fh) {
            return;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            $last = (int)stream_get_contents($fh);
            if (time() - $last >= 300) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string)time());
                try {
                    self::runAll();
                } catch (\Throwable $e) {
                    Log::file('lazy cron: ' . $e->getMessage());
                }
            }
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    /** Все регулярные задания; резервная копия запускается планировщиком отдельно (cron/run.php backup). */
    public static function runAll(): array
    {
        $out = [];
        foreach (['check_deadlines', 'process_queue', 'cleanup'] as $j) {
            $out[$j] = self::run($j);
        }
        return $out;
    }
}
