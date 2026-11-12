<?php
declare(strict_types=1);

use App\Controllers\AuthController as Au;
use App\Controllers\ReferenceController as Ref;
use App\Controllers\RequestController as Req;
use App\Core\Router;

$staff = ['dispatcher', 'manager', 'admin'];

return function (Router $r) use ($staff): void {
    // ----- Авторизация (публичный только вход: roles = null) -----
    $r->post('/api/auth/login', [Au::class, 'login'], null);
    $r->post('/api/auth/logout', [Au::class, 'logout']);
    $r->get('/api/auth/me', [Au::class, 'me']);
    $r->post('/api/auth/password', [Au::class, 'password']);

    // ----- Справочники для форм -----
    $r->get('/api/ref', [Ref::class, 'ref']);

    // ----- Заявки -----
    $r->get('/api/requests', [Req::class, 'list']);
    $r->post('/api/requests/preview', [Req::class, 'preview'], ['dispatcher', 'admin']);
    $r->post('/api/requests', [Req::class, 'create'], ['dispatcher', 'admin']);
    $r->get('/api/requests/{id}', [Req::class, 'get']);
    $r->put('/api/requests/{id}', [Req::class, 'update'], $staff);
    $r->post('/api/requests/{id}/status', [Req::class, 'status']);
    $r->post('/api/requests/{id}/assign', [Req::class, 'assign'], $staff);
    $r->get('/api/requests/{id}/candidates', [Req::class, 'candidates'], $staff);
    $r->post('/api/requests/{id}/comments', [Req::class, 'comment']);
    $r->post('/api/requests/{id}/photos', [Req::class, 'uploadPhoto']);
    $r->get('/api/photos/{id}', [Req::class, 'photo']);
    $r->delete('/api/photos/{id}', [Req::class, 'deletePhoto'], ['admin']);

    // ----- Дополнительные маршруты (справочники, отчёты, администрирование) -----
    $extra = __DIR__ . '/routes_extra.php';
    if (is_file($extra)) {
        $fn = require $extra;
        if (is_callable($fn)) {
            $fn($r);
        }
    }
};
