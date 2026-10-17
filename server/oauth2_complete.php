<?php
/**
 * Servidor OAuth2 Completo em PHP Puro
 * Com suporte a vários parâmetros e funcionalidades
 */

session_start();

// ============================================================================
// CONFIGURAÇÕES
// ============================================================================

$config = [
    'base_url' => 'http://localhost:8000',
    'access_token_expires' => 3600,      // 1 hora
    'refresh_token_expires' => 2592000,  // 30 dias
    'code_expires' => 600,               // 10 minutos
    'device_code_expires' => 600,        // 10 minutos (RFC 8628)
    'device_polling_interval' => 5,      // 5 segundos (RFC 8628)
    'jwt_secret' => 'segredo_jwt_local_dev_1234567890', // HS256 (apenas para estudo)
];

// ============================================================================
// BANCO DE DADOS SIMULADO
// ============================================================================

$users = [
    'usuario1' => [
        'password' => 'senha123',
        'email' => 'usuario1@example.com',
        'name' => 'Usuário Um',
        'phone_number' => '+55 11 98765-4321',
        'address' => 'Rua A, 123',
        'city' => 'São Paulo',
        'country' => 'Brasil',
        'picture' => 'https://via.placeholder.com/150',
        'locale' => 'pt_BR',
        'email_verified' => true,
        'phone_number_verified' => false,
    ],
    'usuario2' => [
        'password' => 'senha456',
        'email' => 'usuario2@example.com',
        'name' => 'Usuário Dois',
        'phone_number' => '+55 21 99876-5432',
        'address' => 'Avenida B, 456',
        'city' => 'Rio de Janeiro',
        'country' => 'Brasil',
        'picture' => 'https://via.placeholder.com/150',
        'locale' => 'pt_BR',
        'email_verified' => true,
        'phone_number_verified' => true,
    ],
];

