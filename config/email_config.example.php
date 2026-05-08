<?php
// config/email_config.example.php
// Copy this file to email_config.php and fill in your credentials

return [
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'your-email@gmail.com',
        'password' => 'your-16-char-app-password-here',
    ],
    'from' => [
        'email' => 'your-email@gmail.com',
        'name' => 'PetUnity'
    ]
];
?>