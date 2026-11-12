<?php
declare(strict_types=1);

// Единая точка входа: API (/api/*), статика public/* и SPA-оболочка (index.html).
// Запуск для разработки:  php -S 127.0.0.1:8300 -t public public/index.php

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if (PHP_SAPI === 'cli-server' && $path !== '/') {
    $file = __DIR__ . $path;
    if (is_file($file) && substr($file, -4) !== '.php' && strpos($path, '..') === false) {
        return false; // статика отдаётся встроенным сервером
    }
}

header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (strpos($path, '/api/') !== 0 && $path !== '/api') {
    // SPA: любой не-API путь без расширения отдаёт оболочку; отсутствующие файлы (с расширением) - 404
    if ($path !== '/' && preg_match('/\.[A-Za-z0-9]{1,5}$/', $path) && !preg_match('/\.html$/', $path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Файл не найден';
        return;
    }
    $index = __DIR__ . '/index.html';
    if (!is_file($index)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Интерфейс не установлен (public/index.html)';
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    return;
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Raw;
use App\Core\Request;
use App\Core\Router;

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// предупреждения PHP не должны портить JSON: они пишутся в storage/logs/php_errors.log
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE . '/logs/php_errors.log');
error_reporting(E_ALL);

function api_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
}

function api_error(int $status, string $message, array $fields = [], array $extra = [], ?string $code = null): void
{
    $body = ['ok' => false, 'error' => $message];
    if ($code !== null) {
        $body['code'] = $code;
    }
    if ($fields) {
        $body['fields'] = $fields;
    }
    if ($extra) {
        $body = array_merge($body, $extra, ['data' => $extra]);
    }
    api_json($status, $body);
}

try {
    Auth::start();
    $req = new Request();
    $router = new Router();
    (require __DIR__ . '/../src/routes.php')($router);
    $result = $router->dispatch($req);
    if ($result instanceof Raw) {
        $result->send();
    } else {
        api_json(200, ['ok' => true, 'data' => $result]);
    }
    // ленивый планировщик (если нет cron): раз в 5 минут, после отправки ответа
    register_shutdown_function(function () {
        try {
            \App\Services\Cron::tick();
        } catch (\Throwable $e) {
            \App\Core\Log::file('tick: ' . $e->getMessage());
        }
    });
} catch (HttpException $e) {
    $code = $e->getCode();
    $map = [401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'validation'];
    api_error($code, $e->getMessage(), $e->fields, $e->extra, $e->errorCode ?? ($map[$code] ?? null));
} catch (\PDOException $e) {
    \App\Core\Log::file('PDO: ' . $e->getMessage());
    $msg = $e->getMessage();
    // сообщения триггеров (SQLSTATE 45000) понятны пользователю
    if (($e->errorInfo[0] ?? '') === '45000' && isset($e->errorInfo[2])) {
        api_error(409, (string)$e->errorInfo[2], [], [], 'conflict');
    } else {
        api_error(500, cfg('app.debug') ? 'Ошибка БД: ' . $msg : 'Внутренняя ошибка сервера');
    }
} catch (\Throwable $e) {
    \App\Core\Log::file(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_error(500, cfg('app.debug') ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : 'Внутренняя ошибка сервера');
}