$clients = [
    'cliente_teste' => [
        'client_id' => 'cliente_teste',
        'client_secret' => 'senha_secreta_123',
        'client_name' => 'Aplicação de Teste',
        'redirect_uris' => [
            'http://localhost:8001/oauth2_client.php',
            'http://localhost:8001/index.php',
            'http://localhost:8001/callback.php',
            'http://localhost:3000/callback',
        ],
        'response_types' => ['code', 'token', 'id_token', 'id_token token'],
    'grant_types' => ['authorization_code', 'refresh_token', 'implicit', 'urn:ietf:params:oauth:grant-type:device_code', 'device_code', 'urn:ietf:params:oauth:grant-type:jwt-bearer'],
    'token_endpoint_auth_method' => 'client_secret_basic',
    'logo_uri' => 'https://via.placeholder.com/200',
    'contacts' => ['admin@example.com'],
],
    'api_recursos' => [
        'client_id' => 'api_recursos',
        'client_secret' => 'segredo_api_456',
        'client_name' => 'API de Recursos (Resource Server)',
        'redirect_uris' => [],
        'response_types' => [],
        'grant_types' => [],          // não obtém tokens, apenas introspecta
        'token_endpoint_auth_method' => 'client_secret_basic',
    ],
    // Cliente público (PKCE sem secret) — OAuth 2.1 / RFC 9700
    'spa_publica' => [
        'client_id' => 'spa_publica',
        'client_secret' => null,      // cliente público não possui segredo
        'public' => true,
        'client_name' => 'SPA Pública (React/Angular)',
        'redirect_uris' => [
            'http://localhost:3000/callback',
            'http://localhost:8001/oauth2_client.php',
        ],
        'response_types' => ['code'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
    ],
];

// Escopos disponíveis e suas claims
$scopes_definition = [
    'openid' => [
        'description' => 'Acesso básico',
        'claims' => ['sub'],
    ],
    'profile' => [
        'description' => 'Informações de perfil',
        'claims' => ['name', 'picture', 'locale', 'family_name', 'given_name'],
    ],
    'email' => [
        'description' => 'Acesso ao email',
        'claims' => ['email', 'email_verified'],
    ],
    'address' => [
        'description' => 'Acesso ao endereço',
        'claims' => ['address'],
    ],
    'phone' => [
        'description' => 'Acesso ao telefone',
        'claims' => ['phone_number', 'phone_number_verified'],
    ],
];

// ============================================================================
// FUNÇÕES UTILITÁRIAS
// ============================================================================

function getStoragePath() {
    return sys_get_temp_dir() . '/oauth2_storage_' . getenv('USER') . '.json';
}

function loadStorage() {
    $path = getStoragePath();
    if (file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        return $data ?: [];
    }
    return [];
}

function saveStorage($data) {
    $path = getStoragePath();
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

function generateCode() {
    return bin2hex(random_bytes(16));
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function generateDeviceCode() {
    return bin2hex(random_bytes(16));
}

function generateUserCode() {
    // Gera código no formato XXXX-XXXX (8 caracteres alfanuméricos legíveis)
    // Evita caracteres ambíguos (0, O, I, 1) para melhor legibilidade
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return substr($code, 0, 4) . '-' . substr($code, 4, 4);
}

function createAccessToken(array &$storage, $client_id, $username, $scope, $expires_in = 3600) {
    $access_token = generateToken();

    $storage['access_tokens'] = $storage['access_tokens'] ?? [];
    $storage['access_tokens'][$access_token] = [
        'client_id' => $client_id,
        'username' => $username,
        'scope' => $scope,
        'created_at' => time(),
        'expires_at' => time() + $expires_in,
    ];

    return $access_token;
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode($data) {
    $padding = strlen($data) % 4;
    if ($padding) {
        $data .= str_repeat('=', 4 - $padding);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function generateIdToken($username, $client_id, $nonce = null) {
    global $users, $config;
    
    $user = $users[$username] ?? [];
    
    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256',
    ];
    
    $now = time();
    $payload = [
        'iss' => $config['base_url'],
        'sub' => $username,
        'aud' => $client_id,
        'exp' => $now + $config['access_token_expires'],
        'iat' => $now,
        'auth_time' => $now,
    ];
    
    if ($nonce) {
        $payload['nonce'] = $nonce;
    }
    
    // JWT HS256 real: header.payload.signature
    $segments = [
        base64UrlEncode(json_encode($header)),
        base64UrlEncode(json_encode($payload)),
    ];
    $signing_input = implode('.', $segments);
    $signature = base64UrlEncode(hash_hmac('sha256', $signing_input, $config['jwt_secret'], true));
    
    return $signing_input . '.' . $signature;
}

function signJwt($data, $client_id, $algorithm = 'HS256') {
    global $clients, $config;
    $client = $clients[$client_id] ?? null;
    if (!$client) {
        return '';
    }

    if ($algorithm === 'HS256') {
        $key = $client['client_secret'] ?? $config['jwt_secret'] ?? '';
        return hash_hmac('sha256', $data, $key, true);
    } elseif ($algorithm === 'RS256') {
        $privateKey = $client['jwt_private_key'] ?? null;
        if (!$privateKey) {
            return '';
        }
        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        return $signature;
    }
    return '';
}

function verifyJwtSignature($data, $signature, $client_id, $algorithm) {
    global $clients, $config;
    $client = $clients[$client_id] ?? null;
    if (!$client) {
        return false;
    }

    if ($algorithm === 'HS256') {
        $key = $client['client_secret'] ?? $config['jwt_secret'] ?? '';
        $expected = hash_hmac('sha256', $data, $key, true);
        return hash_equals($expected, $signature);
    } elseif ($algorithm === 'RS256') {
        $publicKey = $client['jwt_public_key'] ?? null;
        if (!$publicKey) {
            return false;
        }
        return openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
    return false;
}

function verifyJwt($jwt, $client_id = null) {
    global $clients;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return ['valid' => false, 'error' => 'invalid_format'];
    }

    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

    $header = json_decode(base64UrlDecode($headerEncoded), true);
    if (!$header || !isset($header['alg'])) {
        return ['valid' => false, 'error' => 'invalid_header'];
    }

    $algorithm = $header['alg'];
    if ($algorithm === 'none') {
        return ['valid' => false, 'error' => 'invalid_algorithm'];
    }

    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    if (!$payload) {
        return ['valid' => false, 'error' => 'invalid_payload'];
    }

    if ($client_id === null && isset($payload['iss'])) {
        $client_id = $payload['iss'];
    }

    if (!$client_id || !isset($clients[$client_id])) {
        return ['valid' => false, 'error' => 'invalid_issuer'];
    }

    $data = $headerEncoded . '.' . $payloadEncoded;
    $signature = base64UrlDecode($signatureEncoded);

    if (!verifyJwtSignature($data, $signature, $client_id, $algorithm)) {
        return ['valid' => false, 'error' => 'invalid_signature'];
    }

    $now = time();
    if (isset($payload['exp']) && $payload['exp'] < $now) {
        return ['valid' => false, 'error' => 'token_expired'];
    }
    if (isset($payload['nbf']) && $payload['nbf'] > $now) {
        return ['valid' => false, 'error' => 'token_not_yet_valid'];
    }
    if (isset($payload['iat']) && $payload['iat'] > $now + 60) {
        return ['valid' => false, 'error' => 'invalid_issued_at'];
    }

    return ['valid' => true, 'payload' => $payload, 'client_id' => $client_id];
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Devolve um erro OAuth2 ao cliente via redirect (com state), usando '#' quando
 * a resposta usa fragment (implicit) e '?' no authorization code flow.
 */
function redirectWithError($redirect_uri, $error, $description, $state, $response_type = 'code') {
    $params = ['error' => $error];
    if ($description !== '') {
        $params['error_description'] = $description;
    }
    if ($state !== null && $state !== '') {
        $params['state'] = $state;
    }

    $is_fragment = in_array($response_type, ['token', 'id_token', 'id_token token'], true);
    redirect($redirect_uri . ($is_fragment ? '#' : '?') . http_build_query($params));
}

/**
 * Indica se o cliente é público (sem client_secret) — OAuth 2.1 / RFC 9700.
 * Um cliente é público quando marcado com 'public' => true ou com 'client_secret' => null.
 */
function isPublicClient($client_id) {
    global $clients;

    if (!isset($clients[$client_id])) {
        return false;
    }

    $client = $clients[$client_id];

    if (!empty($client['public'])) {
        return true;
    }

    return ($client['client_secret'] ?? null) === null;
}

/**
 * Verifica um code_verifier contra o code_challenge armazenado (RFC 7636).
 * Retorna true quando o código não usou PKCE.
 */
function verifyPkce($verifier, $challenge, $method) {
    if ($challenge === null || $challenge === '') {
        return true;
    }

    if ($verifier === null || $verifier === '') {
        return false;
    }

    if ($method === 'plain') {
        return hash_equals($challenge, $verifier);
    }

    $computed = base64UrlEncode(hash('sha256', $verifier, true));

    return hash_equals($challenge, $computed);
}

function consentIsGranted($client_id, array $requested_scopes) {
    $granted = $_SESSION['oauth_consent'][$client_id] ?? [];

    return array_diff($requested_scopes, $granted) === [];
}

function grantConsent($client_id, array $scopes) {
    $current = $_SESSION['oauth_consent'][$client_id] ?? [];
    $_SESSION['oauth_consent'][$client_id] = array_values(array_unique(array_merge($current, $scopes)));
}

/**
 * Emite a resposta final da autorização: tokens (implicit) ou authorization code.
 */
function completeAuthorization(array $authParams, $username) {
    global $config;

    $client_id = $authParams['client_id'];
    $redirect_uri = $authParams['redirect_uri'];
    $scope = $authParams['scope'];
    $state = $authParams['state'];
    $nonce = $authParams['nonce'];
    $response_type = $authParams['response_type'];

    $storage = loadStorage();

    // Implicit Flow: tokens retornados diretamente no fragment (#), sem token exchange
    if (in_array($response_type, ['token', 'id_token', 'id_token token'], true)) {
        $response_params = ['state' => $state];

        if ($response_type === 'token' || $response_type === 'id_token token') {
            $response_params['access_token'] = createAccessToken($storage, $client_id, $username, $scope);
            $response_params['token_type'] = 'Bearer';
            $response_params['expires_in'] = $config['access_token_expires'];
            $response_params['scope'] = $scope;
        }

        if ($response_type === 'id_token' || $response_type === 'id_token token') {
            $response_params['id_token'] = generateIdToken($username, $client_id, $nonce);
        }

        saveStorage($storage);

        redirect($redirect_uri . '#' . http_build_query($response_params));
    }

    // Authorization Code Flow: gerar code de uso único
    $storage['auth_codes'] = $storage['auth_codes'] ?? [];

    $code = generateCode();
    $storage['auth_codes'][$code] = [
        'client_id' => $client_id,
        'username' => $username,
        'scope' => $scope,
        'redirect_uri' => $redirect_uri,
        'nonce' => $nonce,
        'response_type' => $response_type,
        'code_challenge' => $authParams['code_challenge'] ?? null,
        'code_challenge_method' => $authParams['code_challenge_method'] ?? null,
        'created_at' => time(),
        'expires_at' => time() + $config['code_expires'],
    ];
    saveStorage($storage);

    redirect($redirect_uri . '?' . http_build_query([
        'code' => $code,
        'state' => $state,
    ]));
}

function parseScopes($scope_string) {
    if (!$scope_string) return [];
    return array_filter(explode(' ', trim($scope_string)));
}

function validateScopes($requested_scopes, $allowed_scopes) {
    foreach ($requested_scopes as $scope) {
        if (!in_array($scope, $allowed_scopes)) {
            return false;
        }
    }
    return true;
}

function getClaimsForScopes($scopes) {
    global $scopes_definition;
    $claims = [];
    
    foreach ($scopes as $scope) {
        if (isset($scopes_definition[$scope])) {
            $claims = array_merge($claims, $scopes_definition[$scope]['claims']);
        }
    }
    
    return array_unique($claims);
}

function filterUserDataByClaims($user, $claims) {
    $allowed_fields = [
        'sub', 'name', 'email', 'email_verified', 'phone_number', 
        'phone_number_verified', 'address', 'city', 'country', 
        'picture', 'locale', 'family_name', 'given_name'
    ];
    
    $result = [];
    
    foreach ($claims as $claim) {
        if (in_array($claim, $allowed_fields)) {
            if ($claim === 'sub') {
                continue; // sub é adicionado depois
            }
            if (isset($user[$claim])) {
                $result[$claim] = $user[$claim];
            }
        }
    }
    
    return $result;
}

function getAuthorizationHeader() {
    $headers = getallheaders();
    return $headers['Authorization'] ?? null;
}

// ============================================================================
// AÇÃO: AUTHORIZE (Passo 1)
// ============================================================================

function handleAuthorize() {
    global $clients, $users, $scopes_definition;

    // Parâmetros GET/POST
    $client_id = $_REQUEST['client_id'] ?? null;
    $redirect_uri = $_REQUEST['redirect_uri'] ?? null;
    $response_type = $_REQUEST['response_type'] ?? 'code';
    $scope = $_REQUEST['scope'] ?? 'openid profile';
    $state = $_REQUEST['state'] ?? '';
    $nonce = $_REQUEST['nonce'] ?? null;
    $prompt = $_REQUEST['prompt'] ?? null; // login, consent, none
    $login_hint = trim($_REQUEST['login_hint'] ?? '');
    $code_challenge = $_REQUEST['code_challenge'] ?? null;
    $code_challenge_method = $_REQUEST['code_challenge_method'] ?? null;

    // Validações iniciais (sem redirect_uri validado ainda não é seguro redirecionar)
    if (!$client_id || !isset($clients[$client_id])) {
        return htmlResponse("Erro: client_id inválido", 400);
    }

    if (!$redirect_uri || !in_array($redirect_uri, $clients[$client_id]['redirect_uris'], true)) {
        return htmlResponse("Erro: redirect_uri inválido", 400);
    }

    // A partir daqui o redirect_uri é confiável: erros voltam ao cliente com o state
    $requested_scopes = parseScopes($scope);
    $client_allowed_scopes = array_keys($scopes_definition);

    if (!validateScopes($requested_scopes, $client_allowed_scopes)) {
        return redirectWithError($redirect_uri, 'invalid_scope', 'Scope inválido', $state, $response_type);
    }

    if (!in_array($response_type, $clients[$client_id]['response_types'], true)) {
        return redirectWithError($redirect_uri, 'unsupported_response_type', 'response_type não permitido', $state, $response_type);
    }

    // PKCE (RFC 7636) - obrigatório para clientes públicos (OAuth 2.1 / RFC 9700);
    // opcional para clientes confidenciais, mas validado quando informado
    if ($code_challenge !== null && $code_challenge !== '') {
        $code_challenge_method = $code_challenge_method ?: 'plain';
        if (!in_array($code_challenge_method, ['plain', 'S256'], true)) {
            return redirectWithError($redirect_uri, 'invalid_request', 'code_challenge_method inválido', $state, $response_type);
        }
    } else {
        // Cliente público exige code_challenge (PKCE obrigatório)
        if (isPublicClient($client_id)) {
            return redirectWithError($redirect_uri, 'invalid_request', 'code_challenge é obrigatório para clientes públicos', $state, $response_type);
        }
        $code_challenge = null;
        $code_challenge_method = null;
    }

    $authParams = [
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'scope' => $scope,
        'state' => $state,
        'nonce' => $nonce,
        'response_type' => $response_type,
        'prompt' => $prompt,
        'code_challenge' => $code_challenge,
        'code_challenge_method' => $code_challenge_method,
    ];

    // ===================== Autenticação (separada do consentimento) =====================
    $is_login_post = $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['username'], $_POST['password'])
        && trim((string) $_POST['password']) !== '';

    if ($is_login_post) {
        $username_input = trim((string) $_POST['username']);
        $password = trim((string) $_POST['password']);

        // Uma nova tentativa de login invalida qualquer autenticação anterior
        unset($_SESSION['oauth_authenticated_user']);

        if ($username_input === '' || $password === '') {
            return showLoginForm($authParams, 'Usuário e senha são obrigatórios', $login_hint);
        }
        if (!isset($users[$username_input])) {
            return showLoginForm($authParams, 'Usuário não encontrado', $login_hint);
        }
        if (!hash_equals($users[$username_input]['password'], $password)) {
            return showLoginForm($authParams, 'Senha incorreta', $login_hint);
        }

        // Credenciais válidas: registra a autenticação na sessão
        $_SESSION['oauth_authenticated_user'] = $username_input;
        $username = $username_input;
    } else {
        $username = $_SESSION['oauth_authenticated_user'] ?? null;
    }

    // ===================== prompt=none (sem interação) =====================
    if ($prompt === 'none') {
        if ($username === null) {
            return redirectWithError($redirect_uri, 'interaction_required', 'Autenticação necessária', $state, $response_type);
        }
        if (!consentIsGranted($client_id, $requested_scopes)) {
            return redirectWithError($redirect_uri, 'consent_required', 'Consentimento necessário', $state, $response_type);
        }

        return completeAuthorization($authParams, $username);
    }

    // ===================== Consentimento =====================
    $consent = $_POST['consent'] ?? null; // '1' autoriza, '0' recusa

    if ($consent === '0') {
        return redirectWithError($redirect_uri, 'access_denied', 'Usuário recusou o acesso', $state, $response_type);
    }

    if ($username === null) {
        return showLoginForm($authParams, '', $login_hint);
    }

    $needs_consent = $consent !== '1'
        && ($prompt === 'consent' || !consentIsGranted($client_id, $requested_scopes));

    if ($needs_consent) {
        return showConsentForm($authParams, $requested_scopes);
    }

    // Usuário autorizou: respeita os scopes efetivamente marcados
    if ($consent === '1') {
        $granted_scopes = $requested_scopes;
        if (isset($_POST['scopes']) && is_array($_POST['scopes'])) {
            $selected = array_values(array_intersect(array_map('strval', $_POST['scopes']), $requested_scopes));
            if ($selected !== []) {
                $granted_scopes = $selected;
            }
        }
        grantConsent($client_id, $granted_scopes);
        $authParams['scope'] = implode(' ', $granted_scopes);
    }

    completeAuthorization($authParams, $username);
}

function showLoginForm(array $authParams, $error = '', $login_hint = '') {
    global $clients;

    $client_id = $authParams['client_id'];
    $scope = $authParams['scope'];
    $client_name = $clients[$client_id]['client_name'] ?? $client_id;
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OAuth2 - Login</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 1rem;
            }
            .container {
                background: white;
                padding: 2rem;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                width: 100%;
                max-width: 450px;
            }
            h1 { 
                color: #333; 
                margin-bottom: 0.5rem; 
                font-size: 1.8rem;
                text-align: center;
            }
            .subtitle {
                color: #666;
                text-align: center;
                margin-bottom: 1.5rem;
                font-size: 0.95rem;
            }
            .info {
                background: #e3f2fd;
                padding: 1rem;
                border-radius: 8px;
                margin-bottom: 1.5rem;
                border-left: 4px solid #2196f3;
                font-size: 0.9rem;
                color: #1565c0;
            }
            .scopes-info {
                background: #f0f4ff;
                padding: 1rem;
                border-radius: 8px;
                margin-bottom: 1.5rem;
                border-left: 4px solid #667eea;
                font-size: 0.85rem;
            }
            .scopes-info strong {
                display: block;
                margin-bottom: 0.5rem;
                color: #333;
            }
            .scope-item {
                display: inline-block;
                background: white;
                padding: 0.25rem 0.75rem;
                margin: 0.25rem 0.25rem 0.25rem 0;
                border-radius: 4px;
                border: 1px solid #667eea;
                color: #667eea;
                font-size: 0.8rem;
                font-weight: 500;
            }
            .form-group {
                margin-bottom: 1rem;
            }
            label {
                display: block;
                margin-bottom: 0.5rem;
                color: #333;
                font-weight: 500;
                font-size: 0.95rem;
            }
            input {
                width: 100%;
                padding: 0.75rem;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-size: 1rem;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            input:focus {
                outline: none;
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }
            button {
                width: 100%;
                padding: 0.85rem;
                background: #667eea;
                color: white;
                border: none;
                border-radius: 6px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s, transform 0.1s;
                margin-top: 0.5rem;
            }
            button:hover { 
                background: #5568d3;
            }
            button:active {
                transform: scale(0.98);
            }
            .error {
                background: #ffebee;
                color: #c62828;
                padding: 0.75rem;
                border-radius: 6px;
                margin-bottom: 1rem;
                border-left: 4px solid #c62828;
                font-size: 0.9rem;
            }
            .credentials {
                background: #f5f5f5;
                padding: 1rem;
                border-radius: 6px;
                margin-bottom: 1.5rem;
                font-size: 0.85rem;
            }
            .credentials h3 {
                margin-bottom: 0.75rem;
                color: #333;
            }
            .credentials p {
                margin: 0.4rem 0;
                color: #666;
                line-height: 1.4;
            }
            .credentials code {
                background: white;
                padding: 0.2rem 0.4rem;
                border-radius: 3px;
                font-family: monospace;
                color: #d32f2f;
            }
            .divider {
                text-align: center;
                margin: 1.5rem 0;
                color: #999;
                font-size: 0.85rem;
            }
            .parameters {
                background: #f9f9f9;
                padding: 0.75rem;
                border-radius: 6px;
                margin-bottom: 1rem;
                font-size: 0.8rem;
                color: #666;
                max-height: 100px;
                overflow-y: auto;
                font-family: monospace;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔐 OAuth2 Login</h1>
            <p class="subtitle"><?php echo htmlspecialchars($client_name); ?></p>
            
            <div class="info">
                <strong><?php echo htmlspecialchars($client_name); ?></strong> está solicitando acesso ao seu perfil.
            </div>
            
            <div class="scopes-info">
                <strong>📋 Permissões Solicitadas:</strong>
                <?php foreach (explode(' ', trim($scope)) as $s): ?>
                    <span class="scope-item"><?php echo htmlspecialchars($s); ?></span>
                <?php endforeach; ?>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="error">
                    <strong>⚠️ Erro:</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="credentials">
                <h3>📝 Credenciais de Teste</h3>
                <p><code>usuario1</code> / <code>senha123</code></p>
                <p><strong>ou</strong></p>
                <p><code>usuario2</code> / <code>senha456</code></p>
            </div>
            
            <form method="POST" action="">
                <?php foreach ($authParams as $param_name => $param_value): ?>
                    <?php if ($param_value !== null && $param_value !== ''): ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($param_name); ?>" value="<?php echo htmlspecialchars((string) $param_value); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <div class="form-group">
                    <label for="username">👤 Usuário</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        placeholder="usuario1"
                        value="<?php echo htmlspecialchars($login_hint); ?>"
                        required 
                        autofocus
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">🔑 Senha</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="senha123"
                        required
                    >
                </div>
                
                <button type="submit">✓ Entrar</button>
            </form>
            
            <?php if ($authParams['nonce']): ?>
                <div class="parameters">
                    <strong>Parâmetros:</strong><br>
                    nonce: <?php echo htmlspecialchars($authParams['nonce']); ?>
                    <?php if ($authParams['code_challenge']): ?><br>PKCE: <?php echo htmlspecialchars($authParams['code_challenge_method']); ?><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function showConsentForm(array $authParams, array $requested_scopes) {
    global $clients, $scopes_definition;

    $client_id = $authParams['client_id'];
    $client_name = $clients[$client_id]['client_name'] ?? $client_id;
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Autorizar Acesso</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 1rem;
            }
            .container {
                background: white;
                padding: 2rem;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                width: 100%;
                max-width: 500px;
            }
            h1 { 
                color: #333; 
                margin-bottom: 0.5rem; 
                font-size: 1.6rem;
                text-align: center;
            }
            .subtitle {
                color: #666;
                text-align: center;
                margin-bottom: 1.5rem;
                font-size: 0.95rem;
            }
            .scope-list {
                background: #f5f5f5;
                padding: 1rem;
                border-radius: 8px;
                margin-bottom: 1.5rem;
            }
            .scope-item {
                padding: 0.75rem;
                border-bottom: 1px solid #ddd;
                display: flex;
                align-items: flex-start;
                gap: 0.75rem;
            }
            .scope-item:last-child {
                border-bottom: none;
            }
            .scope-item input[type="checkbox"] {
                margin-top: 0.25rem;
            }
            .scope-info {
                flex: 1;
            }
            .scope-name {
                font-weight: 600;
                color: #333;
                margin-bottom: 0.25rem;
            }
            .scope-description {
                font-size: 0.85rem;
                color: #666;
            }
            .buttons {
                display: flex;
                gap: 1rem;
                margin-top: 1.5rem;
            }
            button {
                flex: 1;
                padding: 0.75rem;
                border: none;
                border-radius: 6px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }
            .allow {
                background: #667eea;
                color: white;
            }
            .allow:hover {
                background: #5568d3;
            }
            .deny {
                background: #f5f5f5;
                color: #333;
                border: 1px solid #ddd;
            }
            .deny:hover {
                background: #eeeeee;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔐 Autorizar Acesso</h1>
            <p class="subtitle"><?php echo htmlspecialchars($client_name); ?> está solicitando acesso</p>
            
            <form method="POST">
                <?php foreach ($authParams as $param_name => $param_value): ?>
                    <?php if ($param_value !== null && $param_value !== ''): ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($param_name); ?>" value="<?php echo htmlspecialchars((string) $param_value); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <div class="scope-list">
                    <?php foreach ($requested_scopes as $scope_name): ?>
                        <?php if (isset($scopes_definition[$scope_name])): ?>
                            <div class="scope-item">
                                <input 
                                    type="checkbox" 
                                    id="scope_<?php echo htmlspecialchars($scope_name); ?>"
                                    name="scopes[]"
                                    value="<?php echo htmlspecialchars($scope_name); ?>"
                                    checked
                                >
                                <div class="scope-info">
                                    <div class="scope-name"><?php echo htmlspecialchars($scope_name); ?></div>
                                    <div class="scope-description">
                                        <?php echo htmlspecialchars($scopes_definition[$scope_name]['description']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                
                <div class="buttons">
                    <button type="submit" class="deny" name="consent" value="0">
                        ✕ Recusar
                    </button>
                    <button type="submit" class="allow" name="consent" value="1">
                        ✓ Autorizar
                    </button>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// AÇÃO: TOKEN (Passo 2)
// ============================================================================

function handleToken() {
    global $clients, $config;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return jsonResponse(['error' => 'invalid_request'], 405);
    }
    
    // Suporta tanto POST quanto Basic Auth
    $client_id = $_POST['client_id'] ?? null;
    $client_secret = $_POST['client_secret'] ?? null;
    $client_assertion_type = $_POST['client_assertion_type'] ?? null;
    $client_assertion = $_POST['client_assertion'] ?? null;
    
    // Verificar Basic Auth
    $auth_header = getAuthorizationHeader();
    if ($auth_header && strpos($auth_header, 'Basic ') === 0) {
        $credentials = base64_decode(substr($auth_header, 6));
        if (strpos($credentials, ':') !== false) {
            list($client_id, $client_secret) = explode(':', $credentials, 2);
        }
    }
    
    $grant_type = $_POST['grant_type'] ?? null;
    $code = $_POST['code'] ?? null;
    $redirect_uri = $_POST['redirect_uri'] ?? null;
    $refresh_token = $_POST['refresh_token'] ?? null;
    $code_verifier = $_POST['code_verifier'] ?? null;
    $username = $_POST['username'] ?? null;
    $password = $_POST['password'] ?? null;
    
    // Autenticação via JWT Bearer Assertion (RFC 7523 - Client Authentication)
    if ($client_assertion_type === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' && $client_assertion) {
        $jwtResult = verifyJwt($client_assertion);
        if (!$jwtResult['valid']) {
            return jsonResponse(['error' => 'invalid_client', 'error_description' => 'Client assertion inválido: ' . ($jwtResult['error'] ?? 'unknown')], 401);
        }
        $payload = $jwtResult['payload'];
        if (($payload['iss'] ?? null) !== ($payload['sub'] ?? null)) {
            return jsonResponse(['error' => 'invalid_client', 'error_description' => 'iss e sub devem ser idênticos'], 401);
        }
        if ($client_id && $client_id !== $payload['iss']) {
            return jsonResponse(['error' => 'invalid_client', 'error_description' => 'client_id não corresponde ao iss'], 401);
        }
        $client_id = $payload['iss'];
        $client_secret = $clients[$client_id]['client_secret'] ?? 'jwt-bearer-auth';
    }
    
    // Validações
    if (!$client_id || !isset($clients[$client_id])) {
        return jsonResponse(['error' => 'invalid_client', 'error_description' => 'Client ID inválido'], 400);
    }
    // Clientes públicos (PKCE sem secret) não possuem client_secret — a
    // autenticação é feita apenas pelo client_id (RFC 9700). JWT Bearer
    // Assertion (RFC 7523) também dispensa o client_secret.
    if (!$client_assertion && $grant_type !== 'urn:ietf:params:oauth:grant-type:jwt-bearer'
        && !isPublicClient($client_id) && $clients[$client_id]['client_secret'] !== $client_secret) {
        return jsonResponse(['error' => 'invalid_client', 'error_description' => 'Client secret inválido'], 401);
    }
    
    $storage = loadStorage();
    
    // ======================== Authorization Code Flow ========================
    if ($grant_type === 'authorization_code') {
        if (!$code) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Authorization code ausente'], 400);
        }
        
        if (!isset($storage['auth_codes'][$code])) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Authorization code inválido'], 400);
        }
        
        $auth_code = $storage['auth_codes'][$code];
        
        if (time() > $auth_code['expires_at']) {
            unset($storage['auth_codes'][$code]);
            saveStorage($storage);
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Authorization code expirado'], 400);
        }
        
        if ($auth_code['client_id'] !== $client_id || $auth_code['redirect_uri'] !== $redirect_uri) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Mismatch de client ou redirect_uri'], 400);
        }

        // PKCE (RFC 7636): se o code foi emitido com challenge, o verifier é obrigatório
        if (!verifyPkce($code_verifier, $auth_code['code_challenge'] ?? null, $auth_code['code_challenge_method'] ?? null)) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Falha na verificação PKCE'], 400);
        }

        $username = $auth_code['username'];
        $scope = $auth_code['scope'];
        $nonce = $auth_code['nonce'] ?? null;
        
        unset($storage['auth_codes'][$code]);
    }
    // ======================== Refresh Token Flow ========================
    elseif ($grant_type === 'refresh_token') {
        if (!$refresh_token) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Refresh token ausente'], 400);
        }
        
        if (!isset($storage['refresh_tokens'][$refresh_token])) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Refresh token inválido'], 400);
        }
        
        $stored_refresh = $storage['refresh_tokens'][$refresh_token];
        
        if ($stored_refresh['client_id'] !== $client_id) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Refresh token não pertence a este cliente'], 400);
        }

        if (isset($stored_refresh['expires_at']) && time() > $stored_refresh['expires_at']) {
            unset($storage['refresh_tokens'][$refresh_token]);
            saveStorage($storage);
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Refresh token expirado'], 400);
        }

        $username = $stored_refresh['username'];
        $scope = $stored_refresh['scope'];

        // Rotação: o refresh token usado é invalidado e um novo será emitido
        unset($storage['refresh_tokens'][$refresh_token]);
    }
    // ======================== Resource Owner Password Credentials Flow ========================
    elseif ($grant_type === 'password') {
        if (!$username || !$password) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Username ou password ausente'], 400);
        }
        
        global $users;
        
        if (!isset($users[$username]) || $users[$username]['password'] !== $password) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Credenciais inválidas'], 401);
        }
        
        $scope = $_POST['scope'] ?? 'openid profile';
    }
    // ======================== Client Credentials Flow ========================
    elseif ($grant_type === 'client_credentials') {
        $scope = $_POST['scope'] ?? 'openid profile';
        $username = $client_id; // Usar client_id como username
    }
    // ======================== JWT Bearer Assertion Grant (RFC 7523) ========================
    elseif ($grant_type === 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
        $assertion = $_POST['assertion'] ?? null;
        $scope = $_POST['scope'] ?? null;

        if (!$assertion) {
            return jsonResponse(['error' => 'invalid_request', 'error_description' => 'assertion ausente'], 400);
        }

        $jwtResult = verifyJwt($assertion);
        if (!$jwtResult['valid']) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Assertion inválida: ' . ($jwtResult['error'] ?? 'unknown')], 400);
        }

        $payload = $jwtResult['payload'];

        $tokenEndpoint = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000') . $_SERVER['SCRIPT_NAME'] . '?action=token';
        $aud = $payload['aud'] ?? null;
        if ($aud !== $tokenEndpoint && (!is_array($aud) || !in_array($tokenEndpoint, $aud))) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Audience inválido'], 400);
        }

        $username = $payload['sub'] ?? $payload['iss'] ?? null;

        if (isset($payload['scope'])) {
            $scope = $payload['scope'];
        }
        if (!$scope) {
            $scope = 'openid profile';
        }
    }
    // ======================== Device Authorization Grant (RFC 8628) ========================
    elseif ($grant_type === 'urn:ietf:params:oauth:grant-type:device_code' || $grant_type === 'device_code') {
        $device_code = $_POST['device_code'] ?? null;

        if (!$device_code) {
            return jsonResponse(['error' => 'invalid_request', 'error_description' => 'device_code ausente'], 400);
        }

        if (!isset($storage['device_codes'][$device_code])) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'device_code inválido'], 400);
        }

        $device_data = $storage['device_codes'][$device_code];

        if ($device_data['client_id'] !== $client_id) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'device_code não pertence a este cliente'], 400);
        }

        if (time() > $device_data['expires_at']) {
            unset($storage['device_codes'][$device_code]);
            saveStorage($storage);
            return jsonResponse(['error' => 'expired_token', 'error_description' => 'device_code expirado'], 400);
        }

        // Slow down: se polling muito rápido
        $now = time();
        if ($device_data['last_poll'] > 0 && ($now - $device_data['last_poll']) < $device_data['interval']) {
            $storage['device_codes'][$device_code]['last_poll'] = $now;
            saveStorage($storage);
            return jsonResponse(['error' => 'slow_down', 'error_description' => 'Polling muito rápido'], 400);
        }

        $storage['device_codes'][$device_code]['last_poll'] = $now;
        saveStorage($storage);

        if ($device_data['status'] === 'pending') {
            return jsonResponse(['error' => 'authorization_pending', 'error_description' => 'Autorização ainda pendente'], 400);
        }

        if ($device_data['status'] === 'denied') {
            unset($storage['device_codes'][$device_code]);
            saveStorage($storage);
            return jsonResponse(['error' => 'access_denied', 'error_description' => 'Usuário negou a autorização'], 400);
        }

        if ($device_data['status'] === 'approved') {
            $username = $device_data['username'];
            $scope = $device_data['scope'];
            $nonce = null; // Device flow não usa nonce tipicamente

            // Remove device_code após uso (one-time use)
            unset($storage['device_codes'][$device_code]);
        } else {
            return jsonResponse(['error' => 'authorization_pending', 'error_description' => 'Autorização ainda pendente'], 400);
        }
    }
    else {
        return jsonResponse(['error' => 'unsupported_grant_type', 'error_description' => 'Grant type não suportado'], 400);
    }
    
    // Gerar tokens
    $access_token = generateToken();
    
    $storage['access_tokens'] = $storage['access_tokens'] ?? [];
    $storage['access_tokens'][$access_token] = [
        'client_id' => $client_id,
        'username' => $username,
        'scope' => $scope,
        'created_at' => time(),
        'expires_at' => time() + $config['access_token_expires'],
    ];
    
    $response = [
        'access_token' => $access_token,
        'token_type' => 'Bearer',
        'expires_in' => $config['access_token_expires'],
        'scope' => $scope,
    ];
    
    // Refresh token (não se aplica a client_credentials)
    if ($grant_type !== 'client_credentials') {
        $new_refresh_token = generateToken();

        $storage['refresh_tokens'] = $storage['refresh_tokens'] ?? [];
        $storage['refresh_tokens'][$new_refresh_token] = [
            'client_id' => $client_id,
            'username' => $username,
            'scope' => $scope,
            'created_at' => time(),
            'expires_at' => time() + $config['refresh_token_expires'],
        ];

        $response['refresh_token'] = $new_refresh_token;
    }
    
    saveStorage($storage);
    
    // Adicionar id_token se openid scope
    if (in_array('openid', parseScopes($scope))) {
        $response['id_token'] = generateIdToken($username, $client_id, $nonce ?? null);
    }
    
    jsonResponse($response);
}

