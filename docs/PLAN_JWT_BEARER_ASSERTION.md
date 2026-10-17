# Plano: JWT Bearer Assertion (RFC 7523)

**Projeto:** `php-oauth`  
**Escopo:** `server/oauth2_complete.php`, `client/oauth2_client.php`  
**Status:** Planejamento para Implementação  
**Data:** 16 de Outubro de 2026  
**Especificação:** RFC 7523 - JSON Web Token (JWT) Profile for OAuth 2.0 Client Authentication and Authorization Grants

---

## 1. Visão Geral

O **JWT Bearer Assertion** (RFC 7523) permite que um cliente obtenha um access token enviando um JWT assinado como credencial, ao invés de utilizar `client_secret` tradicional. Este fluxo é amplamente utilizado em:

- **Service Accounts** (Google Cloud Platform, AWS, Azure)
- **Integrações Enterprise** (Salesforce, Box, Workday)
- **Comunicação M2M segura** com chaves assimétricas (RSA/ECDSA)
- **Sistemas com rotação automática de credenciais**

### Vantagens vs Client Secret

| Aspecto | Client Secret | JWT Bearer Assertion |
|---|---|---|
| Autenticação | Segredo compartilhado | Assinatura criptográfica (chave privada) |
| Rotação | Manual/complexa | Pode ter expiração curta no próprio JWT (`exp`) |
| Segurança | Vulnerável se exposto | Chave privada nunca é enviada, apenas assinatura |
| Auditoria | Difícil de rastrear origem | JWT pode incluir claims específicas (`jti`, `aud`, `iss`) |
| Padrão Enterprise | Básico | Recomendado para integrações críticas |

---

## 2. Objetivos

1. Implementar o **JWT Bearer Grant** (`grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`) conforme RFC 7523
2. Suportar autenticação de cliente via JWT Bearer Assertion (`client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer`)
3. Suportar algoritmos de assinatura **HS256** (HMAC-SHA256) e **RS256** (RSA-SHA256)
4. Validar todos os claims obrigatórios do JWT (iss, sub, aud, exp, iat)
5. Adicionar exemplos e interface no cliente para facilitar testes
6. Manter compatibilidade com fluxos existentes (sem quebrar código atual)

---

## 3. Arquitetura do Fluxo

### 3.1 Grant Type: JWT Bearer Authorization Grant

O cliente envia um JWT assinado representando uma autorização (assertion) para obter um access token.

```mermaid
sequenceDiagram
    autonumber
    participant C as Cliente (M2M/App)
    participant S as Servidor OAuth2 (:8000)

    C->>C: Gera JWT com claims (iss, sub, aud, exp, iat, scope)
    C->>C: Assina JWT com chave privada (RS256) ou client_secret (HS256)
    C->>S: POST /oauth2_complete.php?action=token
           grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer
           assertion=<JWT_ASSINADO>
           scope=openid profile (opcional)
    S->>S: Valida assinatura do JWT
    S->>S: Valida claims (iss, sub, aud, exp, nbf)
    S->>S: Verifica se issuer (iss) corresponde a um client válido
    S-->>C: 200 OK {access_token, token_type, expires_in, scope}
```

### 3.2 Client Authentication via JWT Bearer

O cliente autentica-se no token endpoint enviando um JWT assertion ao invés de `client_secret`.

```mermaid
sequenceDiagram
    autonumber
    participant C as Cliente
    participant S as Servidor OAuth2

    C->>C: Gera JWT com iss=sub=client_id, aud=token_endpoint
    C->>C: Assina com chave privada/segredo
    C->>S: POST /oauth2_complete.php?action=token
           grant_type=authorization_code (ou outro)
           code=ABC123
           client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer
           client_assertion=<JWT_ASSINADO>
           redirect_uri=...
    S->>S: Valida client assertion (assinatura + claims)
    S->>S: Autentica cliente com base no iss
    S->>S: Processa grant type normalmente
    S-->>C: Retorna tokens
```

---

## 4. Estrutura do JWT (Claims)

### 4.1 Claims Obrigatórios (RFC 7523)

