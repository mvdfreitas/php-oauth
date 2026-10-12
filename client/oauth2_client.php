<?php
/**
 * Cliente OAuth2 Completo - Com todos os parâmetros
 */

session_start();

// ============================================================================
// CONFIGURAÇÕES
// ============================================================================

$oauth_server = 'http://localhost:8000';
$client_id = 'cliente_teste';
$client_secret = 'senha_secreta_123';
$redirect_uri = 'http://localhost:8001/oauth2_client.php';
$jwt_secret = 'segredo_jwt_local_dev_1234567890'; // deve ser igual ao do servidor

// ============================================================================
// FUNÇÕES AUXILIARES
// ============================================================================

function generateRandomString($length = 16) {
    return bin2hex(random_bytes($length));
}

function makeRequest($method, $url, $data = null, $headers = []) {
    $ch = curl_init();
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT => 10,
    ];
    
    if ($method === 'POST') {
        $options[CURLOPT_POST] = 1;
        if (is_array($data)) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($data);
        } else {
            $options[CURLOPT_POSTFIELDS] = $data;
        }
    }
    
    if (!empty($headers)) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }
    
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'status' => $http_code,
        'body' => $response,
        'data' => json_decode($response, true)
    ];
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Valida um id_token JWT (HS256).
 * Retorna ['valid' => bool, 'error' => string|null, 'payload' => array|null]
 */
function validateIdToken($jwt, $expected_nonce = null) {
    global $client_id, $oauth_server, $jwt_secret;

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return ['valid' => false, 'error' => 'Formato JWT inválido', 'payload' => null];
    }

    list($header_b64, $payload_b64, $signature_b64) = $parts;

    // 1. Header: alg deve ser HS256
    $header = json_decode(base64UrlDecode($header_b64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
        return ['valid' => false, 'error' => 'Algoritmo não suportado (esperado HS256)', 'payload' => null];
    }

    // 2. Assinatura (HMAC-SHA256)
    $expected_sig = base64UrlEncode(hash_hmac('sha256', $header_b64 . '.' . $payload_b64, $jwt_secret, true));
    if (!hash_equals($expected_sig, $signature_b64)) {
        return ['valid' => false, 'error' => 'Assinatura inválida', 'payload' => null];
    }

    // 3. Payload
    $payload = json_decode(base64UrlDecode($payload_b64), true);
    if (!is_array($payload)) {
        return ['valid' => false, 'error' => 'Payload inválido', 'payload' => null];
    }

    // 4. exp
    if (!isset($payload['exp']) || $payload['exp'] < time()) {
        return ['valid' => false, 'error' => 'id_token expirado', 'payload' => $payload];
    }

    // 5. iss
    if (($payload['iss'] ?? '') !== $oauth_server) {
        return ['valid' => false, 'error' => 'Issuer (iss) inválido', 'payload' => $payload];
    }

    // 6. aud
    $aud = $payload['aud'] ?? '';
    $aud_list = is_array($aud) ? $aud : [$aud];
    if (!in_array($client_id, $aud_list, true)) {
        return ['valid' => false, 'error' => 'Audience (aud) inválida', 'payload' => $payload];
    }

    // 7. nonce (se esperado)
    if ($expected_nonce !== null && ($payload['nonce'] ?? null) !== $expected_nonce) {
        return ['valid' => false, 'error' => 'Nonce não confere', 'payload' => $payload];
    }

    return ['valid' => true, 'error' => null, 'payload' => $payload];
}

// ============================================================================
// PROCESSAR CALLBACK (Authorize Code Flow)
// ============================================================================

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $state = $_GET['state'] ?? '';
    
    // Validar state (se não confere, ABORTA o fluxo - proteção CSRF)
    if ($state === '' || $state !== ($_SESSION['oauth_state'] ?? '')) {
        $_SESSION['error'] = 'State mismatch - possível ataque CSRF! Fluxo abortado.';
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_nonce']);
        unset($_SESSION['oauth_code_verifier']);
        header('Location: ' . $redirect_uri);
        exit;
    }

    unset($_SESSION['id_token_claims'], $_SESSION['id_token_error']);

    // Trocar code por token
    $token_response = makeRequest('POST', $oauth_server . '/oauth2_complete.php?action=token', [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirect_uri,
        'code_verifier' => $_SESSION['oauth_code_verifier'] ?? '',
    ]);

    if ($token_response['status'] === 200 && isset($token_response['data']['access_token'])) {
        $_SESSION['access_token'] = $token_response['data']['access_token'];
        $_SESSION['token_data'] = $token_response['data'];
        $_SESSION['flow_used'] = 'authorization_code';

        // Validar id_token (JWT HS256) quando presente na resposta do token
        if (!empty($token_response['data']['id_token'])) {
            $validation = validateIdToken($token_response['data']['id_token'], $_SESSION['oauth_nonce'] ?? null);
            if ($validation['valid']) {
                $_SESSION['id_token_claims'] = $validation['payload'];
            } else {
                $_SESSION['id_token_error'] = $validation['error'];
            }
        }

        // Obter dados do usuário
        $userinfo_response = makeRequest(
            'GET',
            $oauth_server . '/oauth2_complete.php?action=userinfo',
            null,
            ['Authorization: Bearer ' . $token_response['data']['access_token']]
        );

        if ($userinfo_response['status'] === 200) {
            $_SESSION['user_data'] = $userinfo_response['data'];
        }
    } else {
        $_SESSION['error'] = 'Erro ao obter token: ' . ($token_response['data']['error'] ?? 'desconhecido');
    }

    // Limpar
    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_nonce']);
    unset($_SESSION['oauth_code_verifier']);

    header('Location: ' . $redirect_uri);
    exit;
}

