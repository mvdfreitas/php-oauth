<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Este script deve ser executado via CLI');
}

require_once __DIR__ . '/oauth2_complete.php';

if ($argc < 2) {
    echo "Uso: php server/totp_code.php <usuario>\n";
    exit(1);
}

$username = $argv[1];
$storage = loadStorage();
$secret = $storage['totp_secrets'][$username]['secret'] ?? null;

if ($secret === null) {
    echo "Nenhum segredo TOTP encontrado para '$username'.\n";
    echo "Faça login via /authorize com MFA habilitado para realizar enrollment.\n";
    exit(1);
}

echo totpGenerate($secret) . "\n";