| Claim | Tipo | Descrição |
|---|---|---|
| `iss` | string | Issuer - Identificador do cliente que emite o assertion. Deve corresponder a `client_id`. |
| `sub` | string | Subject - Também deve corresponder ao `client_id` (para client authentication) ou ao usuário (para authorization grant). |
| `aud` | string/array | Audience - O token endpoint do servidor OAuth2. Ex: `http://localhost:8000/oauth2_complete.php?action=token` |
| `exp` | int (timestamp) | Expiration time - JWT expira. Recomendado curto (60s - 300s). |
| `iat` | int (timestamp) | Issued at - Momento de emissão. |

### 4.2 Claims Recomendados/Opcionais

| Claim | Tipo | Descrição |
|---|---|---|
| `nbf` | int | Not before - Não aceitar antes deste tempo. |
| `jti` | string | JWT ID - Único por token, pode ser usado para replay protection (opcional). |
| `scope` | string | Escopos solicitados (apenas para authorization grant). |

### 4.3 Exemplo de JWT Payload (HS256)

```json
{
  "iss": "cliente_teste",
  "sub": "cliente_teste",
  "aud": "http://localhost:8000/oauth2_complete.php?action=token",
  "exp": 1729100000,
  "iat": 1729099700,
  "jti": "jwt_123456"
}
```

### 4.4 Exemplo de JWT Payload (Authorization Grant com usuário)

```json
{
  "iss": "cliente_teste",
  "sub": "usuario1",
  "aud": "http://localhost:8000/oauth2_complete.php?action=token",
  "exp": 1729100000,
  "iat": 1729099700,
  "scope": "openid profile email"
}
```

---

## 5. Modificações no Código

### 5.1 `server/oauth2_complete.php`

#### 5.1.1 Adicionar funções utilitárias para JWT

Adicionar após `base64UrlDecode()` (linha ~175):

```php
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

function generateJwt(array $payload, $client_id, $algorithm = 'HS256') {
    global $clients;
    $header = ['alg' => $algorithm, 'typ' => 'JWT'];
    
    $headerEncoded = base64UrlEncode(json_encode($header));
    $payloadEncoded = base64UrlEncode(json_encode($payload));
    $signature = signJwt($headerEncoded . '.' . $payloadEncoded, $client_id, $algorithm);
    $signatureEncoded = base64UrlEncode($signature);
    
    return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
}

function signJwt($data, $client_id, $algorithm = 'HS256') {
    global $clients;
    $client = $clients[$client_id] ?? null;
    if (!$client) return '';
    
    $key = $client['client_secret'] ?? '';
    
    if ($algorithm === 'HS256') {
        return hash_hmac('sha256', $data, $key, true);
    } elseif ($algorithm === 'RS256') {
        // Para RS256, precisamos de chave privada
        $privateKey = $client['jwt_private_key'] ?? null;
        if (!$privateKey) return '';
        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        return $signature;
    }
    return '';
}

function verifyJwt($jwt, $client_id = null) {
    global $clients;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return ['valid' => false, 'error' => 'invalid_format'];
    }
    
    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
    
    // Decodificar header
    $header = json_decode(base64UrlDecode($headerEncoded), true);
    if (!$header || !isset($header['alg'])) {
        return ['valid' => false, 'error' => 'invalid_header'];
    }
    
    $algorithm = $header['alg'];
    
    // Decodificar payload
    $payload = json_decode(base64UrlDecode($payloadEncoded), true);
    if (!$payload) {
        return ['valid' => false, 'error' => 'invalid_payload'];
    }
    
    // Determinar client_id pelo iss se não fornecido
    if ($client_id === null && isset($payload['iss'])) {
        $client_id = $payload['iss'];
    }
    
    if (!$client_id || !isset($clients[$client_id])) {
        return ['valid' => false, 'error' => 'invalid_issuer'];
    }
    
    // Verificar assinatura
    $data = $headerEncoded . '.' . $payloadEncoded;
    $signature = base64UrlDecode($signatureEncoded);
    
    if (!verifyJwtSignature($data, $signature, $client_id, $algorithm)) {
        return ['valid' => false, 'error' => 'invalid_signature'];
    }
    
    // Validar claims
    $now = time();
    if (isset($payload['exp']) && $payload['exp'] < $now) {
        return ['valid' => false, 'error' => 'token_expired'];
    }
    if (isset($payload['nbf']) && $payload['nbf'] > $now) {
        return ['valid' => false, 'error' => 'token_not_yet_valid'];
    }
    if (isset($payload['iat']) && $payload['iat'] > $now + 60) { // clock skew 60s
        return ['valid' => false, 'error' => 'invalid_issued_at'];
    }
    
    return ['valid' => true, 'payload' => $payload, 'client_id' => $client_id];
}

function verifyJwtSignature($data, $signature, $client_id, $algorithm) {
    global $clients;
    $client = $clients[$client_id] ?? null;
    if (!$client) return false;
    
    if ($algorithm === 'HS256') {
        $expected = hash_hmac('sha256', $data, $client['client_secret'] ?? '', true);
        return hash_equals($expected, $signature);
    } elseif ($algorithm === 'RS256') {
        $publicKey = $client['jwt_public_key'] ?? null;
        if (!$publicKey) return false;
        return openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
    return false;
}
```

