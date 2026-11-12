<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public const ROLE_NAMES = ['dispatcher' => 'диспетчер', 'employee' => 'сотрудник', 'manager' => 'руководитель', 'admin' => 'администратор'];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('PDSESS');
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
            session_start();
        }
    }

    /**
     * Защита от CSRF для изменяющих запросов: куки SameSite=Lax + проверка Origin/Referer, если браузер их прислал.
     */
    public static function checkOrigin(Request $req): void
    {
        $o = $req->header('origin') ?? '';
        if ($o === '' || $o === 'null') {
            return;
        }
        $host = parse_url($o, PHP_URL_HOST);
        $port = parse_url($o, PHP_URL_PORT);
        $h = $host . ($port ? ':' . $port : '');
        if ($h !== ($req->header('host') ?? $h)) {
            throw new HttpException(403, 'Запрос с чужого источника отклонён');
        }
    }

    /** Текущий пользователь (с ролью и привязкой к сотруднику) или null. */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        self::start();
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return self::$user = null;
        }
        $u = Db::one('SELECT u.id, u.login, u.email, u.employee_id, u.is_active, u.must_change_password, r.code AS role,
                             e.last_name, e.first_name, e.middle_name, e.status_id AS employee_status_id
                      FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.id = u.employee_id
                      WHERE u.id = ?', [$id]);
        if (!$u || !$u['is_active']) {
            unset($_SESSION['uid']);
            return self::$user = null;
        }
        $u['id'] = (int)$u['id'];
        $u['employee_id'] = $u['employee_id'] !== null ? (int)$u['employee_id'] : null;
        $u['roles'] = [$u['role']];
        $u['full_name'] = self::fullName($u) ?: $u['login'];
        $u['short_name'] = self::shortName($u) ?: $u['login'];
        $u['name'] = $u['short_name'];
        $u['role_name'] = self::ROLE_NAMES[$u['role']] ?? $u['role'];
        return self::$user = $u;
    }

    public static function id(): int
    {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            throw HttpException::unauth();
        }
        return $u;
    }

    public static function hasRole(string $role): bool
    {
        $u = self::user();
        return $u && in_array($role, $u['roles'], true);
    }

    public static function hasAnyRole(array $roles): bool
    {
        $u = self::user();
        return $u && (bool)array_intersect($roles, $u['roles']);
    }

    public static function login(int $uid): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        self::$loaded = false;
        self::$user = null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_regenerate_id(true);
        self::$loaded = false;
        self::$user = null;
    }

    public static function hashPassword(string $p): string
    {
        return password_hash($p, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    public static function fullName(array $u): string
    {
        return trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? ''));
    }

    /** «Морозова Е.В.» */
    public static function shortName(array $u): string
    {
        if (empty($u['last_name'])) {
            return '';
        }
        $s = $u['last_name'];
        if (!empty($u['first_name'])) {
            $s .= ' ' . mb_substr($u['first_name'], 0, 1) . '.';
        }
        if (!empty($u['middle_name'])) {
            $s .= mb_substr($u['middle_name'], 0, 1) . '.';
        }
        return $s;
    }
}
