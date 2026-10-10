<?php
/**
 * Cliente OAuth2 Simples para Teste
 * Porta 8001
 */

session_start();

$oauth_server_url = 'http://localhost:8000';
$client_id = 'cliente_teste';
$client_secret = 'senha_secreta_123';
$redirect_uri = 'http://localhost:8001/callback.php';

// ============================================================================
// CALLBACK - Recebe o authorization code
// ============================================================================

if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $state = $_GET['state'];
    
    // Trocar code por access_token
    $token_response = file_get_contents(
        $oauth_server_url . '/oauth2_server.php?action=token',
        false,
        stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirect_uri,
                ]),
            ]
        ])
    );
    
    $token_data = json_decode($token_response, true);
    
    if (isset($token_data['access_token'])) {
        $_SESSION['access_token'] = $token_data['access_token'];
        $_SESSION['token_data'] = $token_data;
        
        // Obter informações do usuário
        $userinfo_response = file_get_contents(
            $oauth_server_url . '/oauth2_server.php?action=userinfo',
            false,
            stream_context_create([
                'http' => [
                    'header' => 'Authorization: Bearer ' . $token_data['access_token'],
                ]
            ])
        );
        
        $user_data = json_decode($userinfo_response, true);
        $_SESSION['user_data'] = $user_data;
    }
}

// ============================================================================
// LOGOUT
// ============================================================================

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: callback.php');
    exit;
}

// ============================================================================
// HTML
// ============================================================================

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cliente OAuth2</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .header h1 { font-size: 1.8rem; margin-bottom: 0.5rem; }
        .content { padding: 2rem; }
        .button {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            transition: background 0.2s;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        .button:hover { background: #5568d3; }
        .button.danger {
            background: #dc3545;
        }
        .button.danger:hover {
            background: #c82333;
        }
        .button.secondary {
            background: #6c757d;
        }
        .button.secondary:hover {
            background: #5a6268;
        }
        .section { margin-bottom: 2rem; }
        .section h2 {
            color: #333;
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #f0f4ff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }
        .user-details h3 {
            color: #333;
            margin-bottom: 0.5rem;
        }
        .user-details p {
            color: #666;
            font-size: 0.9rem;
        }
        .token-info {
            background: #f9f9f9;
            padding: 1rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.85rem;
            overflow-x: auto;
            color: #333;
        }
        .token-info strong {
            display: block;
            margin-top: 0.5rem;
            margin-bottom: 0.25rem;
            color: #555;
        }
        .empty-state {
            text-align: center;
            color: #666;
            padding: 2rem;
        }
        .empty-state p {
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: #d4edda;
            color: #155724;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Cliente OAuth2</h1>
            <p>Teste do servidor OAuth2</p>
        </div>
        
        <div class="content">
            <?php if (isset($_SESSION['user_data'])): ?>
                <!-- USUÁRIO AUTENTICADO -->
                <div class="section">
                    <h2>Bem-vindo! ✨</h2>
                    <div class="user-info">
                        <div class="user-avatar">👤</div>
                        <div class="user-details">
                            <h3><?php echo htmlspecialchars($_SESSION['user_data']['name']); ?></h3>
                            <p>📧 <?php echo htmlspecialchars($_SESSION['user_data']['email']); ?></p>
                            <p>🔑 <?php echo htmlspecialchars($_SESSION['user_data']['sub']); ?></p>
                            <span class="status-badge">✓ Autenticado</span>
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <h2>Token de Acesso</h2>
                    <div class="token-info">
                        <strong>Access Token:</strong>
                        <code><?php echo htmlspecialchars(substr($_SESSION['token_data']['access_token'], 0, 20)); ?>...</code>
                        
                        <strong>Tipo:</strong>
                        <code><?php echo htmlspecialchars($_SESSION['token_data']['token_type']); ?></code>
                        
                        <strong>Expira em:</strong>
                        <code><?php echo htmlspecialchars($_SESSION['token_data']['expires_in']); ?> segundos</code>
                        
                        <strong>Scopes:</strong>
                        <code><?php echo htmlspecialchars($_SESSION['token_data']['scope']); ?></code>
                        
                        <strong>Refresh Token:</strong>
                        <code><?php echo htmlspecialchars(substr($_SESSION['token_data']['refresh_token'], 0, 20)); ?>...</code>
                    </div>
                </div>
                
                <div class="section">
                    <h2>Dados Completos</h2>
                    <div class="token-info">
                        <pre><?php echo htmlspecialchars(json_encode($_SESSION['user_data'], JSON_PRETTY_PRINT)); ?></pre>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <a href="?logout=1" class="button danger">🚪 Logout</a>
                    <a href="<?php echo htmlspecialchars($oauth_server_url . '/oauth2_server.php?action=debug'); ?>" class="button secondary" target="_blank">🐛 Ver Debug</a>
                </div>
                
            <?php else: ?>
                <!-- NÃO AUTENTICADO -->
                <div class="empty-state">
                    <p>Você não está autenticado</p>
                    <p style="font-size: 0.95rem; color: #999; margin-bottom: 2rem;">
                        Clique no botão abaixo para iniciar o fluxo de autenticação OAuth2
                    </p>
                    
                    <a href="<?php 
                        echo htmlspecialchars($oauth_server_url . '/oauth2_server.php?action=authorize&' . http_build_query([
                            'client_id' => $client_id,
                            'redirect_uri' => $redirect_uri,
                            'response_type' => 'code',
                            'scope' => 'openid profile email',
                            'state' => bin2hex(random_bytes(16)),
                        ]));
                    ?>" class="button">🔐 Login com OAuth2</a>
                </div>
                
                <hr style="margin: 2rem 0; border: none; border-top: 1px solid #eee;">
                
                <div class="section">
                    <h2>ℹ️ Informações</h2>
                    <p style="color: #666; line-height: 1.6; margin-bottom: 1rem;">
                        Este cliente demonstra o fluxo de Authorization Code do OAuth2:
                    </p>
                    <ol style="color: #666; margin-left: 1.5rem; line-height: 1.8;">
                        <li>Você clica em "Login com OAuth2"</li>
                        <li>É redirecionado para o servidor OAuth2</li>
                        <li>Você faz login com suas credenciais</li>
                        <li>Recebe um authorization code</li>
                        <li>O cliente troca o code por um access token</li>
                        <li>O access token é usado para obter seus dados</li>
                    </ol>
                </div>
                
                <div style="text-align: center; margin-top: 2rem;">
                    <a href="<?php echo htmlspecialchars($oauth_server_url . '/oauth2_server.php?action=debug'); ?>" class="button secondary" target="_blank">🐛 Ver Debug do Servidor</a>
                </div>
                
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
