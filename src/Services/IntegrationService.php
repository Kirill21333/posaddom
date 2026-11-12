<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Log;
use App\Core\Mailer;
use App\Core\Settings;

/**
 * Очередь обмена с внешними системами (ГИС ЖКХ, РСО, SMS-шлюз, почта, мессенджер).
 * Сообщение сначала кладётся в integration_queue, затем отправляется; при недоступности системы основная
 * работа не блокируется, а отправка повторяется по расписанию (cron/process_queue.php).
 */
final class IntegrationService
{
    public const SYSTEMS = ['gis_gkh', 'rso', 'sms', 'email', 'messenger'];

    /** Ставит сообщение в очередь и сразу пробует его отправить. Возвращает id записи очереди. */
    public static function enqueue(string $system, ?int $requestId, array $payload, string $direction = 'out'): int
    {
        $id = Db::insert('integration_queue', [
            'system' => $system, 'request_id' => $requestId, 'direction' => $direction,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'attempts' => 0,
            'next_try_at' => now(), 'created_at' => now(),
        ]);
        if ($direction === 'out') {
            try {
                self::deliver($id);
            } catch (\Throwable $e) {
                Log::file('integration deliver #' . $id . ': ' . $e->getMessage());
            }
        }
        return $id;
    }

    /** Одна попытка отправки. Возвращает итоговый статус записи (pending|sent|error). */
    public static function deliver(int $id): string
    {
        $row = Db::one('SELECT * FROM integration_queue WHERE id = ?', [$id]);
        if (!$row || $row['status'] !== 'pending') {
            return $row['status'] ?? 'error';
        }
        $attempts = (int)$row['attempts'] + 1;
        $payload = json_decode((string)$row['payload'], true) ?: [];
        $error = null;
        if (Settings::get('integrations_enabled', '1') !== '1') {
            $error = 'Внешний сервис недоступен';
        } else {
            try {
                self::send($row['system'], $payload);
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 250);
            }
        }
        $notificationId = (int)($payload['notification_id'] ?? 0);
        if ($error === null) {
            Db::update('integration_queue', ['status' => 'sent', 'attempts' => $attempts, 'last_error' => null,
                'processed_at' => now(), 'next_try_at' => null], 'id = ?', [$id]);
            if ($notificationId) {
                Db::exec("UPDATE notifications SET status = 'sent', sent_at = ? WHERE id = ? AND status = 'new'", [now(), $notificationId]);
            }
            return 'sent';
        }
        $max = (int)cfg('gis.max_attempts', 5);
        if ($attempts >= $max) {
            Db::update('integration_queue', ['status' => 'error', 'attempts' => $attempts, 'last_error' => $error,
                'processed_at' => now(), 'next_try_at' => null], 'id = ?', [$id]);
            if ($notificationId) {
                Db::exec("UPDATE notifications SET status = 'failed' WHERE id = ? AND status = 'new'", [$notificationId]);
            }
            return 'error';
        }
        $next = date('Y-m-d H:i:s', time() + $attempts * (int)cfg('gis.retry_minutes', 5) * 60);
        Db::update('integration_queue', ['attempts' => $attempts, 'last_error' => $error, 'next_try_at' => $next], 'id = ?', [$id]);
        return 'pending';
    }

    /** Повторная отправка всех сообщений, у которых наступил срок следующей попытки. */
    public static function processPending(int $limit = 200): array
    {
        $ids = array_column(Db::all(
            "SELECT id FROM integration_queue WHERE status = 'pending' AND direction = 'out' AND (next_try_at IS NULL OR next_try_at <= ?) ORDER BY id LIMIT $limit",
            [now()]), 'id');
        $res = ['processed' => 0, 'sent' => 0, 'pending' => 0, 'error' => 0];
        foreach ($ids as $id) {
            $st = self::deliver((int)$id);
            $res['processed']++;
            $res[$st] = ($res[$st] ?? 0) + 1;
        }
        return $res;
    }

    /** Ручной сброс времени повтора (для администратора): ближайшая попытка - сейчас. */
    public static function retryNow(int $id): void
    {
        Db::exec("UPDATE integration_queue SET status = 'pending', next_try_at = ?, attempts = LEAST(attempts, 3) WHERE id = ? AND status <> 'sent'", [now(), $id]);
    }

    private static function send(string $system, array $p): void
    {
        switch ($system) {
            case 'sms':
                if (!Mailer::sms((string)$p['to'], (string)$p['text'])) {
                    throw new \RuntimeException('SMS-шлюз не принял сообщение');
                }
                return;
            case 'email':
                if (!Mailer::send((string)$p['to'], (string)($p['subject'] ?? cfg('app.name')), (string)$p['text'])) {
                    throw new \RuntimeException('Почтовый сервер не принял сообщение');
                }
                return;
            case 'messenger':
                self::toFile('mails', 'messenger_' . date('Ymd_His') . '_' . substr(md5(json_encode($p) . microtime()), 0, 6) . '.txt',
                    "To: {$p['to']}\n\n{$p['text']}\n");
                return;
            case 'gis_gkh':
            case 'rso':
                self::sendExternal($system, $p);
                return;
        }
        throw new \RuntimeException('Неизвестная система: ' . $system);
    }

    /** ГИС ЖКХ / РСО: без заданного URL - заглушка (запись в журнал интеграций), иначе HTTP POST. */
    private static function sendExternal(string $system, array $p): void
    {
        $url = (string)cfg('gis.' . $system . '_url', '');
        if ($url === '') {
            @file_put_contents(STORAGE . '/logs/integration_' . $system . '.log',
                '[' . now() . '] ' . json_encode($p, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            return;
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . cfg('gis.token', ''),
            'content' => json_encode($p, JSON_UNESCAPED_UNICODE)]]);
        $r = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
        if ($r === false || $code >= 400 || $code === 0) {
            throw new \RuntimeException("Система $system вернула ошибку ($code)");
        }
    }

    private static function toFile(string $dir, string $name, string $content): void
    {
        @file_put_contents(STORAGE . '/' . $dir . '/' . $name, $content);
    }
}
