<?php
// Базовая конфигурация. Локальные переопределения - в config/config.local.php (не коммитится).
$config = [
    'app' => [
        'name'      => 'ПосадДом',
        'url'       => 'http://127.0.0.1:8300',
        'debug'     => true,                 // подробные тексты ошибок сервера
        'timezone'  => 'Europe/Moscow',
        'lazy_cron' => true,                 // нет планировщика - фоновые задания запускаются из запросов раз в 5 минут
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'posaddom',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'from'        => 'no-reply@posaddom.ru',
        'from_name'   => 'ПосадДом',
        'smtp_host'   => '',                 // пусто - письма пишутся в storage/mails
        'smtp_port'   => 587,
        'smtp_user'   => '',
        'smtp_pass'   => '',
        'smtp_secure' => 'tls',              // tls | ssl | ''
    ],
    'sms' => [
        'url'    => '',                      // пусто - SMS пишутся в storage/sms
        'token'  => '',
        'sender' => 'PosadDom',
    ],
    'gis' => [
        'gis_gkh_url' => '',                 // пусто - обмен с ГИС ЖКХ идёт через очередь integration_queue (заглушка)
        'rso_url'     => '',                 // то же для передачи аварийных заявок в РСО
        'token'       => '',
        'max_attempts' => 5,
        'retry_minutes' => 5,
    ],
    'photos' => [
        'max_bytes'       => 10 * 1024 * 1024,
        'allowed_types'   => ['image/jpeg', 'image/png'],
        'retention_years' => 3,
    ],
    'security' => [
        'password_min_len' => 8,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
return $config;