// ============================================================================
// AÇÃO: USERINFO
// ============================================================================

function handleUserInfo() {
    global $users;
    
    $auth_header = getAuthorizationHeader();
    
    if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
        return jsonResponse(['error' => 'invalid_token'], 401);
    }
    
    $access_token = substr($auth_header, 7);
    $storage = loadStorage();
    
    if (!isset($storage['access_tokens'][$access_token])) {
        return jsonResponse(['error' => 'invalid_token'], 401);
    }
    
    $token_data = $storage['access_tokens'][$access_token];
    
    if (time() > $token_data['expires_at']) {
        unset($storage['access_tokens'][$access_token]);
        saveStorage($storage);
        return jsonResponse(['error' => 'token_expired'], 401);
    }
    
    $username = $token_data['username'];
    $user = $users[$username] ?? null;
    
    if (!$user) {
        return jsonResponse(['error' => 'user_not_found'], 401);
    }
    
    $scopes = parseScopes($token_data['scope']);
    $claims = getClaimsForScopes($scopes);
    
    $userinfo = [
        'sub' => $username,
    ];
    
    $userinfo = array_merge($userinfo, filterUserDataByClaims($user, $claims));
    
    jsonResponse($userinfo);
}

// ============================================================================
// AÇÃO: REVOKE (RFC 7009 - Token Revocation)
// ============================================================================

