<?php
declare(strict_types=1);

/*
 * Copy this file to config.php on your PHP host.
 * Never commit real database credentials to a public repository.
 * Prefer setting these values as environment variables in your hosting panel.
 */
return [
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: '',
    'db_user' => getenv('DB_USER') ?: '',
    'db_pass' => getenv('DB_PASS') ?: '',
    'support_email' => getenv('SUPPORT_EMAIL') ?: 'mehrzada9999@gmail.com',
    'mail_from_email' => getenv('MAIL_FROM_EMAIL') ?: '',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'Portfolio Support',
    'admin_password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: '',
    'allowed_origin' => getenv('ALLOWED_ORIGIN') ?: ''
];
