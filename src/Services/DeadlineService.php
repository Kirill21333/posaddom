<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;
use DateTimeImmutable;

/** Расчёт сроков заявки (ТЗ 7.6): выезд, устранение, предупреждение, остаток и просрочка. */
final class DeadlineService
{
    /**
     * @param array $category строка request_categories (response_minutes | response_work_days, resolve_hours)
     * @return array{arrive_due:string,due_at:string,warn_at:string}
     */
    public static function calculate(array $category, DateTimeImmutable $receivedAt, ?array $settings = null): array
    {
        $arrive = $category['response_minutes'] !== null
            ? $receivedAt->modify('+' . (int)$category['response_minutes'] . ' minutes')
            : self::addWorkDays($receivedAt, max(1, (int)$category['response_work_days']), $settings);
        $due = $receivedAt->modify('+' . (int)$category['resolve_hours'] . ' hours');
        $warn = self::warnAt($receivedAt, $due, $settings);
        return [
            'arrive_due' => $arrive->format('Y-m-d H:i:s'),
            'due_at' => $due->format('Y-m-d H:i:s'),
            'warn_at' => $warn->format('Y-m-d H:i:s'),
        ];
    }

    /** warn_at = received + (1 - warn_percent/100) * (due - received). */
    public static function warnAt(DateTimeImmutable $receivedAt, DateTimeImmutable $due, ?array $settings = null): DateTimeImmutable
    {
        $pct = (int)self::setting('warn_percent', '25', $settings);
        $total = $due->getTimestamp() - $receivedAt->getTimestamp();
        return $receivedAt->setTimestamp($receivedAt->getTimestamp() + (int)round($total * (100 - $pct) / 100));
    }

    /**
     * Плановый срок выезда: «в то же время следующего рабочего дня» (пн-пт; праздники не учитываются).
     * Время суток, выходящее за рабочий день (09:00-18:00), приводится к его границе.
     */
    public static function addWorkDays(DateTimeImmutable $from, int $days, ?array $settings = null): DateTimeImmutable
    {
        $d = $from;
        for ($i = 0; $i < $days; $i++) {
            do {
                $d = $d->modify('+1 day');
            } while ((int)$d->format('N') > 5);
        }
        $start = self::setting('business_start', '09:00', $settings);
        $end = self::setting('business_end', '18:00', $settings);
        $day = $d->format('Y-m-d');
        $t = $d->format('H:i');
        if ($t < $start) {
            return new DateTimeImmutable("$day $start:00");
        }
        if ($t > $end) {
            return new DateTimeImmutable("$day $end:00");
        }
        return $d;
    }

    public static function isWorkingMoment(DateTimeImmutable $d, ?array $settings = null): bool
    {
        if ((int)$d->format('N') > 5) {
            return false;
        }
        $t = $d->format('H:i');
        return $t >= self::setting('business_start', '09:00', $settings) && $t < self::setting('business_end', '18:00', $settings);
    }

    /** Минуты до срока (отрицательные - срок прошёл). */
    public static function minutesLeft(string $due, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        return (int)floor((strtotime($due) - $now->getTimestamp()) / 60);
    }

    /** Величина просрочки в минутах или null, если срок не нарушен. */
    public static function overdueMinutes(string $due, ?string $completedAt, ?DateTimeImmutable $now = null): ?int
    {
        $end = $completedAt !== null ? strtotime($completedAt) : ($now ?? new DateTimeImmutable())->getTimestamp();
        $m = (int)floor(($end - strtotime($due)) / 60);
        return $m > 0 ? $m : null;
    }

    /** Нужно ли предупреждение: наступил warn_at и срок ещё не нарушен. */
    public static function needsWarning(string $warnAt, ?DateTimeImmutable $now = null): bool
    {
        return strtotime($warnAt) <= ($now ?? new DateTimeImmutable())->getTimestamp();
    }

    private static function setting(string $name, string $default, ?array $settings): string
    {
        return $settings[$name] ?? Settings::get($name, $default);
    }
}