#### 5.1.2 Modificar `handleToken()` para suportar JWT Bearer

Adicionar suporte a `client_assertion` para autenticação de cliente (antes da validação de client_secret):

```php
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

// Autenticação via JWT Bearer Assertion (RFC 7523 - Client Authentication)
if ($client_assertion_type === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' && $client_assertion) {
    $jwtResult = verifyJwt($client_assertion);
    if (!$jwtResult['valid']) {
        return jsonResponse(['error' => 'invalid_client', 'error_description' => 'Client assertion inválido: ' . ($jwtResult['error'] ?? 'unknown')], 401);
    }
    $payload = $jwtResult['payload'];
    // iss e sub devem ser iguais ao client_id
    if (($payload['iss'] ?? null) !== ($payload['sub'] ?? null)) {
        return jsonResponse(['error' => 'invalid_client', 'error_description' => 'iss e sub devem ser idênticos'], 401);
    }
    if ($client_id && $client_id !== $payload['iss']) {
        return jsonResponse(['error' => 'invalid_client', 'error_description' => 'client_id não corresponde ao iss'], 401);
    }
    $client_id = $payload['iss'];
    // Para client assertion, não precisamos de client_secret
    $client_secret = $clients[$client_id]['client_secret'] ?? 'jwt-bearer-auth'; // bypass
}
```

E ajustar a validação de client_secret para pular quando usando JWT assertion:

```php
if (!$client_id || !isset($clients[$client_id])) {
    return jsonResponse(['error' => 'invalid_client', 'error_description' => 'Client ID inválido'], 400);
}

// Só valida client_secret se não estiver usando client assertion
if (!$client_assertion && $clients[$client_id]['client_secret'] !== $client_secret) {
    return jsonResponse(['error' => 'invalid_client', 'error_description' => 'Client secret inválido'], 401);
}
```

#### 5.1.3 Adicionar grant_type `jwt-bearer`

Adicionar após `client_credentials` (aprox linha 1052):

```php
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
    
    // Validar audience - deve apontar para token endpoint
    $tokenEndpoint = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . '?action=token';
    $aud = $payload['aud'] ?? null;
    if ($aud !== $tokenEndpoint && (!is_array($aud) || !in_array($tokenEndpoint, $aud))) {
        return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'Audience inválido'], 400);
    }
    
    // Para JWT Bearer Grant, sub pode ser username (diferente de iss)
    $username = $payload['sub'] ?? $payload['iss'] ?? null;
    
    // Usar scope do JWT se presente, senão do request
    if (isset($payload['scope'])) {
        $scope = $payload['scope'];
    }
    if (!$scope) {
        $scope = 'openid profile';
    }
}
```

#### 5.1.4 Atualizar `handleWellKnown()` para incluir JWT Bearer

Adicionar suporte a JWT Bearer nos metadados discovery:

```php
'grant_types_supported' => [
    'authorization_code', 
    'implicit', 
    'refresh_token', 
    'password', 
    'client_credentials',
    'urn:ietf:params:oauth:grant-type:device_code',
    'urn:ietf:params:oauth:grant-type:jwt-bearer'
],
'token_endpoint_auth_methods_supported' => [
    'client_secret_basic',
    'client_secret_post',
    'client_secret_jwt',
    'private_key_jwt'
],
'token_endpoint_auth_signing_alg_values_supported' => ['HS256', 'RS256'],
'code_challenge_methods_supported' => ['plain', 'S256'],
```

