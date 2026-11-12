<?php
declare(strict_types=1);

// Маршруты второй части: справочник услуг, сотрудники, клиенты и дома, сводка, отчёты, администрирование, уведомления.
// Подключается из routes.php: $fn = require 'routes_extra.php'; $fn($router).

use App\Controllers\AdminController as Adm;
use App\Controllers\ClientController as Cli;
use App\Controllers\DashboardController as Dash;
use App\Controllers\EmployeeController as Emp;
use App\Controllers\NotificationController as Ntf;
use App\Controllers\ReportController as Rep;
use App\Controllers\ServiceCatalogController as Svc;
use App\Core\Router;

return function (Router $r): void {
    $staff = ['dispatcher', 'manager', 'admin'];
    $mgmt = ['manager', 'admin'];
    $adm = ['admin'];

    // ----- Категории услуг и справочник услуг -----
    $r->get('/api/service-categories', [Svc::class, 'categories']);
    $r->post('/api/service-categories', [Svc::class, 'saveCategory'], $adm);
    $r->put('/api/service-categories/{id}', [Svc::class, 'saveCategory'], $adm);
    $r->get('/api/services', [Svc::class, 'list']);
    $r->post('/api/services', [Svc::class, 'create'], $adm);
    $r->get('/api/services/{id}', [Svc::class, 'get']);
    $r->put('/api/services/{id}', [Svc::class, 'update'], $adm);
    $r->post('/api/services/{id}/archive', [Svc::class, 'archive'], $adm);
    $r->post('/api/services/{id}/restore', [Svc::class, 'restore'], $adm);
    $r->delete('/api/services/{id}', [Svc::class, 'delete'], $adm);
    $r->get('/api/services/{id}/changes', [Svc::class, 'changes'], $staff);

    // ----- Сотрудники -----
    $r->get('/api/me/schedule', [Emp::class, 'meSchedule']);
    $r->get('/api/me/stats', [Emp::class, 'meStats']);
    $r->get('/api/employees', [Emp::class, 'list'], $staff);
    $r->post('/api/employees', [Emp::class, 'create'], $adm);
    $r->get('/api/employees/{id}', [Emp::class, 'get']);
    $r->put('/api/employees/{id}', [Emp::class, 'update'], $adm);
    $r->put('/api/employees/{id}/schedule', [Emp::class, 'saveSchedule'], $adm);
    $r->post('/api/employees/{id}/exceptions', [Emp::class, 'addException'], $adm);
    $r->delete('/api/employees/{id}/exceptions/{eid}', [Emp::class, 'deleteException'], $adm);
    $r->put('/api/employees/{id}/services', [Emp::class, 'saveServices'], $adm);
    $r->put('/api/employees/{id}/houses', [Emp::class, 'saveHouses'], $adm);

    // ----- Клиенты -----
    $r->get('/api/clients', [Cli::class, 'list'], $staff);
    $r->get('/api/clients/lookup', [Cli::class, 'lookup'], $staff);
    $r->get('/api/clients/{id}', [Cli::class, 'get'], $staff);
    $r->post('/api/clients', [Cli::class, 'create'], ['dispatcher', 'admin']);
    $r->put('/api/clients/{id}', [Cli::class, 'update'], ['dispatcher', 'admin']);

    // ----- Дома и помещения -----
    $r->get('/api/houses', [Cli::class, 'houses']);
    $r->post('/api/houses', [Cli::class, 'createHouse'], $adm);
    $r->get('/api/houses/{id}/premises', [Cli::class, 'premises']);
    $r->post('/api/houses/{id}/premises', [Cli::class, 'createPremise'], $adm);
    $r->put('/api/houses/{id}/premises/{pid}', [Cli::class, 'updatePremise'], $adm);
    $r->put('/api/houses/{id}', [Cli::class, 'updateHouse'], $adm);

    // ----- Сводка и отчёты -----
    $r->get('/api/dashboard', [Dash::class, 'get'], $mgmt);
    $r->get('/api/reports', [Rep::class, 'types'], $staff);
    $r->get('/api/reports/{type}', [Rep::class, 'get'], $staff);

    // ----- Администрирование -----
    $r->get('/api/users', [Adm::class, 'users'], $adm);
    $r->post('/api/users', [Adm::class, 'createUser'], $adm);
    $r->put('/api/users/{id}', [Adm::class, 'updateUser'], $adm);
    $r->post('/api/users/{id}/reset-password', [Adm::class, 'resetPassword'], $adm);
    $r->post('/api/users/{id}/unlock', [Adm::class, 'unlock'], $adm);
    $r->get('/api/settings', [Adm::class, 'settings'], $adm);
    $r->put('/api/settings', [Adm::class, 'saveSettings'], $adm);
    $r->get('/api/rules', [Adm::class, 'rules'], $mgmt);
    $r->post('/api/rules', [Adm::class, 'createRule'], $adm);
    $r->put('/api/rules/{id}', [Adm::class, 'updateRule'], $adm);
    $r->delete('/api/rules/{id}', [Adm::class, 'deleteRule'], $adm);
    $r->get('/api/audit', [Adm::class, 'audit'], $adm);
    $r->post('/api/backup', [Adm::class, 'backup'], $adm);
    $r->get('/api/backups', [Adm::class, 'backups'], $adm);
    $r->get('/api/backups/{name}', [Adm::class, 'downloadBackup'], $adm);
    $r->get('/api/integrations/queue', [Adm::class, 'queue'], $mgmt);
    $r->post('/api/integrations/queue/{id}/retry', [Adm::class, 'retry'], $adm);

    // ----- Уведомления -----
    $r->get('/api/notifications', [Ntf::class, 'list']);
    $r->post('/api/notifications/read-all', [Ntf::class, 'readAll']);
    $r->post('/api/notifications/{id}/read', [Ntf::class, 'read']);
};
