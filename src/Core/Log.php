<?php
declare(strict_types=1);

namespace App\Core;

final class Log
{
    /** Запись в журнал аудита (audit_log). $details пишется в new_value. */
    public static function act(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null, ?int $userId = null): void
    {
        self::audit($action, $entityType, $entityId, null, $details, $userId);
    }

    /** Запись в журнал аудита с прежним и новым значением. */
    public static function audit(string $action, ?string $entityType, ?int $entityId, $old = null, $new = null, ?int $userId = null): void
    {
        $enc = fn($v) => $v === null ? null : mb_substr(is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE), 0, 60000);
        try {
            Db::insert('audit_log', [
                'user_id' => $userId ?? (Auth::id() ?: null),
                'action' => $action,
                'entity_type' => $entityType ?? '',
                'entity_id' => $entityId,
                'old_value' => $enc($old),
                'new_value' => $enc($new),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            self::file('audit_log failed: ' . $e->getMessage());
        }
    }

    public static function file(string $msg): void
    {
        @file_put_contents(STORAGE . '/logs/app.log', '[' . now() . '] ' . $msg . "\n", FILE_APPEND);
    }
}