function handleRevoke() {
    global $clients;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return jsonResponse(['error' => 'invalid_request'], 405);
    }

    $client_id = $_POST['client_id'] ?? null;
    $client_secret = $_POST['client_secret'] ?? null;

    $auth_header = getAuthorizationHeader();
    if ($auth_header && strpos($auth_header, 'Basic ') === 0) {
        $credentials = base64_decode(substr($auth_header, 6));
        if (strpos($credentials, ':') !== false) {
            list($client_id, $client_secret) = explode(':', $credentials, 2);
        }
    }

    if (!$client_id || !isset($clients[$client_id])
        || (!isPublicClient($client_id) && $clients[$client_id]['client_secret'] !== $client_secret)) {
        return jsonResponse(['error' => 'invalid_client'], 401);
    }

    $token = $_POST['token'] ?? null;
    $token_type_hint = $_POST['token_type_hint'] ?? null;

    if (!$token) {
        return jsonResponse(['error' => 'invalid_request', 'error_description' => 'token ausente'], 400);
    }

    $storage = loadStorage();

    // Tenta primeiro o tipo sugerido e depois o outro (RFC 7009 §2.1)
    $hints = ['access_token', 'refresh_token'];
    if ($token_type_hint === 'refresh_token') {
        $hints = ['refresh_token', 'access_token'];
    }

    $revoked = false;
    foreach ($hints as $hint) {
        $bucket = $hint === 'refresh_token' ? 'refresh_tokens' : 'access_tokens';

        if (!isset($storage[$bucket][$token])) {
            continue;
        }

        $token_data = $storage[$bucket][$token];

        // Só revoga tokens do próprio cliente autenticado
        if (($token_data['client_id'] ?? null) !== $client_id) {
            continue;
        }

        unset($storage[$bucket][$token]);
        $revoked = true;

        // Revogar um refresh token também invalida os access tokens associados
        if ($bucket === 'refresh_tokens') {
            foreach ($storage['access_tokens'] ?? [] as $access_token => $data) {
                if (($data['client_id'] ?? null) === $client_id
                    && ($data['username'] ?? null) === ($token_data['username'] ?? null)) {
                    unset($storage['access_tokens'][$access_token]);
                }
            }
        }
    }

    saveStorage($storage);

    // Conforme a RFC 7009, respondemos 200 mesmo se o token já era inválido
    jsonResponse(['revoked' => $revoked]);
}

