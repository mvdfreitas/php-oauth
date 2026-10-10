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
        'grant_types' => ['authorization_code', 'refresh_token', 'implicit'],
        'token_endpoint_auth_method' => 'client_secret_basic',
        'logo_uri' => 'https://via.placeholder.com/200',
        'contacts' => ['admin@example.com'],
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
    return base64_decode(strtr($data, '-_', '+/'));
}

function generateIdToken($username, $client_id, $nonce = null) {
    global $users, $config;
    
    $user = $users[$username] ?? [];
    
    $header = [
        'typ' => 'JWT',
        'alg' => 'HS256',
    ];
    
    $payload = [
        'iss' => $config['base_url'],
        'sub' => $username,
        'aud' => $client_id,
        'exp' => time() + $config['access_token_expires'],
        'iat' => time(),
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
    $login_hint = $_REQUEST['login_hint'] ?? null; // email ou username
    $id_token_hint = $_REQUEST['id_token_hint'] ?? null;
    $display = $_REQUEST['display'] ?? 'page'; // page, popup, touch, wap
    $ui_locales = $_REQUEST['ui_locales'] ?? null;
    $acr_values = $_REQUEST['acr_values'] ?? null;
    $max_age = $_REQUEST['max_age'] ?? null;
    $claims = $_REQUEST['claims'] ?? null; // JSON com claims específicas
    
    // Validações iniciais
    if (!$client_id || !isset($clients[$client_id])) {
        return htmlResponse("Erro: client_id inválido", 400);
    }
    
    if (!$redirect_uri || !in_array($redirect_uri, $clients[$client_id]['redirect_uris'])) {
        return htmlResponse("Erro: redirect_uri inválido", 400);
    }
    
    $requested_scopes = parseScopes($scope);
    $client_allowed_scopes = array_keys($scopes_definition);
    
    if (!validateScopes($requested_scopes, $client_allowed_scopes)) {
        return htmlResponse("Erro: scope inválido", 400);
    }
    
    if (!in_array($response_type, $clients[$client_id]['response_types'])) {
        return htmlResponse("Erro: response_type não permitido para este cliente", 400);
    }
    
    // Se POST (formulário de login)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $consent = $_POST['consent'] ?? false;
        
        // Validar credenciais
        if (!$username || !$password) {
            return showLoginForm(
                $client_id, $redirect_uri, $scope, $state, $nonce, 
                $display, $ui_locales, 'Usuário e senha são obrigatórios', 
                $login_hint, $response_type
            );
        }
        
        if (!isset($users[$username])) {
            return showLoginForm(
                $client_id, $redirect_uri, $scope, $state, $nonce,
                $display, $ui_locales, 'Usuário não encontrado',
                $login_hint, $response_type
            );
        }
        
        if ($users[$username]['password'] !== $password) {
            return showLoginForm(
                $client_id, $redirect_uri, $scope, $state, $nonce,
                $display, $ui_locales, 'Senha incorreta',
                $login_hint, $response_type
            );
        }
        
        // Se prompt=none, não pode mostrar formulário
        if ($prompt === 'none') {
            $error = 'interaction_required';
            $callback_url = $redirect_uri . '?' . http_build_query([
                'error' => $error,
                'state' => $state,
            ]);
            redirect($callback_url);
        }
        
        // Validar consentimento (se necessário)
        if (!$consent && $prompt !== 'login') {
            return showConsentForm(
                $client_id, $redirect_uri, $scope, $state, $nonce, $username, 
                $requested_scopes, $display, $response_type
            );
        }
        
        // Credenciais válidas!
        $storage = loadStorage();

        // Implicit Flow: tokens retornados diretamente no fragment (#), sem token exchange
        if (in_array($response_type, ['token', 'id_token', 'id_token token'], true)) {
            $response_params = ['state' => $state];

            if ($response_type === 'token' || $response_type === 'id_token token') {
                $response_params['access_token'] = createAccessToken($storage, $client_id, $username, $scope);
                $response_params['token_type'] = 'Bearer';
                $response_params['expires_in'] = 3600;
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
            'created_at' => time(),
            'expires_at' => time() + 600,
        ];
        saveStorage($storage);

        redirect($redirect_uri . '?' . http_build_query([
            'code' => $code,
            'state' => $state,
        ]));
    }
    
    // GET - mostrar formulário
    showLoginForm(
        $client_id, $redirect_uri, $scope, $state, $nonce,
        $display, $ui_locales, '', $login_hint, $response_type
    );
}

function showLoginForm($client_id, $redirect_uri, $scope, $state, $nonce, $display, $ui_locales, $error = '', $login_hint = '', $response_type = 'code') {
    global $clients;
    
    $baseUrl = 'http://localhost:8000';
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
                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_id); ?>">
                <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($redirect_uri); ?>">
                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope); ?>">
                <input type="hidden" name="state" value="<?php echo htmlspecialchars($state); ?>">
                <input type="hidden" name="nonce" value="<?php echo htmlspecialchars($nonce ?? ''); ?>">
                <input type="hidden" name="response_type" value="<?php echo htmlspecialchars($response_type); ?>">
                <input type="hidden" name="consent" value="1">
                
                <div class="form-group">
                    <label for="username">👤 Usuário</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        placeholder="usuario1"
                        value="<?php echo htmlspecialchars($login_hint ?? ''); ?>"
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
            
            <?php if ($nonce): ?>
                <div class="parameters">
                    <strong>Parâmetros:</strong><br>
                    nonce: <?php echo htmlspecialchars($nonce); ?>
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function showConsentForm($client_id, $redirect_uri, $scope, $state, $nonce, $username, $requested_scopes, $display, $response_type = 'code') {
    global $clients, $scopes_definition;
    
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
                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_id); ?>">
                <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($redirect_uri); ?>">
                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope); ?>">
                <input type="hidden" name="state" value="<?php echo htmlspecialchars($state); ?>">
                <input type="hidden" name="nonce" value="<?php echo htmlspecialchars($nonce ?? ''); ?>">
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                <input type="hidden" name="password" value="">
                <input type="hidden" name="response_type" value="<?php echo htmlspecialchars($response_type); ?>">
                
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
                    <button type="button" class="deny" onclick="document.location='<?php echo htmlspecialchars($redirect_uri); ?>?error=access_denied&state=<?php echo urlencode($state); ?>'">
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
    $username = $_POST['username'] ?? null;
    $password = $_POST['password'] ?? null;
    
    // Validações
    if (!$client_id || !isset($clients[$client_id])) {
        return jsonResponse(['error' => 'invalid_client', 'error_description' => 'Client ID inválido'], 400);
    }
    
    if ($clients[$client_id]['client_secret'] !== $client_secret) {
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
        
        $username = $stored_refresh['username'];
        $scope = $stored_refresh['scope'];
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
    else {
        return jsonResponse(['error' => 'unsupported_grant_type', 'error_description' => 'Grant type não suportado'], 400);
    }
    
    // Gerar tokens
    $access_token = generateToken();
    $new_refresh_token = generateToken();
    
    $storage['access_tokens'] = $storage['access_tokens'] ?? [];
    $storage['access_tokens'][$access_token] = [
        'client_id' => $client_id,
        'username' => $username,
        'scope' => $scope,
        'created_at' => time(),
        'expires_at' => time() + $config['access_token_expires'],
    ];
    
    $storage['refresh_tokens'] = $storage['refresh_tokens'] ?? [];
    $storage['refresh_tokens'][$new_refresh_token] = [
        'client_id' => $client_id,
        'username' => $username,
        'scope' => $scope,
        'created_at' => time(),
    ];
    
    saveStorage($storage);
    
    $response = [
        'access_token' => $access_token,
        'token_type' => 'Bearer',
        'expires_in' => $config['access_token_expires'],
        'scope' => $scope,
    ];
    
    // Adicionar refresh token
    if ($grant_type !== 'client_credentials') {
        $response['refresh_token'] = $new_refresh_token;
    }
    
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
// AÇÃO: WELL-KNOWN (OpenID Connect Discovery)
// ============================================================================

function handleWellKnown() {
    global $config;
    
    jsonResponse([
        'issuer' => $config['base_url'],
        'authorization_endpoint' => $config['base_url'] . '/index.php?action=authorize',
        'token_endpoint' => $config['base_url'] . '/index.php?action=token',
        'userinfo_endpoint' => $config['base_url'] . '/index.php?action=userinfo',
        'response_types_supported' => ['code', 'token', 'id_token', 'id_token token', 'code token', 'code id_token', 'code id_token token'],
        'response_modes_supported' => ['query', 'fragment'],
        'grant_types_supported' => ['authorization_code', 'implicit', 'refresh_token', 'password', 'client_credentials'],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['HS256', 'RS256'],
        'scopes_supported' => ['openid', 'profile', 'email', 'address', 'phone'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
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
    case 'well-known':
        handleWellKnown();
        break;
    case 'clear_storage':
        handleClearStorage();
        break;
    case 'debug':
    default:
        handleDebug();
        break;
}
