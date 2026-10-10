<?php
/**
 * Servidor OAuth2 Simples em PHP Puro
 * Sem frameworks - apenas PHP puro
 */

session_start();

// ============================================================================
// CONFIGURAÇÕES
// ============================================================================

$config = [
    'base_url' => 'http://localhost:8000',
    'authorize_endpoint' => '/oauth2_server.php?action=authorize',
    'token_endpoint' => '/oauth2_server.php?action=token',
    'userinfo_endpoint' => '/oauth2_server.php?action=userinfo',
];

// ============================================================================
// BANCO DE DADOS SIMULADO (em produção, usar um banco real)
// ============================================================================

// Clientes cadastrados
$clients = [
    'cliente_teste' => [
        'client_id' => 'cliente_teste',
        'client_secret' => 'senha_secreta_123',
        'redirect_uris' => [
            'http://localhost:8001/callback.php',
            'http://localhost:3000/callback.php',
        ],
        'allowed_scopes' => ['openid', 'profile', 'email'],
    ],
];

// Usuários cadastrados
$users = [
    'usuario1' => [
        'password' => password_hash('senha123', PASSWORD_BCRYPT),
        'email' => 'usuario1@example.com',
        'name' => 'Usuário Um',
        'picture' => 'https://via.placeholder.com/150',
    ],
    'usuario2' => [
        'password' => password_hash('senha456', PASSWORD_BCRYPT),
        'email' => 'usuario2@example.com',
        'name' => 'Usuário Dois',
        'picture' => 'https://via.placeholder.com/150',
    ],
];

// Armazena temporariamente authorization codes e tokens
// Em produção, usar banco de dados persistente
$storage = [];
if (file_exists('C:\Projetos\php-oauth\session\oauth2_storage.json')) {
    $storage = json_decode(file_get_contents('C:\Projetos\php-oauth\session\oauth2_storage.json'), true) ?? [];
}

// ============================================================================
// FUNÇÕES UTILITÁRIAS
// ============================================================================

function saveStorage($data) {
    file_put_contents('C:\Projetos\php-oauth\session\oauth2_storage.json', json_encode($data, JSON_PRETTY_PRINT));
}

function generateCode() {
    return bin2hex(random_bytes(32));
}

function generateToken() {
    return bin2hex(random_bytes(64));
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function getQueryParam($name, $default = null) {
    return $_GET[$name] ?? $default;
}

function getPostParam($name, $default = null) {
    return $_POST[$name] ?? $default;
}

function getAuthorizationHeader() {
    $headers = getallheaders();
    return $headers['Authorization'] ?? null;
}

// ============================================================================
// AÇÃO: AUTHORIZE (Passo 1 do fluxo Authorization Code)
// ============================================================================

function handleAuthorize() {
    global $clients;

    $client_id = getQueryParam('client_id');
    $redirect_uri = getQueryParam('redirect_uri');
    $scope = getQueryParam('scope', 'openid profile');
    $state = getQueryParam('state');
    $response_type = getQueryParam('response_type', 'code');
    
    // Validações
    if (!$client_id || !isset($clients[$client_id])) {
        return jsonResponse(['error' => 'invalid_client'], 400);
    }

    if (!in_array($redirect_uri, $clients[$client_id]['redirect_uris'])) {
        return jsonResponse(['error' => 'invalid_redirect_uri'], 400);
    }
    
    if ($response_type !== 'code') {
        return jsonResponse(['error' => 'unsupported_response_type'], 400);
    }
    
    // Se POST (usuário autenticado), gerar authorization code
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = getPostParam('username');
        $password = getPostParam('password');
        
        global $users;
        
        // Validar credenciais
        if (!$username || !$password || !isset($users[$username])) {
            $_SESSION['login_error'] = 'Usuário ou senha inválidos';
            return showLoginForm($client_id, $redirect_uri, $scope, $state);
        }
        
        if (!password_verify($password, $users[$username]['password'])) {
            $_SESSION['login_error'] = 'Usuário ou senha inválidos';
            return showLoginForm($client_id, $redirect_uri, $scope, $state);
        }
        
        // Gerar authorization code
        global $storage;
        $code = generateCode();
        $storage['auth_codes'] = $storage['auth_codes'] ?? [];
        $storage['auth_codes'][$code] = [
            'client_id' => $client_id,
            'username' => $username,
            'scope' => $scope,
            'redirect_uri' => $redirect_uri,
            'expires_at' => time() + 600, // 10 minutos
        ];
        saveStorage($storage);
        
        // Redirecionar para callback com code
        $callback_url = $redirect_uri . '?code=' . $code . '&state=' . urlencode($state);
        header('Location: ' . $callback_url);
        exit;
    }
    
    // GET - mostrar formulário de login
    showLoginForm($client_id, $redirect_uri, $scope, $state);
}