// ============================================================================
// PROCESSAR CALLBACK COM IMPLICIT FLOW
// ============================================================================
// O implicit flow devolve o token no fragment (#) da URL, que o browser NÃO
// envia ao servidor. O JavaScript no fim desta página lê o fragment e reenvia
// os dados para cá via POST (campo implicit_callback). Só então o PHP consegue
// validar o state e consultar o endpoint /userinfo.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['implicit_callback'])) {
    $access_token = $_POST['access_token'] ?? '';
    $state = $_POST['state'] ?? '';

    // Validar state (se não confere, ABORTA o fluxo - proteção CSRF)
    if ($state === '' || $state !== ($_SESSION['oauth_state'] ?? '')) {
        $_SESSION['error'] = 'State mismatch - possível ataque CSRF! Fluxo implicit abortado.';
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_nonce']);
        header('Location: ' . $redirect_uri);
        exit;
    }

    $_SESSION['access_token'] = $access_token;
    $_SESSION['token_data'] = [
        'access_token' => $access_token,
        'token_type' => $_POST['token_type'] ?? 'Bearer',
        'expires_in' => (int) ($_POST['expires_in'] ?? 3600),
        'scope' => $_POST['scope'] ?? '',
    ];
    $_SESSION['flow_used'] = 'implicit';

    // Validar id_token (JWT HS256) quando presente no fragment
    unset($_SESSION['id_token_claims'], $_SESSION['id_token_error']);
    $id_token = $_POST['id_token'] ?? '';
    if ($id_token !== '') {
        $validation = validateIdToken($id_token, $_SESSION['oauth_nonce'] ?? null);
        if ($validation['valid']) {
            $_SESSION['id_token_claims'] = $validation['payload'];
        } else {
            $_SESSION['id_token_error'] = $validation['error'];
        }
    }

    // Obter dados do usuário
    $userinfo_response = makeRequest(
        'GET',
        $oauth_server . '/oauth2_complete.php?action=userinfo',
        null,
        ['Authorization: Bearer ' . $access_token]
    );

    if ($userinfo_response['status'] === 200) {
        $_SESSION['user_data'] = $userinfo_response['data'];
    }

    unset($_SESSION['oauth_state']);
    unset($_SESSION['oauth_nonce']);
    header('Location: ' . $redirect_uri);
    exit;
}

// ============================================================================
// LOGOUT
// ============================================================================

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $redirect_uri);
    exit;
}

// ============================================================================
// PROCESSAR FORMULÁRIOS
// ============================================================================

$message = '';
$flow_type = $_GET['flow'] ?? null;

// Mensagens de erro gravadas em sessão antes de um redirect de callback
if (!empty($_SESSION['error'])) {
    $message = 'erro|' . $_SESSION['error'];
    unset($_SESSION['error']);
}

// Password Flow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password_flow'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if ($username && $password) {
        $token_response = makeRequest('POST', $oauth_server . '/oauth2_complete.php?action=token', [
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
            'scope' => 'openid profile email',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ]);
        
        if (isset($token_response['data']['access_token'])) {
            $_SESSION['access_token'] = $token_response['data']['access_token'];
            $_SESSION['token_data'] = $token_response['data'];
            $_SESSION['flow_used'] = 'password';
            
            $userinfo_response = makeRequest(
                'GET',
                $oauth_server . '/oauth2_complete.php?action=userinfo',
                null,
                ['Authorization: Bearer ' . $token_response['data']['access_token']]
            );
            
            if ($userinfo_response['status'] === 200) {
                $_SESSION['user_data'] = $userinfo_response['data'];
            }
            
            header('Location: ' . $redirect_uri);
            exit;
        } else {
            $message = 'erro|Erro: ' . ($token_response['data']['error_description'] ?? 'Falha na autenticação');
        }
    } else {
        $message = 'erro|Preencha usuário e senha';
    }
}

