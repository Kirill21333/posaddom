<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** Сквозная нумерация заявок: <префикс категории>-<год>-<6 цифр>, счётчик на год в request_number_seq. */
final class NumberingService
{
    public static function format(string $prefix, int $year, int $n): string
    {
        return sprintf('%s-%d-%06d', $prefix, $year, $n);
    }

    /** Выдаёт следующий номер. Вызывать внутри транзакции создания заявки (строка счётчика блокируется FOR UPDATE). */
    public static function next(string $prefix, ?int $year = null): string
    {
        $year ??= (int)date('Y');
        return Db::tx(function () use ($prefix, $year) {
            Db::exec('INSERT IGNORE INTO request_number_seq (`year`, last_value) VALUES (?, 0)', [$year]);
            $last = (int)Db::val('SELECT last_value FROM request_number_seq WHERE `year` = ? FOR UPDATE', [$year]);
            $n = $last + 1;
            Db::exec('UPDATE request_number_seq SET last_value = ? WHERE `year` = ?', [$n, $year]);
            return self::format($prefix, $year, $n);
        });
    }

    /** Ближайший свободный номер без побочных эффектов (для предпросмотра). */
    public static function peek(string $prefix, ?int $year = null): string
    {
        $year ??= (int)date('Y');
        $last = (int)Db::val('SELECT last_value FROM request_number_seq WHERE `year` = ?', [$year]);
        return self::format($prefix, $year, $last + 1);
    }
}