function showLoginForm($client_id, $redirect_uri, $scope, $state) {
    $error = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    
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
                height: 100vh;
            }
            .container {
                background: white;
                padding: 2rem;
                border-radius: 8px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                width: 100%;
                max-width: 400px;
            }
            h1 { color: #333; margin-bottom: 1.5rem; font-size: 1.8rem; }
            .info {
                background: #f0f4ff;
                padding: 1rem;
                border-radius: 4px;
                margin-bottom: 1.5rem;
                font-size: 0.9rem;
                color: #555;
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
            input[type="password"] {
                width: 100%;
                padding: 0.75rem;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 1rem;
            }
            input[type="text"]:focus,
            input[type="password"]:focus {
                outline: none;
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }
            button {
                width: 100%;
                padding: 0.75rem;
                background: #667eea;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }
            button:hover { background: #5568d3; }
            .error {
                background: #fee;
                color: #c33;
                padding: 0.75rem;
                border-radius: 4px;
                margin-bottom: 1rem;
                border-left: 4px solid #c33;
            }
            .credentials {
                background: #f9f9f9;
                padding: 1rem;
                border-radius: 4px;
                margin-bottom: 1.5rem;
                font-size: 0.9rem;
            }
            .credentials strong { color: #333; }
            .credentials p { margin: 0.5rem 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔐 OAuth2 Login</h1>
            
            <div class="info">
                <strong><?php echo htmlspecialchars($client_id); ?></strong> solicitou acesso ao seu perfil.
            </div>
            
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="credentials">
                <strong>Credenciais de teste:</strong>
                <p><strong>Usuário:</strong> usuario1</p>
                <p><strong>Senha:</strong> senha123</p>
                <p><strong>ou</strong></p>
                <p><strong>Usuário:</strong> usuario2</p>
                <p><strong>Senha:</strong> senha456</p>
            </div>
            
            <form method="POST">
                <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_id); ?>">
                <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($redirect_uri); ?>">
                <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope); ?>">
                <input type="hidden" name="state" value="<?php echo htmlspecialchars($state); ?>">
                
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

// ============================================================================
// AÇÃO: TOKEN (Passo 2 do fluxo Authorization Code)
// ============================================================================

function handleToken() {
    global $clients, $storage;
    
    // Validar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return jsonResponse(['error' => 'invalid_request'], 405);
    }
    
    $grant_type = getPostParam('grant_type');
    $code = getPostParam('code');
    $client_id = getPostParam('client_id');
    $client_secret = getPostParam('client_secret');
    $redirect_uri = getPostParam('redirect_uri');
    
    // Validações
    if (!$client_id || !isset($clients[$client_id])) {
        return jsonResponse(['error' => 'invalid_client'], 400);
    }
    
    if ($clients[$client_id]['client_secret'] !== $client_secret) {
        return jsonResponse(['error' => 'invalid_client'], 401);
    }
    
    if ($grant_type !== 'authorization_code') {
        return jsonResponse(['error' => 'unsupported_grant_type'], 400);
    }
    
    if (!$code) {
        return jsonResponse(['error' => 'invalid_grant'], 400);
    }
    
    // Verificar authorization code
    if (!isset($storage['auth_codes'][$code])) {
        return jsonResponse(['error' => 'invalid_grant'], 400);
    }
    
    $auth_code = $storage['auth_codes'][$code];
    
    // Validar expiração
    if ($auth_code['expires_at'] < time()) {
        unset($storage['auth_codes'][$code]);
        saveStorage($storage);
        return jsonResponse(['error' => 'invalid_grant'], 400);
    }
    
    // Validar client_id e redirect_uri
    if ($auth_code['client_id'] !== $client_id || $auth_code['redirect_uri'] !== $redirect_uri) {
        return jsonResponse(['error' => 'invalid_grant'], 400);
    }
    
    // Gerar tokens
    $access_token = generateToken();
    $refresh_token = generateToken();
    
    // Armazenar tokens
    $storage['access_tokens'] = $storage['access_tokens'] ?? [];
    $storage['access_tokens'][$access_token] = [
        'client_id' => $client_id,
        'username' => $auth_code['username'],
        'scope' => $auth_code['scope'],
        'expires_at' => time() + 3600, // 1 hora
    ];
    
    $storage['refresh_tokens'] = $storage['refresh_tokens'] ?? [];
    $storage['refresh_tokens'][$refresh_token] = [
        'client_id' => $client_id,
        'username' => $auth_code['username'],
        'scope' => $auth_code['scope'],
    ];
    
    // Remover authorization code usado
    unset($storage['auth_codes'][$code]);
    saveStorage($storage);
    
    jsonResponse([
        'access_token' => $access_token,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'refresh_token' => $refresh_token,
        'scope' => $auth_code['scope'],
    ]);
}

// ============================================================================
// AÇÃO: USERINFO (Obter informações do usuário)
// ============================================================================

function handleUserInfo() {
    global $storage, $users;
    
    $auth_header = getAuthorizationHeader();
    
    if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
        return jsonResponse(['error' => 'invalid_token'], 401);
    }
    
    $access_token = substr($auth_header, 7);
    
    // Verificar token
    if (!isset($storage['access_tokens'][$access_token])) {
        return jsonResponse(['error' => 'invalid_token'], 401);
    }
    
    $token_data = $storage['access_tokens'][$access_token];
    
    // Validar expiração
    if ($token_data['expires_at'] < time()) {
        unset($storage['access_tokens'][$access_token]);
        saveStorage($storage);
        return jsonResponse(['error' => 'invalid_token'], 401);
    }
    
    $username = $token_data['username'];
    $user = $users[$username] ?? null;
    
    if (!$user) {
        return jsonResponse(['error' => 'invalid_token'], 401);
    }
    
    jsonResponse([
        'sub' => $username,
        'name' => $user['name'],
        'email' => $user['email'],
        'picture' => $user['picture'],
        'scope' => $token_data['scope'],
    ]);
}

// ============================================================================
// AÇÃO: WELL-KNOWN (OpenID Connect Discovery)
// ============================================================================

function handleWellKnown() {
    global $config;
    
    jsonResponse([
        'issuer' => $config['base_url'],
        'authorization_endpoint' => $config['base_url'] . $config['authorize_endpoint'],
        'token_endpoint' => $config['base_url'] . $config['token_endpoint'],
        'userinfo_endpoint' => $config['base_url'] . $config['userinfo_endpoint'],
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'subject_types_supported' => ['public'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
    ]);
}

// ============================================================================
// AÇÃO: DEBUG (Informações de debug)
// ============================================================================

function handleDebug() {
    global $storage, $clients, $users;
    
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OAuth2 Debug</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: 'Monaco', 'Courier New', monospace;
                background: #1e1e1e;
                color: #d4d4d4;
                padding: 2rem;
            }
            .container { max-width: 1200px; margin: 0 auto; }
            h1 { color: #4ec9b0; margin-bottom: 1.5rem; }
            h2 { color: #569cd6; margin-top: 2rem; margin-bottom: 1rem; }
            .section {
                background: #252526;
                padding: 1.5rem;
                border-radius: 4px;
                margin-bottom: 1.5rem;
                border-left: 3px solid #007acc;
            }
            pre {
                overflow-x: auto;
                background: #1e1e1e;
                padding: 1rem;
                border-radius: 4px;
                font-size: 0.85rem;
                line-height: 1.4;
            }
            .endpoint {
                background: #1e1e1e;
                padding: 1rem;
                border-radius: 4px;
                margin-bottom: 1rem;
                border-left: 3px solid #ce9178;
            }
            .endpoint code {
                color: #ce9178;
                word-break: break-all;
            }
            a { color: #569cd6; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🐛 OAuth2 Debug - Informações do Servidor</h1>
            
            <div class="section">
                <h2>📋 Clientes Registrados</h2>
                <pre><?php echo htmlspecialchars(json_encode($clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </div>
            
            <div class="section">
                <h2>👥 Usuários Cadastrados</h2>
                <pre><?php 
                $users_display = [];
                foreach ($users as $username => $user) {
                    $users_display[$username] = [
                        'email' => $user['email'],
                        'name' => $user['name'],
                        'password_hashed' => '***',
                    ];
                }
                echo htmlspecialchars(json_encode($users_display, JSON_PRETTY_PRINT));
                ?></pre>
            </div>
            
            <div class="section">
                <h2>🔐 Storage Atual</h2>
                <pre><?php echo htmlspecialchars(json_encode($storage, JSON_PRETTY_PRINT)); ?></pre>
            </div>
            
            <div class="section">
                <h2>🔗 Endpoints</h2>
                <div class="endpoint">
                    <strong>Authorization:</strong><br>
                    <code>GET /oauth2_server.php?action=authorize&client_id=cliente_teste&redirect_uri=http://localhost:8001/callback&scope=openid%20profile&state=xyz123&response_type=code</code>
                </div>
                <div class="endpoint">
                    <strong>Token:</strong><br>
                    <code>POST /oauth2_server.php?action=token</code><br>
                    Body: client_id=cliente_teste&client_secret=senha_secreta_123&grant_type=authorization_code&code=CODE&redirect_uri=http://localhost:8001/callback
                </div>
                <div class="endpoint">
                    <strong>UserInfo:</strong><br>
                    <code>GET /oauth2_server.php?action=userinfo</code><br>
                    Header: Authorization: Bearer TOKEN
                </div>
                <div class="endpoint">
                    <strong>Well-Known:</strong><br>
                    <code>GET /oauth2_server.php?action=well-known</code>
                </div>
            </div>
            
            <div class="section">
                <h2>🧪 Teste Rápido</h2>
                <p>Clique <a href="?action=authorize&client_id=cliente_teste&redirect_uri=http://localhost:8001/callback&scope=openid%20profile&state=xyz123&response_type=code">aqui</a> para iniciar o fluxo OAuth2</p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// ROTEADOR PRINCIPAL
// ============================================================================

$action = getQueryParam('action', 'debug');

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
    case 'debug':
    default:
        handleDebug();
        break;
}