#### 5.1.5 Atualizar configuração de clientes (opcional)

Adicionar campos para chaves RSA nos clientes (para RS256):

```php
$clients = [
    'cliente_teste' => [
        'client_id' => 'cliente_teste',
        'client_secret' => 'senha_secreta_123',
        'client_name' => 'Cliente de Teste',
        'redirect_uris' => [
            'http://localhost:8001/client/oauth2_client.php',
            'http://localhost:8001/callback.php',
        ],
        'response_types' => ['code', 'token'],
        'grant_types' => ['authorization_code', 'refresh_token', 'implicit', 'password', 'client_credentials', 'urn:ietf:params:oauth:grant-type:jwt-bearer'],
        'allowed_scopes' => ['openid', 'profile', 'email', 'phone', 'address'],
        // Para RS256 (opcional - pode ser carregado de arquivo)
        'jwt_private_key' => null,
        'jwt_public_key' => null,
    ],
];
```

---

### 5.2 `client/oauth2_client.php`

Adicionar nova aba/interface para testar JWT Bearer Assertion.

#### 5.2.1 Adicionar botão/aba no HTML

Localizar onde estão as abas (provavelmente perto das outras) e adicionar:

```html
<button class="tab-button" onclick="showTab('jwt-bearer')">🔑 JWT Bearer</button>
```

#### 5.2.2 Adicionar seção da aba JWT Bearer

```html
<div id="jwt-bearer" class="tab-content">
    <h3>JWT Bearer Assertion (RFC 7523)</h3>
    <p>Obtenha tokens usando um JWT assinado como assertion.</p>
    
    <div class="form-group">
        <label>Grant Type:</label>
        <select id="jwt_grant_type">
            <option value="urn:ietf:params:oauth:grant-type:jwt-bearer">JWT Bearer Grant</option>
        </select>
    </div>
    
    <div class="form-group">
        <label>Signing Algorithm:</label>
        <select id="jwt_alg" onchange="toggleJwtKeyFields()">
            <option value="HS256">HS256 (HMAC-SHA256 - usa client_secret)</option>
            <option value="RS256">RS256 (RSA-SHA256 - usa chave privada)</option>
        </select>
    </div>
    
    <div class="form-group" id="jwt_private_key_group" style="display:none;">
        <label>Chave Privada RSA (PEM):</label>
        <textarea id="jwt_private_key" rows="6" placeholder="-----BEGIN PRIVATE KEY-----&#10;...&#10;-----END PRIVATE KEY-----"></textarea>
    </div>
    
    <div class="form-group">
        <label>Issuer (iss):</label>
        <input type="text" id="jwt_iss" value="cliente_teste">
    </div>
    
    <div class="form-group">
        <label>Subject (sub):</label>
        <input type="text" id="jwt_sub" value="usuario1" placeholder="Para grant: pode ser username. Para client auth: igual a iss">
    </div>
    
    <div class="form-group">
        <label>Audience (aud):</label>
        <input type="text" id="jwt_aud" value="http://localhost:8000/oauth2_complete.php?action=token">
    </div>
    
    <div class="form-group">
        <label>Scope:</label>
        <input type="text" id="jwt_scope" value="openid profile email">
    </div>
    
    <div class="form-group">
        <label>Expiração (segundos):</label>
        <input type="number" id="jwt_exp_seconds" value="300" min="60" max="3600">
    </div>
    
    <button onclick="generateAndRequestJwtBearer()">Gerar JWT e Solicitar Token</button>
    <button onclick="testClientAssertion()">Testar Client Assertion (Auth Code)</button>
    
    <div class="form-group">
        <label>JWT Gerado:</label>
        <textarea id="jwt_generated" rows="4" readonly></textarea>
    </div>
</div>
```

#### 5.2.3 Adicionar funções JavaScript