// ============================================================================
// AÇÃO: INTROSPECT (RFC 7662 - Token Introspection)
// ============================================================================

function handleIntrospect() {
    global $clients, $config;

    // RFC 7662 §4: respostas de introspecção não devem ser cacheadas
    header('Cache-Control: no-store');
    header('Pragma: no-cache');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return jsonResponse(['error' => 'invalid_request'], 405);
    }

    // Autenticação do Resource Server (client_secret_post ou client_secret_basic)
    $client_id = $_POST['client_id'] ?? null;
    $client_secret = $_POST['client_secret'] ?? null;

    $auth_header = getAuthorizationHeader();
    if ($auth_header && strpos($auth_header, 'Basic ') === 0) {
        $credentials = base64_decode(substr($auth_header, 6));
        if (strpos($credentials, ':') !== false) {
            list($client_id, $client_secret) = explode(':', $credentials, 2);
        }
    }

    if (!$client_id || !isset($clients[$client_id])
        || (!isPublicClient($client_id)
            && !hash_equals((string) $clients[$client_id]['client_secret'], (string) $client_secret))) {
        header('WWW-Authenticate: Basic realm="oauth2"');
        return jsonResponse(['error' => 'invalid_client'], 401);
    }

    $token = $_POST['token'] ?? null;
    if (!$token) {
        return jsonResponse(['error' => 'invalid_request', 'error_description' => 'token ausente'], 400);
    }

    // O hint só define a ordem da busca; se não achar, procura no outro tipo (RFC 7662 §2.1)
    $buckets = ['access_tokens', 'refresh_tokens'];
    if (($_POST['token_type_hint'] ?? null) === 'refresh_token') {
        $buckets = ['refresh_tokens', 'access_tokens'];
    }

    $storage = loadStorage();

    foreach ($buckets as $bucket) {
        if (!isset($storage[$bucket][$token])) {
            continue;
        }

        $data = $storage[$bucket][$token];

        // Expirado → inativo (somente leitura: não remove do storage)
        if (time() > ($data['expires_at'] ?? 0)) {
            return jsonResponse(['active' => false]);
        }

        $response = [
            'active'     => true,
            'scope'      => $data['scope'],
            'client_id'  => $data['client_id'],
            'sub'        => $data['username'],
            'token_type' => $bucket === 'refresh_tokens' ? 'refresh_token' : 'Bearer',
            'exp'        => $data['expires_at'],
            'iat'        => $data['created_at'],
            'iss'        => $config['base_url'],
        ];

        // Client Credentials grava username = client_id; nesse caso não há usuário final
        if ($data['username'] !== $data['client_id']) {
            $response['username'] = $data['username'];
        }

        return jsonResponse($response);
    }

    // Token desconhecido ou revogado
    return jsonResponse(['active' => false]);
}