// Client Credentials Flow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_flow'])) {
    $token_response = makeRequest('POST', $oauth_server . '/oauth2_complete.php?action=token', [
        'grant_type' => 'client_credentials',
        'scope' => 'openid',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
    ]);
    
    if (isset($token_response['data']['access_token'])) {
        $_SESSION['access_token'] = $token_response['data']['access_token'];
        $_SESSION['token_data'] = $token_response['data'];
        $_SESSION['flow_used'] = 'client_credentials';
        
        $userinfo_response = makeRequest(
            'GET',
            $oauth_server . '/oauth2_complete.php?action=userinfo',
            null,
            ['Authorization: Bearer ' . $token_response['data']['access_token']]
        );
        
        if ($userinfo_response['status'] === 200) {
            $_SESSION['user_data'] = $userinfo_response['data'];
        }
        
        header('Location: ' . $redirect_uri);
        exit;
    } else {
        $message = 'erro|Erro: ' . ($token_response['data']['error'] ?? 'Falha');
    }
}

// Refresh Token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_token'])) {
    if (!isset($_SESSION['token_data']['refresh_token'])) {
        $message = 'erro|Nenhum refresh token disponível';
    } else {
        $token_response = makeRequest('POST', $oauth_server . '/oauth2_complete.php?action=token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $_SESSION['token_data']['refresh_token'],
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ]);
        
        if (isset($token_response['data']['access_token'])) {
            $_SESSION['access_token'] = $token_response['data']['access_token'];
            $_SESSION['token_data'] = $token_response['data'];
            $message = 'sucesso|Token renovado com sucesso!';
        } else {
            $message = 'erro|Erro ao renovar: ' . ($token_response['data']['error'] ?? 'desconhecido');
        }
    }
}

// Revogar Token (RFC 7009)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_token'])) {
    $has_refresh = isset($_SESSION['token_data']['refresh_token']);
    $token_to_revoke = $_SESSION['token_data']['refresh_token'] ?? $_SESSION['access_token'] ?? null;

    if (!$token_to_revoke) {
        $message = 'erro|Nenhum token para revogar';
    } else {
        $revoke_response = makeRequest('POST', $oauth_server . '/oauth2_complete.php?action=revoke', [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'token' => $token_to_revoke,
            'token_type_hint' => $has_refresh ? 'refresh_token' : 'access_token',
        ]);

        if ($revoke_response['status'] === 200) {
            unset(
                $_SESSION['access_token'],
                $_SESSION['token_data'],
                $_SESSION['flow_used'],
                $_SESSION['user_data'],
                $_SESSION['id_token_claims'],
                $_SESSION['id_token_error']
            );
            $message = 'sucesso|Token revogado com sucesso!';
        } else {
            $message = 'erro|Erro ao revogar: ' . ($revoke_response['data']['error'] ?? 'desconhecido');
        }
    }
}