```javascript
function base64UrlEncode(str) {
    let base64 = btoa(unescape(encodeURIComponent(str)));
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

async function generateJwtClientSide() {
    const alg = document.getElementById('jwt_alg').value;
    const iss = document.getElementById('jwt_iss').value;
    const sub = document.getElementById('jwt_sub').value;
    const aud = document.getElementById('jwt_aud').value;
    const scope = document.getElementById('jwt_scope').value;
    const expSeconds = parseInt(document.getElementById('jwt_exp_seconds').value);
    
    const now = Math.floor(Date.now() / 1000);
    const header = { alg: alg, typ: 'JWT' };
    const payload = {
        iss: iss,
        sub: sub,
        aud: aud,
        exp: now + expSeconds,
        iat: now,
        jti: 'jwt_' + Math.random().toString(36).substring(2, 15)
    };
    if (scope) payload.scope = scope;
    
    const headerEnc = base64UrlEncode(JSON.stringify(header));
    const payloadEnc = base64UrlEncode(JSON.stringify(payload));
    const data = headerEnc + '.' + payloadEnc;
    
    let signatureEnc;
    if (alg === 'HS256') {
        // Usar Web Crypto API para HMAC
        const key = await crypto.subtle.importKey(
            'raw',
            new TextEncoder().encode('senha_secreta_123'),
            { name: 'HMAC', hash: 'SHA-256' },
            false,
            ['sign']
        );
        const signature = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(data));
        const sigArray = Array.from(new Uint8Array(signature));
        const sigBase64 = btoa(String.fromCharCode.apply(null, sigArray));
        signatureEnc = sigBase64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    } else {
        // RS256 - requer chave privada; para demo, pode mostrar aviso
        alert('RS256 no cliente browser requer importação de chave privada (Web Crypto API com subtle). Para testes, use HS256 ou gere JWT no servidor.');
        signatureEnc = '';
    }
    
    const jwt = data + '.' + signatureEnc;
    document.getElementById('jwt_generated').value = jwt;
    return jwt;
}

async function generateAndRequestJwtBearer() {
    const jwt = await generateJwtClientSide();
    if (!jwt || jwt.endsWith('.')) return;
    
    const formData = new URLSearchParams();
    formData.append('grant_type', 'urn:ietf:params:oauth:grant-type:jwt-bearer');
    formData.append('assertion', jwt);
    formData.append('client_id', 'cliente_teste');
    // Não precisa client_secret com JWT assertion (pode usar client auth também)
    
    try {
        const response = await fetch(TOKEN_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        });
        const data = await response.json();
        displayResult(data);
    } catch (error) {
        displayResult({ error: 'request_failed', error_description: error.message });
    }
}
```

> **Nota:** Para RS256 no navegador, é necessário usar Web Crypto API com chaves importadas. Para simplificar os testes, recomendamos priorizar **HS256** inicialmente.

---

## 6. Testes e Validação

### 6.1 Testes com cURL

#### Teste 1: JWT Bearer Grant (HS256)

Gerar JWT manualmente (pode usar Python ou PHP para gerar):

```bash
# Gerar JWT com PHP
php << 'PHP'
<?php
$header = ['alg'=>'HS256','typ'=>'JWT'];
$payload = [
  'iss'=>'cliente_teste',
  'sub'=>'usuario1',
  'aud'=>'http://localhost:8000/oauth2_complete.php?action=token',
  'exp'=>time()+300,
  'iat'=>time(),
  'scope'=>'openid profile email'
];
$h = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
$p = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", 'senha_secreta_123', true)), '+/', '-_'), '=');
echo "$h.$p.$sig";
PHP
```

Solicitar token:

```bash
curl -X POST "http://localhost:8000/oauth2_complete.php?action=token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer" \
  -d "assertion=SEU_JWT_AQUI" \
  -d "client_id=cliente_teste"
```

**Resultado esperado:** HTTP 200 com `access_token`, `token_type`, `expires_in`, `scope`, `id_token` (se openid)

#### Teste 2: Client Authentication via JWT Bearer