// ============================================================================
// AÇÃO: WELL-KNOWN (OpenID Connect Discovery)
// ============================================================================

function handleWellKnown() {
    global $config;

    $base = $config['base_url'] . '/oauth2_complete.php';

    jsonResponse([
        'issuer' => $config['base_url'],
        'authorization_endpoint' => $base . '?action=authorize',
        'token_endpoint' => $base . '?action=token',
        'userinfo_endpoint' => $base . '?action=userinfo',
        'revocation_endpoint' => $base . '?action=revoke',
        'introspection_endpoint' => $base . '?action=introspect',
        'response_types_supported' => ['code', 'token', 'id_token', 'id_token token', 'code token', 'code id_token', 'code id_token token'],
        'response_modes_supported' => ['query', 'fragment'],
        'grant_types_supported' => [
            'authorization_code',
            'implicit',
            'refresh_token',
            'password',
            'client_credentials',
            'urn:ietf:params:oauth:grant-type:device_code',
            'device_code',
            'urn:ietf:params:oauth:grant-type:jwt-bearer'
        ],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['HS256'],
        'scopes_supported' => ['openid', 'profile', 'email', 'address', 'phone'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'client_secret_jwt', 'private_key_jwt', 'none'],
        'revocation_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
        'introspection_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
        'token_endpoint_auth_signing_alg_values_supported' => ['HS256', 'RS256'],
        'code_challenge_methods_supported' => ['S256', 'plain'],
        'display_values_supported' => ['page', 'popup', 'touch', 'wap'],
        'claim_types_supported' => ['normal'],
        'claims_supported' => ['sub', 'name', 'email', 'email_verified', 'phone_number', 'phone_number_verified', 'address', 'picture', 'locale'],
        'claim_parameter_supported' => true,
    ]);
}

// ============================================================================
// AÇÃO: DEBUG
// ============================================================================

