<?php

declare(strict_types=1);

return [
    'driver' => \App\Core\Env::get('DB_CONNECTION', 'mysql'),
    'host' => \App\Core\Env::get('DB_HOST', '127.0.0.1'),
    'port' => \App\Core\Env::get('DB_PORT', '3306'),
    'database' => \App\Core\Env::get('DB_DATABASE', 'energy_dss'),
    'username' => \App\Core\Env::get('DB_USERNAME', 'root'),
    'password' => \App\Core\Env::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
];
