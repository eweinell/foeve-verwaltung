<?php

declare(strict_types=1);

/**
 * Liest die Konfiguration aus der Umgebung (.env via phpdotenv, in index.php geladen)
 * und stellt sie als Array bereit. Keine Secrets im Code — alles kommt aus .env.
 */

$bool = static fn (string $key, bool $default): bool => match (strtolower((string) ($_ENV[$key] ?? ''))) {
    '1', 'true', 'yes', 'on' => true,
    '0', 'false', 'no', 'off' => false,
    default => $default,
};

return [
    'app' => [
        'env'      => $_ENV['APP_ENV'] ?? 'prod',
        'debug'    => $bool('APP_DEBUG', false),
        'url'      => $_ENV['APP_URL'] ?? '',
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Europe/Berlin',
    ],
    'db' => [
        'dsn'  => $_ENV['DB_DSN'] ?? '',
        'user' => $_ENV['DB_USER'] ?? '',
        'pass' => $_ENV['DB_PASS'] ?? '',
    ],
    'crypto' => [
        'key' => $_ENV['APP_CRYPTO_KEY'] ?? '',
    ],
    'mail' => [
        'dsn'             => $_ENV['MAIL_DSN'] ?? '',
        'absender_adresse' => $_ENV['MAIL_ABSENDER_ADRESSE'] ?? '',
        'absender_name'    => $_ENV['MAIL_ABSENDER_NAME'] ?? '',
    ],
    'captcha' => [
        'sitekey' => $_ENV['TRUSTCAPTCHA_SITEKEY'] ?? '',
        'secret'  => $_ENV['TRUSTCAPTCHA_SECRET'] ?? '',
    ],
    'session' => [
        'secure' => $bool('SESSION_SECURE', true),
    ],
    'pfade' => [
        'basis'     => dirname(__DIR__),
        'templates' => dirname(__DIR__) . '/templates',
        'log'       => dirname(__DIR__) . '/var/log',
    ],
];