function handleDebug() {
    global $clients, $users, $scopes_definition;
    
    $storage = loadStorage();
    $baseUrl = 'http://localhost:8000';
    
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OAuth2 - Debug</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Roboto, sans-serif;
                background: #f5f5f5;
                padding: 2rem;
            }
            .container { max-width: 1400px; margin: 0 auto; }
            h1 {
                color: #333;
                margin-bottom: 2rem;
                font-size: 2rem;
            }
            h2 {
                color: #667eea;
                margin-top: 2rem;
                margin-bottom: 1rem;
                border-bottom: 2px solid #667eea;
                padding-bottom: 0.5rem;
            }
            .section {
                background: white;
                padding: 1.5rem;
                margin-bottom: 1.5rem;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            pre {
                background: #f5f5f5;
                padding: 1rem;
                border-radius: 6px;
                overflow-x: auto;
                font-size: 0.85rem;
                line-height: 1.5;
                border-left: 4px solid #667eea;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 1rem;
            }
            th, td {
                padding: 0.75rem;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            th {
                background: #f5f5f5;
                font-weight: 600;
                color: #333;
            }
            .badge {
                display: inline-block;
                padding: 0.25rem 0.75rem;
                background: #e8f5e9;
                color: #2e7d32;
                border-radius: 20px;
                font-size: 0.75rem;
                font-weight: 600;
            }
            .endpoint {
                background: #f9f9f9;
                padding: 1rem;
                border-radius: 6px;
                margin-bottom: 1rem;
                border-left: 4px solid #4caf50;
            }
            .endpoint code {
                display: block;
                word-break: break-all;
                color: #d32f2f;
                font-size: 0.8rem;
                margin-top: 0.5rem;
                font-family: monospace;
            }
            .button {
                display: inline-block;
                padding: 0.75rem 1.5rem;
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                margin-top: 1rem;
                border: none;
                cursor: pointer;
                transition: background 0.2s;
            }
            .button:hover { background: #5568d3; }
            .button.secondary {
                background: #6c757d;
            }
            .button.secondary:hover {
                background: #5a6268;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🐛 OAuth2 - Debug Completo</h1>
            
            <div class="section">
                <h2>🔐 Scopes Disponíveis</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Scope</th>
                            <th>Descrição</th>
                            <th>Claims</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scopes_definition as $scope_name => $scope_def): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($scope_name); ?></strong></td>
                                <td><?php echo htmlspecialchars($scope_def['description']); ?></td>
                                <td><code><?php echo htmlspecialchars(implode(', ', $scope_def['claims'])); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <h2>👥 Usuários Cadastrados</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Senha</th>
                            <th>Email</th>
                            <th>Nome</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $username => $user): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($username); ?></code></td>
                                <td><code><?php echo htmlspecialchars($user['password']); ?></code></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <h2>🌐 Clientes OAuth2</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Client ID</th>
                            <th>Grant Types</th>
                            <th>Response Types</th>
                            <th>Redirect URIs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $cid => $client): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($cid); ?></code></td>
                                <td>
                                    <?php foreach ($client['grant_types'] as $gt): ?>
                                        <span class="badge"><?php echo htmlspecialchars($gt); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php foreach ($client['response_types'] as $rt): ?>
                                        <span class="badge"><?php echo htmlspecialchars($rt); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php foreach ($client['redirect_uris'] as $uri): ?>
                                        <div style="font-size: 0.8rem; margin: 0.25rem 0;">
                                            <?php echo htmlspecialchars($uri); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="section">
                <h2>🔗 Endpoints & Grant Types</h2>
                
                <h3 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.5rem;">Authorization Code Flow:</h3>
                <div class="endpoint">
                    <strong>1. GET /authorize (login)</strong>
                    <code>client_id=cliente_teste&redirect_uri=http://localhost:8001/index.php&response_type=code&scope=openid%20profile&state=xyz&nonce=abc</code>
                </div>
                <div class="endpoint">
                    <strong>2. POST /token</strong>
                    <code>grant_type=authorization_code&code=CODE&client_id=cliente_teste&client_secret=senha_secreta_123&redirect_uri=http://localhost:8001/index.php</code>
                </div>
                
                <h3 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.5rem;">Implicit Flow:</h3>
                <div class="endpoint">
                    <strong>GET /authorize com response_type=token</strong>
                    <code>client_id=cliente_teste&redirect_uri=...&response_type=token&scope=openid%20profile&state=xyz</code>
                </div>
                
                <h3 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.5rem;">Refresh Token:</h3>
                <div class="endpoint">
                    <strong>POST /token</strong>
                    <code>grant_type=refresh_token&refresh_token=TOKEN&client_id=cliente_teste&client_secret=senha_secreta_123</code>
                </div>
                
                <h3 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.5rem;">Resource Owner Password Credentials:</h3>
                <div class="endpoint">
                    <strong>POST /token</strong>
                    <code>grant_type=password&username=usuario1&password=senha123&client_id=cliente_teste&client_secret=senha_secreta_123&scope=openid%20profile</code>
                </div>
                
                <h3 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.5rem;">Client Credentials:</h3>
                <div class="endpoint">
                    <strong>POST /token (com Basic Auth)</strong>
                    <code>grant_type=client_credentials&scope=openid%20profile</code>
                    <p style="margin-top: 0.5rem; font-size: 0.8rem; color: #666;">Header: Authorization: Basic base64(cliente_teste:senha_secreta_123)</p>
                </div>

                <h3 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.5rem;">Token Introspection (RFC 7662):</h3>
                <div class="endpoint">
                    <strong>POST /introspect (com Basic Auth do Resource Server)</strong>
                    <code>token=ACCESS_TOKEN&amp;token_type_hint=access_token</code>
                    <p style="margin-top: 0.5rem; font-size: 0.8rem; color: #666;">Header: Authorization: Basic base64(api_recursos:segredo_api_456)</p>
                </div>
            </div>
            
            <div class="section">
                <h2>🔐 Storage Atual</h2>
                <?php if (empty($storage)): ?>
                    <p style="color: #999; font-style: italic;">Nenhum dado armazenado ainda</p>
                <?php else: ?>
                    <pre><?php echo htmlspecialchars(json_encode($storage, JSON_PRETTY_PRINT)); ?></pre>
                <?php endif; ?>
                <button class="button secondary" onclick="if(confirm('Limpar storage?')) window.location='?action=clear_storage'">Limpar Storage</button>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// AÇÃO: CLEAR STORAGE
// ============================================================================

function handleClearStorage() {
    $path = getStoragePath();
    if (file_exists($path)) {
        unlink($path);
    }
    redirect('?action=debug');
}

// ============================================================================
// DEVICE AUTHORIZATION GRANT (RFC 8628)
// ============================================================================

function handleDeviceAuthorization() {
    global $clients, $config;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return jsonResponse(['error' => 'invalid_request', 'error_description' => 'Método deve ser POST'], 405);
    }

    $client_id = $_POST['client_id'] ?? null;
    $scope = $_POST['scope'] ?? 'openid profile';

    if (!$client_id || !isset($clients[$client_id])) {
        return jsonResponse(['error' => 'invalid_client', 'error_description' => 'Client ID inválido'], 400);
    }

    $requested_scopes = parseScopes($scope);
    $allowed_scopes = array_keys($GLOBALS['scopes_definition'] ?? []);

    if (!validateScopes($requested_scopes, $allowed_scopes)) {
        return jsonResponse(['error' => 'invalid_scope', 'error_description' => 'Scope inválido'], 400);
    }

    $storage = loadStorage();
    $storage['device_codes'] = $storage['device_codes'] ?? [];

    $device_code = generateDeviceCode();
    $user_code = generateUserCode();

    $expires_at = time() + $config['device_code_expires'];
    $interval = $config['device_polling_interval'];

    $storage['device_codes'][$device_code] = [
        'device_code' => $device_code,
        'user_code' => $user_code,
        'client_id' => $client_id,
        'scope' => $scope,
        'status' => 'pending',
        'username' => null,
        'created_at' => time(),
        'expires_at' => $expires_at,
        'interval' => $interval,
        'last_poll' => 0,
    ];

    saveStorage($storage);

    $verification_uri = $config['base_url'] . '/oauth2_complete.php?action=device';
    $verification_uri_complete = $verification_uri . '&user_code=' . urlencode($user_code);

    return jsonResponse([
        'device_code' => $device_code,
        'user_code' => $user_code,
        'verification_uri' => $verification_uri,
        'verification_uri_complete' => $verification_uri_complete,
        'expires_in' => $config['device_code_expires'],
        'interval' => $interval,
    ]);
}

function handleDevice() {
    global $clients, $scopes_definition, $config;

    $user_code = $_GET['user_code'] ?? $_POST['user_code'] ?? null;
    $consent = $_POST['consent'] ?? null;

    $storage = loadStorage();
    $storage['device_codes'] = $storage['device_codes'] ?? [];

    // Encontrar device_code pelo user_code
    $device_data = null;
    $device_code_key = null;
    if ($user_code) {
        foreach ($storage['device_codes'] as $key => $data) {
            if (strtoupper($data['user_code']) === strtoupper($user_code)) {
                $device_data = $data;
                $device_code_key = $key;
                break;
            }
        }
    }

    // Se não encontrou por user_code, tentar buscar por sessão ou mostrar formulário
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$device_data && !$user_code) {
            // Mostrar formulário para inserir user_code
            return showDeviceCodeForm();
        }

        if (!$device_data) {
            return htmlResponse('Código do dispositivo inválido ou expirado', 400);
        }

        if (time() > $device_data['expires_at']) {
            unset($storage['device_codes'][$device_code_key]);
            saveStorage($storage);
            return htmlResponse('Código do dispositivo expirado', 400);
        }

        // Verificar se já aprovado/negado
        if ($device_data['status'] !== 'pending') {
            return showDeviceStatus($device_data['status']);
        }

        // Verificar autenticação do usuário
        $username = $_SESSION['oauth_authenticated_user'] ?? null;
        if ($username === null) {
            // Redirecionar para login com parâmetros para voltar ao device flow
            $_SESSION['device_flow_return'] = true;
            $_SESSION['device_user_code'] = $user_code;
            return showDeviceLoginForm($device_data);
        }

        // Usuário autenticado, mostrar tela de consentimento
        return showDeviceConsentForm($device_data, $username);
    }

    // POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$device_data) {
            return htmlResponse('Código do dispositivo inválido', 400);
        }

        if (time() > $device_data['expires_at']) {
            unset($storage['device_codes'][$device_code_key]);
            saveStorage($storage);
            return htmlResponse('Código do dispositivo expirado', 400);
        }

        $username = $_SESSION['oauth_authenticated_user'] ?? null;

        if ($consent === '0') {
            // Negado
            $storage['device_codes'][$device_code_key]['status'] = 'denied';
            saveStorage($storage);
            return showDeviceStatus('denied');
        }

        if ($consent === '1' && $username) {
            // Aprovado
            $storage['device_codes'][$device_code_key]['status'] = 'approved';
            $storage['device_codes'][$device_code_key]['username'] = $username;
            saveStorage($storage);
            return showDeviceStatus('approved');
        }

        // Precisa autenticar
        if ($username === null) {
            // Processar login
            $username_input = trim((string) ($_POST['username'] ?? ''));
            $password = trim((string) ($_POST['password'] ?? ''));

            if ($username_input !== '' && $password !== '' && isset($GLOBALS['users'][$username_input])) {
                if (hash_equals($GLOBALS['users'][$username_input]['password'], $password)) {
                    $_SESSION['oauth_authenticated_user'] = $username_input;
                    $username = $username_input;
                    return showDeviceConsentForm($device_data, $username);
                }
            }
            return showDeviceLoginForm($device_data, 'Credenciais inválidas');
        }

        return showDeviceConsentForm($device_data, $username);
    }

    return htmlResponse('Método não permitido', 405);
}