list($msg_type, $msg_text) = $message ? explode('|', $message, 2) : ['', ''];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cliente OAuth2 Completo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 1rem;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        header h1 {
            margin-bottom: 0.5rem;
            font-size: 2rem;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 1.3rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }
        
        .card h3 {
            color: #667eea;
            margin-top: 1rem;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        
        input[type="text"],
        input[type="password"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.95rem;
            font-family: inherit;
        }
        
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        button {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            font-size: 0.95rem;
        }
        
        button:hover {
            background: #5568d3;
        }
        
        button.secondary {
            background: #6c757d;
        }
        
        button.secondary:hover {
            background: #5a6268;
        }
        
        button.danger {
            background: #dc3545;
        }
        
        button.danger:hover {
            background: #c82333;
        }
        
        button.small {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        
        .button-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .button-group button {
            flex: 1;
            min-width: 150px;
        }
        
        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }
        
        .message.sucesso {
            background: #d4edda;
            color: #155724;
            border-color: #28a745;
        }
        
        .message.erro {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }
        
        .info-box {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 4px;
            border-left: 4px solid #2196f3;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #1565c0;
        }
        
        .token-display {
            background: #f5f5f5;
            padding: 1rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.8rem;
            margin: 0.5rem 0;
            overflow-x: auto;
            word-break: break-all;
            max-height: 100px;
            overflow-y: auto;
        }
        
        .user-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .user-card h2 {
            color: white;
            border-bottom-color: rgba(255,255,255,0.3);
        }
        
        .user-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .user-info-item {
            background: rgba(255,255,255,0.1);
            padding: 1rem;
            border-radius: 4px;
        }
        
        .user-info-item strong {
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .user-info-item code {
            background: rgba(0,0,0,0.2);
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            word-break: break-all;
        }
        
        .flow-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .flow-button {
            padding: 1rem;
            text-align: center;
            font-weight: 600;
            border: 2px solid #ddd;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: #333;
            display: block;
        }
        
        .flow-button:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }
        
        .flow-button.active {
            border-color: #667eea;
            background: #667eea;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #ddd;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 1rem;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: #666;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .tab:hover {
            color: #667eea;
        }
        
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .alert-info {
            background: #cfe2ff;
            border-left: 4px solid #0c63e4;
            color: #084298;
        }
        
        .credentials-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        .credentials-table td {
            padding: 0.5rem;
            border-bottom: 1px solid #ddd;
        }
        
        .credentials-table code {
            background: #f5f5f5;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .flow-selector {
                grid-template-columns: 1fr;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .button-group button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔐 Cliente OAuth2 Completo</h1>
            <p>Teste todos os Grant Types e Parâmetros</p>
        </header>
        
        <div id="implicit-message" class="message" style="display: none;"></div>
        
        <?php if ($msg_text): ?>
            <div class="message <?php echo htmlspecialchars($msg_type); ?>">
                <?php echo htmlspecialchars($msg_text); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['user_data'])): ?>
            <!-- USUÁRIO AUTENTICADO -->
            
            <div class="user-card">
                <h2>✨ Bem-vindo, <?php echo htmlspecialchars($_SESSION['user_data']['name'] ?? $_SESSION['user_data']['sub']); ?>!</h2>
                
                <div class="user-info">
                    <div class="user-info-item">
                        <strong>👤 Usuário:</strong>
                        <code><?php echo htmlspecialchars($_SESSION['user_data']['sub']); ?></code>
                    </div>
                    
                    <?php if (isset($_SESSION['user_data']['email'])): ?>
                        <div class="user-info-item">
                            <strong>📧 Email:</strong>
                            <code><?php echo htmlspecialchars($_SESSION['user_data']['email']); ?></code>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['user_data']['phone_number'])): ?>
                        <div class="user-info-item">
                            <strong>📱 Telefone:</strong>
                            <code><?php echo htmlspecialchars($_SESSION['user_data']['phone_number']); ?></code>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['user_data']['address'])): ?>
                        <div class="user-info-item">
                            <strong>📍 Endereço:</strong>
                            <code><?php echo htmlspecialchars($_SESSION['user_data']['address']); ?></code>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="grid">
                <div class="card">
                    <h2>🔑 Token de Acesso</h2>
                    <div class="info-box">
                        <strong>Status:</strong> Ativo por <?php echo htmlspecialchars($_SESSION['token_data']['expires_in']); ?> segundos
                    </div>
                    
                    <h3>Token:</h3>
                    <div class="token-display">
                        <?php echo htmlspecialchars($_SESSION['access_token']); ?>
                    </div>
                    
                    <h3>Detalhes:</h3>
                    <div class="token-display">
                        Type: <?php echo htmlspecialchars($_SESSION['token_data']['token_type']); ?><br>
                        Expires: <?php echo htmlspecialchars($_SESSION['token_data']['expires_in']); ?>s<br>
                        Scope: <?php echo htmlspecialchars($_SESSION['token_data']['scope']); ?>
                    </div>
                    
                    <?php if (isset($_SESSION['token_data']['refresh_token'])): ?>
                        <h3>Refresh Token:</h3>
                        <div class="token-display">
                            <?php echo htmlspecialchars(substr($_SESSION['token_data']['refresh_token'], 0, 32)); ?>...
                        </div>
                    <?php endif; ?>
                    
                    <h3>Flow usado:</h3>
                    <div class="token-display">
                        <?php echo htmlspecialchars($_SESSION['flow_used'] ?? 'desconhecido'); ?>
                    </div>
                    
                    <?php if (isset($_SESSION['id_token_claims'])): ?>
                        <h3>ID Token (JWT validado ✅):</h3>
                        <div class="token-display">
                            <?php echo htmlspecialchars(json_encode($_SESSION['id_token_claims'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?>
                        </div>
                    <?php elseif (isset($_SESSION['id_token_error'])): ?>
                        <h3>ID Token (inválido ❌):</h3>
                        <div class="token-display">
                            <?php echo htmlspecialchars($_SESSION['id_token_error']); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <h2>⚙️ Ações</h2>
                    
                    <?php if (isset($_SESSION['token_data']['refresh_token'])): ?>
                        <form method="POST">
                            <button type="submit" name="refresh_token" value="1">
                                🔄 Renovar Token
                            </button>
                        </form>
                        <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">
                            Obter novo access_token usando refresh_token
                        </p>
                    <?php endif; ?>
                    
                    <form method="POST" style="margin-top: 1rem;">
                        <button type="submit" name="revoke_token" value="1" class="danger" style="width: 100%;">
                            🗑️ Revogar Token
                        </button>
                    </form>
                    <p style="font-size: 0.85rem; color: #666; margin-top: 0.5rem;">
                        Revoga o token no servidor (endpoint <code>revoke</code>)
                    </p>
                    
                    <hr style="margin: 1rem 0; border: none; border-top: 1px solid #ddd;">
                    
                    <form method="GET">
                        <button type="submit" name="logout" value="1" class="danger" style="width: 100%;">
                            🚪 Fazer Logout
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <h2>📊 Dados Completos (JSON)</h2>
                <div class="token-display" style="max-height: 200px;">
                    <?php echo htmlspecialchars(json_encode($_SESSION['user_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?>
                </div>
            </div>
            
        <?php else: ?>
            <!-- NÃO AUTENTICADO - MOSTRA OPÇÕES DE LOGIN -->
            
            <div class="alert alert-info">
                <strong>ℹ️ Escolha um método de autenticação:</strong> Este cliente demonstra os 4 principais Grant Types do OAuth2.
            </div>
            
            <div class="flow-selector">
                <a href="?flow=code" class="flow-button <?php echo $flow_type === 'code' ? 'active' : ''; ?>">
                    📝 Code Flow<br>
                    <small>Mais seguro</small>
                </a>
                <a href="?flow=implicit" class="flow-button <?php echo $flow_type === 'implicit' ? 'active' : ''; ?>">
                    ⚡ Implicit Flow<br>
                    <small>Para SPAs</small>
                </a>
                <a href="?flow=password" class="flow-button <?php echo $flow_type === 'password' ? 'active' : ''; ?>">
                    🔐 Password Flow<br>
                    <small>App nativa</small>
                </a>
                <a href="?flow=client" class="flow-button <?php echo $flow_type === 'client' ? 'active' : ''; ?>">
                    🔧 Client Flow<br>
                    <small>Backend</small>
                </a>
                <a href="?flow=device" class="flow-button <?php echo $flow_type === 'device' ? 'active' : ''; ?>">
                    📺 Device Flow<br>
                    <small>CLI / TV</small>
                </a>
            </div>
            
            <!-- AUTHORIZATION CODE FLOW -->
            <?php if ($flow_type === 'code' || $flow_type === null): ?>
                <div class="card">
                    <h2>📝 Authorization Code Flow (Recomendado)</h2>
                    
                    <div class="info-box">
                        <strong>Como funciona:</strong>
                        <ol style="margin-left: 1.5rem; margin-top: 0.5rem;">
                            <li>Você clica em "Login"</li>
                            <li>É redirecionado para o servidor OAuth2</li>
                            <li>Faz login com suas credenciais</li>
                            <li>Concede permissões (consentimento)</li>
                            <li>Recebe um authorization code</li>
                            <li>O backend troca code por access_token</li>
                            <li>Você fica autenticado</li>
                        </ol>
                    </div>
                    
                    <form method="GET" action="">
                        <input type="hidden" name="flow" value="code">
                        
                        <div class="form-group">
                            <label>🎯 Parâmetros Customizáveis:</label>
                            <div style="background: #f5f5f5; padding: 1rem; border-radius: 4px; margin-top: 0.5rem;">
                                <label style="margin-bottom: 0.5rem;">
                                    <input type="checkbox" name="use_nonce" value="1" checked>
                                    Usar NONCE (OpenID Connect)
                                </label><br>
                                <label style="margin-bottom: 0.5rem;">
                                    <input type="checkbox" name="use_state" value="1" checked>
                                    Usar STATE (Proteção CSRF)
                                </label><br>
                                <label style="margin-bottom: 0.5rem;">
                                    <input type="checkbox" name="ask_consent" value="1" checked>
                                    Pedir Consentimento
                                </label><br>
                                <label>
                                    <input type="checkbox" name="prompt_none" value="1">
                                    prompt=none (sem interação; exige consentimento prévio)
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" name="auth_code" value="1" style="width: 100%;">
                            🔐 Fazer Login com Authorization Code
                        </button>
                    </form>
                    
                    <?php
                    // Processar login com Authorization Code
                    if (isset($_GET['auth_code'])) {
                        $state = generateRandomString();
                        $nonce = generateRandomString();

                        // PKCE (RFC 7636): verifier aleatório e challenge S256
                        $code_verifier = base64UrlEncode(random_bytes(32));
                        $code_challenge = base64UrlEncode(hash('sha256', $code_verifier, true));

                        $_SESSION['oauth_state'] = $state;
                        $_SESSION['oauth_nonce'] = $nonce;
                        $_SESSION['oauth_code_verifier'] = $code_verifier;

                        $params = [
                            'client_id' => $client_id,
                            'redirect_uri' => $redirect_uri,
                            'response_type' => 'code',
                            'scope' => 'openid profile email phone address',
                            'state' => $state,
                            'code_challenge' => $code_challenge,
                            'code_challenge_method' => 'S256',
                        ];
                        
                        if ($_GET['use_nonce'] ?? false) {
                            $params['nonce'] = $nonce;
                        }

                        if ($_GET['prompt_none'] ?? false) {
                            $params['prompt'] = 'none';
                        } elseif ($_GET['ask_consent'] ?? false) {
                            $params['prompt'] = 'consent';
                        }
                        
                        $auth_url = $oauth_server . '/oauth2_complete.php?action=authorize&' . http_build_query($params);
                        
                        echo '<script>window.location.href = ' . json_encode($auth_url) . ';</script>';
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <!-- IMPLICIT FLOW -->
            <?php if ($flow_type === 'implicit'): ?>
                <div class="card">
                    <h2>⚡ Implicit Flow (para SPAs)</h2>
                    
                    <div class="info-box">
                        <strong>Como funciona:</strong> O token é retornado diretamente na URL (fragment). Mais rápido mas menos seguro.
                    </div>
                    
                    <form method="GET" action="">
                        <input type="hidden" name="flow" value="implicit">
                        
                        <div class="form-group">
                            <label style="margin-bottom: 0.5rem;">
                                <input type="checkbox" name="use_nonce" value="1" checked>
                                Usar NONCE e id_token assinado (response_type=id_token token)
                            </label>
                        </div>
                        
                        <button type="submit" name="implicit_auth" value="1" style="width: 100%;">
                            ⚡ Fazer Login com Implicit Flow
                        </button>
                    </form>
                    
                    <?php
                    if (isset($_GET['implicit_auth'])) {
                        $state = generateRandomString();
                        $_SESSION['oauth_state'] = $state;
                        
                        $params = [
                            'client_id' => $client_id,
                            'redirect_uri' => $redirect_uri,
                            'response_type' => 'token',
                            'scope' => 'openid profile email',
                            'state' => $state,
                        ];
                        
                        // Com nonce, pede também o id_token assinado (OIDC)
                        if ($_GET['use_nonce'] ?? false) {
                            $nonce = generateRandomString();
                            $_SESSION['oauth_nonce'] = $nonce;
                            $params['nonce'] = $nonce;
                            $params['response_type'] = 'id_token token';
                        } else {
                            unset($_SESSION['oauth_nonce']);
                        }
                        
                        $auth_url = $oauth_server . '/oauth2_complete.php?action=authorize&' . http_build_query($params);
                        
                        echo '<script>window.location.href = ' . json_encode($auth_url) . ';</script>';
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <!-- PASSWORD FLOW -->
            <?php if ($flow_type === 'password'): ?>
                <div class="card">
                    <h2>🔐 Password Flow (App Nativa/Desktop)</h2>
                    
                    <div class="alert" style="background: #fff3cd; border-left-color: #ffc107; color: #664d03;">
                        <strong>⚠️ Aviso:</strong> Este flow requer que você digite sua senha. Use apenas em apps que você confia!
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="password_flow" value="1">
                        
                        <div class="form-group">
                            <label for="username">👤 Usuário:</label>
                            <input type="text" id="username" name="username" placeholder="usuario1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">🔑 Senha:</label>
                            <input type="password" id="password" name="password" placeholder="senha123" required>
                        </div>
                        
                        <button type="submit" style="width: 100%;">
                            ✓ Login com Senha
                        </button>
                    </form>
                    
                    <div class="info-box" style="margin-top: 1rem;">
                        <strong>Credenciais de teste:</strong><br>
                        usuario1 / senha123<br>
                        usuario2 / senha456
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- CLIENT CREDENTIALS FLOW -->
            <?php if ($flow_type === 'client'): ?>
                <div class="card">
                    <h2>🔧 Client Credentials Flow (Backend)</h2>
                    
                    <div class="info-box">
                        <strong>Como funciona:</strong> Autenticação direta entre aplicações, sem usuário final.
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="client_flow" value="1">
                        
                        <button type="submit" style="width: 100%;">
                            🔧 Autenticar como Cliente
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- DEVICE FLOW -->
            <?php if ($flow_type === 'device'): ?>
                <div class="card">
                    <h2>📺 Device Authorization Grant (RFC 8628)</h2>
                    
                    <div class="info-box">
                        <strong>Como funciona:</strong>
                        <ol style="margin-left: 1.5rem; margin-top: 0.5rem;">
                            <li>O dispositivo solicita um código (device_code + user_code)</li>
                            <li>Exibe instruções para o usuário acessar no navegador</li>
                            <li>Usuário autentica e autoriza no navegador</li>
                            <li>Dispositivo faz polling até receber o token</li>
                        </ol>
                    </div>
                    
                    <div id="device-step1">
                        <button type="button" onclick="startDeviceFlow()" style="width: 100%;">
                            🚀 Iniciar Device Flow
                        </button>
                    </div>
                    
                    <div id="device-step2" style="display: none;">
                        <h3>Passo 1: Acesse e digite o código</h3>
                        <div style="background: #f0f4ff; padding: 1.5rem; border-radius: 8px; margin: 1rem 0; text-align: center; border: 2px dashed #667eea;">
                            <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">Acesse este URL no seu navegador:</div>
                            <div id="verification-uri" style="font-size: 0.85rem; color: #667eea; word-break: break-all; margin-bottom: 1rem; font-family: monospace; background: white; padding: 0.5rem; border-radius: 4px;"></div>
                            <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">Ou use o código completo:</div>
                            <div id="verification-uri-complete" style="font-size: 0.85rem; color: #667eea; word-break: break-all; margin-bottom: 1rem; font-family: monospace; background: white; padding: 0.5rem; border-radius: 4px;"></div>
                            <div style="font-size: 1.2rem; color: #333; margin-top: 1rem;">Código do Usuário:</div>
                            <div id="user-code" style="font-size: 2rem; font-weight: bold; letter-spacing: 4px; color: #667eea; margin: 0.5rem 0; font-family: monospace; background: white; padding: 1rem; border-radius: 8px; border: 2px solid #667eea;"></div>
                        </div>
                        
                        <h3>Passo 2: Aguardando autorização...</h3>
                        <div style="background: #f5f5f5; padding: 1rem; border-radius: 8px; margin: 1rem 0; text-align: center;">
                            <div id="polling-status" style="color: #666; margin-bottom: 0.5rem;">Fazendo polling a cada <span id="interval">5</span> segundos...</div>
                            <div style="font-size: 0.85rem; color: #999;">Status: <span id="auth-status">authorization_pending</span></div>
                        </div>
                        
                        <div id="device-success" style="display: none; background: #d4edda; color: #155724; padding: 1rem; border-radius: 8px; margin: 1rem 0; text-align: center; border-left: 4px solid #28a745;">
                            ✓ Autorizado com sucesso! Redirecionando...
                        </div>
                        
                        <div id="device-error" style="display: none; background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #f5c6cb;"></div>
                    </div>
                </div>
                
                <script>
                let deviceCode = null;
                let interval = 5;
                let pollingTimer = null;
                
                function startDeviceFlow() {
                    document.getElementById('device-step1').style.display = 'none';
                    document.getElementById('device-step2').style.display = 'block';
                    
                    fetch('<?php echo $oauth_server; ?>/oauth2_complete.php?action=device_code', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'client_id=<?php echo urlencode($client_id); ?>&scope=openid%20profile%20email'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            showDeviceError(data.error_description || data.error);
                            return;
                        }
                        deviceCode = data.device_code;
                        interval = data.interval || 5;
                        document.getElementById('verification-uri').textContent = data.verification_uri;
                        document.getElementById('verification-uri-complete').textContent = data.verification_uri_complete;
                        document.getElementById('user-code').textContent = data.user_code;
                        document.getElementById('interval').textContent = interval;
                        startPolling();
                    })
                    .catch(err => {
                        showDeviceError('Erro ao iniciar Device Flow: ' + err);
                    });
                }
                
                function startPolling() {
                    if (pollingTimer) clearInterval(pollingTimer);
                    pollingTimer = setInterval(poll, interval * 1000);
                    poll();
                }
                
                function poll() {
                    if (!deviceCode) return;
                    
                    const formData = new URLSearchParams();
                    formData.append('client_id', '<?php echo $client_id; ?>');
                    formData.append('client_secret', '<?php echo $client_secret; ?>');
                    formData.append('grant_type', 'urn:ietf:params:oauth:grant-type:device_code');
                    formData.append('device_code', deviceCode);
                    
                    fetch('<?php echo $oauth_server; ?>/oauth2_complete.php?action=token', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.access_token) {
                            clearInterval(pollingTimer);
                            document.getElementById('device-success').style.display = 'block';
                            document.getElementById('polling-status').style.display = 'none';
                            setTimeout(() => {
                                window.location.href = '<?php echo $redirect_uri; ?>';
                            }, 1000);
                        } else if (data.error === 'authorization_pending') {
                            document.getElementById('auth-status').textContent = 'authorization_pending';
                        } else if (data.error === 'slow_down') {
                            interval += 5;
                            document.getElementById('interval').textContent = interval;
                            document.getElementById('auth-status').textContent = 'slow_down (intervalo aumentado)';
                            startPolling();
                        } else if (data.error === 'expired_token') {
                            clearInterval(pollingTimer);
                            showDeviceError('Código expirado. Reinicie o Device Flow.');
                        } else if (data.error === 'access_denied') {
                            clearInterval(pollingTimer);
                            showDeviceError('Acesso negado pelo usuário.');
                        } else {
                            document.getElementById('auth-status').textContent = data.error || 'erro';
                        }
                    })
                    .catch(err => {
                        console.error('Polling error:', err);
                    });
                }
                
                function showDeviceError(msg) {
                    const el = document.getElementById('device-error');
                    el.textContent = msg;
                    el.style.display = 'block';
                }
                </script>
            <?php endif; ?>
            
            <!-- INFORMAÇÕES E CREDENCIAIS -->
            <div class="grid">
                <div class="card">
                    <h2>📋 Credenciais de Teste</h2>
                    <table class="credentials-table">
                        <tr>
                            <td><strong>Usuário 1:</strong></td>
                            <td><code>usuario1</code> / <code>senha123</code></td>
                        </tr>
                        <tr>
                            <td><strong>Usuário 2:</strong></td>
                            <td><code>usuario2</code> / <code>senha456</code></td>
                        </tr>
                        <tr>
                            <td><strong>Client ID:</strong></td>
                            <td><code>cliente_teste</code></td>
                        </tr>
                        <tr>
                            <td><strong>Client Secret:</strong></td>
                            <td><code>senha_secreta_123</code></td>
                        </tr>
                    </table>
                </div>
                
                <div class="card">
                    <h2>🔗 Links Úteis</h2>
                    <p style="margin-bottom: 1rem;">
                        <a href="http://localhost:8000/oauth2_complete.php?action=debug" target="_blank" style="color: #667eea; text-decoration: none; font-weight: 600;">
                            🐛 Ver página de Debug do Servidor
                        </a>
                    </p>
                    <p style="margin-bottom: 1rem;">
                        <a href="http://localhost:8000/oauth2_complete.php?action=well-known" target="_blank" style="color: #667eea; text-decoration: none; font-weight: 600;">
                            📄 Ver endpoint Well-Known
                        </a>
                    </p>
                    <p>
                        <strong>Server:</strong> http://localhost:8000<br>
                        <strong>Client:</strong> http://localhost:8001
                    </p>
                </div>
            </div>
            
        <?php endif; ?>
    </div>
    
    <script>
    // Implicit Flow: o token chega no fragment (#) da URL, que o servidor não recebe.
    // Lemos o fragment no browser, validamos o state e reenviamos via POST para o PHP.
    (function () {
        var hash = window.location.hash;
        if (!hash || hash.length < 2) {
            return;
        }

        var params = new URLSearchParams(hash.substring(1));
        var accessToken = params.get('access_token');
        var idToken = params.get('id_token');
        if (!accessToken && !idToken) {
            return;
        }

        // Limpa o fragment da URL/histórico antes de qualquer coisa (evita vazamento do token)
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, document.title, window.location.pathname + window.location.search);
        }

        var expectedState = <?php echo json_encode($_SESSION['oauth_state'] ?? null); ?>;
        var state = params.get('state') || '';

        if (!expectedState || state !== expectedState) {
            var el = document.getElementById('implicit-message');
            if (el) {
                el.className = 'message erro';
                el.textContent = 'State mismatch - possível ataque CSRF! Fluxo implicit abortado.';
                el.style.display = 'block';
            }
            return;
        }

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.pathname + window.location.search;
        form.style.display = 'none';

        function addField(name, value) {
            if (value === null || value === undefined) {
                return;
            }
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }

        addField('implicit_callback', '1');
        addField('access_token', accessToken);
        addField('id_token', idToken);
        addField('token_type', params.get('token_type'));
        addField('expires_in', params.get('expires_in'));
        addField('scope', params.get('scope'));
        addField('state', state);

        document.body.appendChild(form);
        form.submit();
    })();
    </script>
</body>
</html>
