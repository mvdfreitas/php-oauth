<?php
/**
 * Device Authorization Grant CLI (RFC 8628)
 * Simula uma ferramenta CLI como gh auth login
 */

declare(strict_types=1);

$server = 'http://localhost:8000';
$client_id = 'cliente_teste';
$client_secret = 'senha_secreta_123';

function httpPost(string $url, array $data, array $headers = []): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    if ($body === false) {
        $body = '';
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'code' => $code,
        'body' => $body,
        'json' => json_decode($body, true),
    ];
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                Device Authorization Grant CLI                    ║\n";
echo "║                    RFC 8628 - Device Flow                        ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

echo "Solicitando código do dispositivo...\n\n";

// 1. Solicitar device_code
$res = httpPost($server . '/oauth2_complete.php?action=device_code', [
    'client_id' => $client_id,
    'scope' => 'openid profile email',
]);

if ($res['code'] !== 200 || !isset($res['json']['device_code'])) {
    echo "Erro ao obter device_code:\n";
    echo $res['body'] . "\n";
    exit(1);
}

$data = $res['json'];
$device_code = $data['device_code'];
$user_code = $data['user_code'];
$verification_uri = $data['verification_uri'];
$verification_uri_complete = $data['verification_uri_complete'];
$interval = $data['interval'] ?? 5;
$expires_in = $data['expires_in'] ?? 600;

echo "✓ Código obtido com sucesso!\n\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Código do Usuário:  " . $user_code . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
echo "Acesse no seu navegador:\n";
echo "  " . $verification_uri . "\n\n";
echo "Ou acesse diretamente com o código preenchido:\n";
echo "  " . $verification_uri_complete . "\n\n";
echo "O código expira em " . $expires_in . " segundos.\n";
echo "Fazendo polling a cada " . $interval . " segundos...\n\n";

$start = time();
$poll_interval = $interval;

while (true) {
    if ((time() - $start) > $expires_in) {
        echo "\n✗ Código expirado!\n";
        exit(1);
    }

    sleep($poll_interval);

    $tokenRes = httpPost($server . '/oauth2_complete.php?action=token', [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'device_code' => $device_code,
    ]);

    $tokenJson = $tokenRes['json'] ?? [];

    if (isset($tokenJson['access_token'])) {
        echo "\n✓ Autorização concedida com sucesso!\n\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  Access Token: " . substr($tokenJson['access_token'], 0, 40) . "...\n";
        echo "  Token Type:   " . ($tokenJson['token_type'] ?? 'Bearer') . "\n";
        echo "  Expires In:   " . ($tokenJson['expires_in'] ?? 3600) . "s\n";
        echo "  Scope:        " . ($tokenJson['scope'] ?? '') . "\n";
        if (isset($tokenJson['refresh_token'])) {
            echo "  Refresh Token: " . substr($tokenJson['refresh_token'], 0, 40) . "...\n";
        }
        if (isset($tokenJson['id_token'])) {
            echo "  ID Token:     Presente (JWT)\n";
        }
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        echo "Dispositivo autenticado e pronto para uso!\n";
        exit(0);
    }

    if (isset($tokenJson['error'])) {
        if ($tokenJson['error'] === 'authorization_pending') {
            echo "⏳ Aguardando autorização... (status: pending)\n";
            continue;
        } elseif ($tokenJson['error'] === 'slow_down') {
            $poll_interval += 5;
            echo "⚠️  Polling muito rápido. Aumentando intervalo para " . $poll_interval . "s...\n";
            continue;
        } elseif ($tokenJson['error'] === 'expired_token') {
            echo "\n✗ Código expirado!\n";
            exit(1);
        } elseif ($tokenJson['error'] === 'access_denied') {
            echo "\n✗ Acesso negado pelo usuário!\n";
            exit(1);
        } else {
            echo "\n✗ Erro: " . ($tokenJson['error_description'] ?? $tokenJson['error']) . "\n";
            exit(1);
        }
    }

    echo "⏳ Aguardando...\n";
}