```bash
# Gerar JWT para client auth (iss=sub=client_id)
php << 'PHP'
<?php
$header = ['alg'=>'HS256','typ'=>'JWT'];
$payload = [
  'iss'=>'cliente_teste',
  'sub'=>'cliente_teste',
  'aud'=>'http://localhost:8000/oauth2_complete.php?action=token',
  'exp'=>time()+300,
  'iat'=>time(),
  'jti'=>'test123'
];
$h = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
$p = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", 'senha_secreta_123', true)), '+/', '-_'), '=');
echo "$h.$p.$sig";
PHP
```

Usar com Authorization Code:

```bash
curl -X POST "http://localhost:8000/oauth2_complete.php?action=token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=authorization_code" \
  -d "code=AUTH_CODE" \
  -d "redirect_uri=http://localhost:8001/client/oauth2_client.php" \
  -d "client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer" \
  -d "client_assertion=SEU_JWT_AQUI"
```

#### Teste 3: Casos de Erro

| Caso | Esperado |
|---|---|
| JWT expirado (`exp` no passado) | `invalid_grant` / `token_expired` |
| Audience incorreto (`aud`) | `invalid_grant` com "Audience inválido" |
| Assinatura inválida | `invalid_grant` / `invalid_signature` |
| iss != sub (client auth) | `invalid_client` |
| JWT sem assertion | `invalid_request` |
| nbf no futuro | `token_not_yet_valid` |

### 6.2 Testes Manuais no Cliente Web

1. Iniciar servidores: `php -S localhost:8000 server/oauth2_complete.php` e `php -S localhost:8001 client/oauth2_client.php`
2. Acessar `http://localhost:8001/client/oauth2_client.php`
3. Ir para aba "🔑 JWT Bearer"
4. Manter HS256, preencher campos (sub=usuario1)
5. Clicar "Gerar JWT e Solicitar Token"
6. Verificar se tokens são retornados corretamente
7. Testar com `sub=cliente_teste` para client auth scenarios

---

## 7. Considerações de Segurança

| Risco | Mitigação |
|---|---|
| **Replay Attacks** | Usar `jti` (JWT ID) único e armazenar em cache por tempo curto (expiração). Não obrigatório na RFC mas recomendado. |
| **Clock Skew** | Permitir diferença de até 60s em `iat`, `nbf`, `exp`. |
| **Chaves fracas** | Para HS256, usar `client_secret` forte. Para RS256, usar chaves >= 2048 bits. |
| **JWT longo prazo** | Sempre usar `exp` curto (recomendado 5 minutos máx. para assertions). |
| **Token injection** | Validar todos os claims estritamente conforme RFC 7523. |
| **Algoritmo confusion** | Não aceitar `alg: none`. Validar algoritmo contra configuração do cliente. |

---

## 8. Ordem de Implementação Recomendada

| Etapa | Tarefa | Complexidade | Testável |
|---|---|---|---|
| 1 | Adicionar funções JWT (generate/sign/verify) | Média | Sim (unitário via PHP) |
| 2 | Modificar `handleToken()` para client assertion | Média | Sim (cURL) |
| 3 | Adicionar grant_type `jwt-bearer` | Média | Sim (cURL) |
| 4 | Atualizar `handleWellKnown()` | Baixa | Sim |
| 5 | Adicionar interface no cliente (`oauth2_client.php`) | Baixa-Média | Sim (manual) |
| 6 | Testes completos (HS256 + casos de erro) | Baixa | Sim |
| 7 | Suporte a RS256 (opcional avançado) | Alta | Opcional |

---

## 9. Status de Implementação

- [ ] Funções utilitárias JWT
- [ ] Client Authentication via JWT Bearer
- [ ] JWT Bearer Authorization Grant
- [ ] Well-Known atualizado
- [ ] Interface cliente web
- [ ] Testes com cURL validados
- [ ] Documentação atualizada (README/GUIA)

---

## 10. Referências

- [RFC 7523 - JSON Web Token (JWT) Profile for OAuth 2.0 Client Authentication and Authorization Grants](https://datatracker.ietf.org/doc/html/rfc7523)
- [RFC 7519 - JSON Web Token (JWT)](https://datatracker.ietf.org/doc/html/rfc7519)
- [RFC 7515 - JSON Web Signature (JWS)](https://datatracker.ietf.org/doc/html/rfc7515)
- [OAuth 2.0 Security Best Current Practice](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-security-topics)
