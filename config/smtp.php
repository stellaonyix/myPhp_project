<?php

return [
    'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'port' => (int) (getenv('SMTP_PORT') ?: 587),
    'username' => getenv('SMTP_USERNAME') ?: 'onyixanigbo@gmail.com',
    'password' => getenv('SMTP_PASSWORD') ?: 'tmts jpow gamf udkj',
    'from' => getenv('SMTP_FROM') ?: (getenv('SMTP_USERNAME') ?: 'onyixanigbo@gmail.com'),
    'from_name' => getenv('SMTP_FROM_NAME') ?: 'Exam Shield',
    'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
];
