<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Mailer;

/**
 * Уведомления (ТЗ 7.2, правило 11).
 * Сотрудникам: запись на сайте (channel=site, статус new -> read) + дубль в storage/mails (e-mail или мессенджер).
 * Клиентам: SMS и/или e-mail через очередь интеграций, только при notify_consent = 1.
 */
final class NotificationService
{
    /** Сотруднику; $mirror - дублировать на e-mail/мессенджер. Возвращает id site-уведомления. */
    public static function employee(int $employeeId, string $type, string $message, ?int $requestId = null, bool $mirror = true): int
    {
        $id = Db::insert('notifications', [
            'recipient_type' => 'employee', 'employee_id' => $employeeId, 'request_id' => $requestId, 'channel' => 'site',
            'type' => $type, 'message' => $message, 'status' => 'new', 'created_at' => now(),
        ]);
        if ($mirror) {
            $emp = Db::one('SELECT email, phone FROM employees WHERE id = ?', [$employeeId]);
            if ($emp && !empty($emp['email'])) {
                $ok = Mailer::send($emp['email'], '[' . cfg('app.name') . '] ' . self::subject($type), $message);
                Db::insert('notifications', [
                    'recipient_type' => 'employee', 'employee_id' => $employeeId, 'request_id' => $requestId, 'channel' => 'email',
                    'type' => $type, 'message' => $message, 'status' => $ok ? 'sent' : 'failed', 'created_at' => now(), 'sent_at' => $ok ? now() : null,
                ]);
            } elseif ($emp) {
                IntegrationService::enqueue('messenger', $requestId, ['to' => (string)($emp['phone'] ?? ''), 'text' => $message]);
                Db::insert('notifications', [
                    'recipient_type' => 'employee', 'employee_id' => $employeeId, 'request_id' => $requestId, 'channel' => 'messenger',
                    'type' => $type, 'message' => $message, 'status' => 'sent', 'created_at' => now(), 'sent_at' => now(),
                ]);
            }
        }
        return $id;
    }

    /** Всем активным сотрудникам с учётной записью указанной роли. */
    public static function role(string $role, string $type, string $message, ?int $requestId = null, bool $mirror = false): void
    {
        foreach (self::employeesWithRole($role) as $eid) {
            self::employee($eid, $type, $message, $requestId, $mirror);
        }
    }

    public static function dispatchers(string $type, string $message, ?int $requestId = null, bool $mirror = false): void
    {
        self::role('dispatcher', $type, $message, $requestId, $mirror);
    }

    public static function managers(string $type, string $message, ?int $requestId = null, bool $mirror = false): void
    {
        self::role('manager', $type, $message, $requestId, $mirror);
    }

    /** @return int[] employee_id активных пользователей роли */
    public static function employeesWithRole(string $role): array
    {
        return array_map('intval', array_column(Db::all(
            'SELECT u.employee_id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.code = ? AND u.is_active = 1 AND u.employee_id IS NOT NULL', [$role]), 'employee_id'));
    }

    /** Клиенту: SMS (и e-mail, если указан) при наличии согласия на уведомления. */
    public static function client(int $clientId, string $type, string $message, ?int $requestId = null): void
    {
        $c = Db::one('SELECT phone, email, notify_consent FROM clients WHERE id = ?', [$clientId]);
        if (!$c || !(int)$c['notify_consent']) {
            return;
        }
        $channels = [];
        if (!empty($c['phone'])) {
            $channels['sms'] = ['to' => $c['phone'], 'text' => $message];
        }
        if (!empty($c['email'])) {
            $channels['email'] = ['to' => $c['email'], 'subject' => self::subject($type), 'text' => $message];
        }
        foreach ($channels as $channel => $payload) {
            $nid = Db::insert('notifications', [
                'recipient_type' => 'client', 'client_id' => $clientId, 'request_id' => $requestId, 'channel' => $channel,
                'type' => $type, 'message' => $message, 'status' => 'new', 'created_at' => now(),
            ]);
            IntegrationService::enqueue($channel, $requestId, $payload + ['notification_id' => $nid]);
        }
    }

    private static function subject(string $type): string
    {
        return [
            'new_request' => 'Вам назначена заявка', 'status_changed' => 'Изменён статус заявки',
            'deadline_warning' => 'Приближается срок заявки', 'overdue' => 'Заявка просрочена',
            'off_schedule' => 'Вызов вне графика', 'queued' => 'Заявка в очереди нераспределённых',
            'reassigned' => 'Заявка переназначена', 'request_registered' => 'Заявка принята',
            'request_assigned' => 'По заявке назначен исполнитель', 'request_closed' => 'Заявка закрыта',
        ][$type] ?? 'Уведомление';
    }
}