// ============================================================================
// RESPOSTA HTML SIMPLES
// ============================================================================

function htmlResponse($content, $statusCode = 200) {
    http_response_code($statusCode);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Erro</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f5f5f5;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                padding: 1rem;
            }
            .container {
                background: white;
                padding: 2rem;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                max-width: 500px;
            }
            h1 { color: #d32f2f; }
            p { color: #666; margin-top: 1rem; }
            a { color: #667eea; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>⚠️ Erro</h1>
            <p><?php echo htmlspecialchars($content); ?></p>
            <a href="?action=debug">← Voltar para Debug</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// ROTEADOR
// ============================================================================

$action = $_GET['action'] ?? $_POST['action'] ?? 'debug';

switch ($action) {
    case 'authorize':
        handleAuthorize();
        break;
    case 'token':
        handleToken();
        break;
    case 'userinfo':
        handleUserInfo();
        break;
    case 'revoke':
        handleRevoke();
        break;
    case 'introspect':
        handleIntrospect();
        break;
    case 'well-known':
        handleWellKnown();
        break;
    case 'device_code':
        handleDeviceAuthorization();
        break;
    case 'device':
        handleDevice();
        break;
    case 'clear_storage':
        handleClearStorage();
        break;
    case 'debug':
    default:
        handleDebug();
        break;
}
// Device Flow Forms
function showDeviceCodeForm() {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Device Authorization</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
            .container { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); width: 100%; max-width: 450px; }
            h1 { color: #333; margin-bottom: 0.5rem; font-size: 1.8rem; text-align: center; }
            .subtitle { color: #666; text-align: center; margin-bottom: 1.5rem; font-size: 0.95rem; }
            .form-group { margin-bottom: 1rem; }
            label { display: block; margin-bottom: 0.5rem; color: #333; font-weight: 500; font-size: 0.95rem; }
            input { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 1rem; text-transform: uppercase; letter-spacing: 2px; text-align: center; font-family: monospace; }
            button { width: 100%; padding: 0.85rem; background: #667eea; color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem; }
            button:hover { background: #5568d3; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>📺 Device Authorization</h1>
            <p class="subtitle">Digite o código exibido no seu dispositivo</p>
            <form method="GET" action="">
                <input type="hidden" name="action" value="device">
                <div class="form-group">
                    <label for="user_code">Código do Usuário</label>
                    <input type="text" id="user_code" name="user_code" placeholder="XXXX-XXXX" maxlength="9" required autofocus>
                </div>
                <button type="submit">Continuar</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function showDeviceLoginForm($device_data, $error = '') {
    global $clients;
    $client_name = $clients[$device_data['client_id']]['client_name'] ?? $device_data['client_id'];
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Device Authorization - Login</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
            .container { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); width: 100%; max-width: 450px; }
            h1 { color: #333; margin-bottom: 0.5rem; font-size: 1.8rem; text-align: center; }
            .subtitle { color: #666; text-align: center; margin-bottom: 1.5rem; font-size: 0.95rem; }
            .info { background: #e3f2fd; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #2196f3; font-size: 0.9rem; color: #1565c0; }
            .error { background: #ffebee; color: #c62828; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; border-left: 4px solid #c62828; font-size: 0.9rem; }
            .form-group { margin-bottom: 1rem; }
            label { display: block; margin-bottom: 0.5rem; color: #333; font-weight: 500; font-size: 0.95rem; }
            input { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 6px; font-size: 1rem; }
            button { width: 100%; padding: 0.85rem; background: #667eea; color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem; }
            button:hover { background: #5568d3; }
            .credentials { background: #f5f5f5; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.85rem; }
            .credentials code { background: white; padding: 0.2rem 0.4rem; border-radius: 3px; font-family: monospace; color: #d32f2f; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔐 Login</h1>
            <p class="subtitle">Device Flow - <?php echo htmlspecialchars($client_name); ?></p>
            <div class="info">
                <strong>Código:</strong> <?php echo htmlspecialchars($device_data['user_code']); ?><br>
                <strong>Escopos:</strong> <?php echo htmlspecialchars($device_data['scope']); ?>
            </div>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="credentials">
                <strong>Credenciais de Teste:</strong><br>
                <code>usuario1</code> / <code>senha123</code> ou <code>usuario2</code> / <code>senha456</code>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="device">
                <input type="hidden" name="user_code" value="<?php echo htmlspecialchars($device_data['user_code']); ?>">
                <div class="form-group">
                    <label for="username">Usuário</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit">Entrar</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function showDeviceConsentForm($device_data, $username) {
    global $clients, $scopes_definition;
    $client_name = $clients[$device_data['client_id']]['client_name'] ?? $device_data['client_id'];
    $requested_scopes = parseScopes($device_data['scope']);
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Autorizar Dispositivo</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
            .container { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); width: 100%; max-width: 500px; }
            h1 { color: #333; margin-bottom: 0.5rem; font-size: 1.6rem; text-align: center; }
            .subtitle { color: #666; text-align: center; margin-bottom: 1.5rem; font-size: 0.95rem; }
            .user-info { background: #f5f5f5; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
            .scope-list { background: #f5f5f5; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
            .scope-item { padding: 0.5rem 0; border-bottom: 1px solid #ddd; }
            .scope-item:last-child { border-bottom: none; }
            .scope-name { font-weight: 600; color: #333; }
            .scope-desc { font-size: 0.85rem; color: #666; }
            .buttons { display: flex; gap: 1rem; }
            button { flex: 1; padding: 0.75rem; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
            .allow { background: #667eea; color: white; }
            .allow:hover { background: #5568d3; }
            .deny { background: #f5f5f5; color: #333; border: 1px solid #ddd; }
            .deny:hover { background: #eeeeee; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔐 Autorizar Dispositivo</h1>
            <p class="subtitle"><?php echo htmlspecialchars($client_name); ?> solicita acesso</p>
            <div class="user-info">
                <strong>Usuário:</strong> <?php echo htmlspecialchars($username); ?><br>
                <strong>Código:</strong> <?php echo htmlspecialchars($device_data['user_code']); ?>
            </div>
            <div class="scope-list">
                <?php foreach ($requested_scopes as $scope_name): ?>
                    <?php if (isset($scopes_definition[$scope_name])): ?>
                        <div class="scope-item">
                            <div class="scope-name"><?php echo htmlspecialchars($scope_name); ?></div>
                            <div class="scope-desc"><?php echo htmlspecialchars($scopes_definition[$scope_name]['description']); ?></div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="device">
                <input type="hidden" name="user_code" value="<?php echo htmlspecialchars($device_data['user_code']); ?>">
                <div class="buttons">
                    <button type="submit" class="deny" name="consent" value="0">✕ Recusar</button>
                    <button type="submit" class="allow" name="consent" value="1">✓ Autorizar</button>
                </div>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function showDeviceStatus($status) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Device Authorization</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
            .container { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); width: 100%; max-width: 450px; text-align: center; }
            h1 { color: #333; margin-bottom: 1rem; font-size: 1.8rem; }
            .icon { font-size: 4rem; margin-bottom: 1rem; }
            .approved { color: #2e7d32; }
            .denied { color: #c62828; }
            p { color: #666; margin-bottom: 1rem; line-height: 1.6; }
        </style>
    </head>
    <body>
        <div class="container">
            <?php if ($status === 'approved'): ?>
                <div class="icon approved">✓</div>
                <h1 class="approved">Autorizado!</h1>
                <p>O dispositivo foi autorizado com sucesso.<br>Você pode fechar esta janela.</p>
            <?php else: ?>
                <div class="icon denied">✕</div>
                <h1 class="denied">Acesso Negado</h1>
                <p>O dispositivo não foi autorizado.<br>Você pode fechar esta janela.</p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}
