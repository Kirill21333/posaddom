<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Request;
use App\Core\Settings;
use App\Services\Cron;

final class AuthController
{
    private static function payload(array $u): array
    {
        return ['id' => $u['id'], 'login' => $u['login'], 'role' => $u['role'], 'role_name' => $u['role_name'],
                'name' => $u['name'], 'full_name' => $u['full_name'], 'employee_id' => $u['employee_id'],
                'must_change_password' => (bool)$u['must_change_password']];
    }

    public static function login(Request $r): array
    {
        $login = (string)$r->str('login', '');
        $pass = (string)$r->input('password', '');
        if ($login === '' || $pass === '') {
            throw HttpException::bad('Введите логин и пароль', ['login' => $login === '' ? 'Обязательное поле' : '', 'password' => $pass === '' ? 'Обязательное поле' : '']);
        }
        $u = Db::one('SELECT u.*, st.code AS employee_status FROM users u
                      LEFT JOIN employees e ON e.id = u.employee_id LEFT JOIN employee_statuses st ON st.id = e.status_id
                      WHERE u.login = ?', [$login]);
        if (!$u) {
            Log::audit('auth.login_failed', 'user', null, null, ['login' => mb_substr($login, 0, 50)], null);
            throw HttpException::unauth('Неверный логин или пароль');
        }
        $maxAttempts = max(1, Settings::int('login_max_attempts', 5));
        $lockMinutes = max(1, Settings::int('login_lock_minutes', 15));
        if ($u['locked_until'] !== null && strtotime($u['locked_until']) > time()) {
            Log::audit('auth.login_blocked', 'user', (int)$u['id'], null, null, (int)$u['id']);
            throw self::locked($u['locked_until']);
        }
        if (!password_verify($pass, $u['password_hash'])) {
            $attempts = (int)$u['failed_attempts'] + 1;
            if ($attempts >= $maxAttempts) {
                $until = date('Y-m-d H:i:s', time() + $lockMinutes * 60);
                Db::update('users', ['failed_attempts' => 0, 'locked_until' => $until], 'id = ?', [$u['id']]);
                Log::audit('auth.locked', 'user', (int)$u['id'], null, ['until' => $until], (int)$u['id']);
                throw self::locked($until);
            }
            Db::update('users', ['failed_attempts' => $attempts], 'id = ?', [$u['id']]);
            Log::audit('auth.login_failed', 'user', (int)$u['id'], null, ['attempt' => $attempts], (int)$u['id']);
            $left = $maxAttempts - $attempts;
            throw HttpException::unauth("Неверный логин или пароль. Осталось попыток: $left");
        }
        if (!(int)$u['is_active'] || $u['employee_status'] === 'dismissed') {
            throw HttpException::forbidden('Учётная запись отключена. Обратитесь к администратору');
        }
        Db::update('users', ['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()], 'id = ?', [$u['id']]);
        Auth::login((int)$u['id']);
        Log::audit('auth.login', 'user', (int)$u['id'], null, null, (int)$u['id']);
        return ['user' => self::payload(Auth::user())];
    }

    private static function locked(string $until): HttpException
    {
        return (new HttpException(403, 'Учётная запись заблокирована до ' . date('H:i', strtotime($until)) . ' из-за неудачных попыток входа'))
            ->with(['locked_until' => $until], 'locked');
    }

    public static function logout(Request $r): array
    {
        $uid = Auth::id();
        Log::audit('auth.logout', 'user', $uid, null, null, $uid);
        Auth::logout();
        return ['logged_out' => true];
    }

    public static function me(Request $r): array
    {
        $u = Auth::requireLogin();
        Cron::tick();
        return ['user' => self::payload($u)];
    }

    public static function password(Request $r): array
    {
        $u = Auth::requireLogin();
        $old = (string)$r->input('old', '');
        $new = (string)$r->input('new', '');
        $hash = (string)Db::val('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify($old, $hash)) {
            throw HttpException::bad('Текущий пароль указан неверно', ['old' => 'Неверный пароль']);
        }
        $min = (int)cfg('security.password_min_len', 8);
        if (mb_strlen($new) < $min || !preg_match('/[A-Za-zА-Яа-я]/u', $new) || !preg_match('/\d/', $new)) {
            throw HttpException::bad("Новый пароль: не менее $min символов, буквы и цифры", ['new' => 'Слишком простой пароль']);
        }
        if ($new === $old) {
            throw HttpException::bad('Новый пароль должен отличаться от текущего', ['new' => 'Совпадает с текущим']);
        }
        Db::update('users', ['password_hash' => Auth::hashPassword($new), 'must_change_password' => 0], 'id = ?', [$u['id']]);
        Log::audit('auth.password', 'user', $u['id'], null, null, $u['id']);
        return ['changed' => true];
    }
}
