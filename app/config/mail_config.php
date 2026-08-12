<?php
// config/mail_config.php
// Lütfen bu bilgileri kendi SMTP sunucu bilgilerinizle güncelleyiniz.
return [
    'host' => getenv('MAIL_HOST') ?: ($_ENV['MAIL_HOST'] ?? 'smtp.example.com'),
    'port' => getenv('MAIL_PORT') ?: ($_ENV['MAIL_PORT'] ?? 587),
    'username' => getenv('MAIL_USERNAME') ?: ($_ENV['MAIL_USERNAME'] ?? 'user@example.com'),
    'password' => getenv('MAIL_PASSWORD') ?: ($_ENV['MAIL_PASSWORD'] ?? ''),
    'from_address' => getenv('MAIL_FROM_ADDRESS') ?: ($_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@example.com'),
    'from_name' => getenv('MAIL_FROM_NAME') ?: ($_ENV['MAIL_FROM_NAME'] ?? 'Eaprimus')
];
?>