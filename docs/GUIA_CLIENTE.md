# 🎮 Guia de Uso - Cliente OAuth2 Completo

## 🚀 Como Começar

### Terminal 1 - Servidor OAuth2
```bash
cd /caminho/php-oauth
php -S localhost:8000 server/oauth2_complete.php
```

### Terminal 2 - Cliente
```bash
cd /caminho/php-oauth
php -S localhost:8001 client/oauth2_client.php
```

### Browser
Acesse: **http://localhost:8001/client/oauth2_client.php**

---

## 📖 Entendendo os Grant Types

### 1️⃣ Authorization Code Flow (📝 Mais Seguro)

**O que é:** O fluxo mais seguro do OAuth2, recomendado para web.

**Como usar no cliente:**
1. Clique em **"📝 Code Flow"**
2. Clique em **"🔐 Fazer Login com Authorization Code"**
3. Você será redirecionado para o servidor
4. Faça login com `usuario1 / senha123`
5. Autorize as permissões solicitadas
6. Será redirecionado de volta autenticado

**Parâmetros que pode customizar:**
```
✓ Usar NONCE → Adiciona validação OpenID Connect
✓ Usar STATE → Proteção contra CSRF (sempre ativado)
✓ Pedir Consentimento → Força tela de permissões
```

**O que acontece nos bastidores:**

```bash
# 1. Frontend redireciona para authorize
GET /oauth2_complete.php?action=authorize
  &client_id=cliente_teste
  &response_type=code
  &redirect_uri=http://localhost:8001/oauth2_client.php
  &scope=openid profile email
  &state=random_state
  &nonce=random_nonce

# 2. Usuário faz login → recebe AUTHORIZATION CODE

# 3. Backend troca code por token
POST /oauth2_complete.php?action=token
  grant_type=authorization_code
  code=RECEIVED_CODE
  client_id=cliente_teste
  client_secret=senha_secreta_123
  redirect_uri=http://localhost:8001/oauth2_client.php

# 4. Resposta com tokens
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "...",
  "scope": "openid profile email",
  "id_token": "..."
}
```

**Quando usar:**
- ✅ Web tradicional
- ✅ Aplicações backend
- ✅ Apps com servidor confiável

---

### 2️⃣ Implicit Flow (⚡ Para SPAs)

**O que é:** Fluxo rápido onde o token é retornado diretamente na URL. Menos seguro mas adequado para SPAs.

**Como usar no cliente:**
1. Clique em **"⚡ Implicit Flow"**
2. Clique em **"⚡ Fazer Login com Implicit Flow"**
3. Faça login como de costume
4. Token aparece na URL (fragment: `#access_token=...`)

**O que acontece:**

```bash
# 1. Redirecionar com response_type=token
GET /oauth2_complete.php?action=authorize
  &client_id=cliente_teste
  &response_type=token
  &redirect_uri=http://localhost:8001/oauth2_client.php
  &scope=openid profile
  &state=random_state

# 2. Após login → token retorna na URL
http://localhost:8001/oauth2_client.php
  #access_token=TOKEN
  &token_type=Bearer
  &expires_in=3600
  &scope=openid profile
  &state=random_state

# 3. Frontend extrai token da URL (via JavaScript)
const token = new URLSearchParams(window.location.hash.slice(1)).get('access_token');

# 4. Usar token diretamente (sem refresh)
GET /oauth2_complete.php?action=userinfo
  Authorization: Bearer TOKEN
```

**Características:**
- Token vem diretamente (sem code intermediário)
- Resposta usa fragment (#) não query (?)
- Sem refresh token (token expira e precisa fazer login de novo)
- Mais rápido, mas menos seguro

**Quando usar:**
- ✅ Single Page Apps (React, Vue, Angular)
- ✅ JavaScript no navegador
- ❌ Não para apps sensíveis com dados críticos

---

### 3️⃣ Password Flow (🔐 App Nativa/Desktop)

**O que é:** Usuário digita senha diretamente na app. Menos seguro, mas conveniente para apps nativas.

**Como usar no cliente:**
1. Clique em **"🔐 Password Flow"**
2. Digite `usuario1` e `senha123`
3. Clique em **"✓ Login com Senha"**

**O que acontece:**

```bash
# 1. Enviar credenciais diretamente
POST /oauth2_complete.php?action=token
  grant_type=password
  username=usuario1
  password=senha123
  scope=openid profile email
  client_id=cliente_teste
  client_secret=senha_secreta_123

# 2. Retorna token (sem redirect)
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "...",
  "scope": "openid profile email"
}

# 3. Usar token
GET /oauth2_complete.php?action=userinfo
  Authorization: Bearer TOKEN
```

**⚠️ Segurança:**
- Usuario digita senha na APP
- Não há página de login do servidor
- Menos seguro que Code Flow
- Use apenas com apps que você confia

**Quando usar:**
- ✅ Apps desktop (Electron, etc)
- ✅ Apps móveis nativas
- ✅ CLI tools
- ❌ Web apps públicas
- ❌ Apps de terceiros

---

### 4️⃣ Client Credentials Flow (🔧 Backend-to-Backend)

**O que é:** Autenticação entre aplicações, sem usuário final.

**Como usar no cliente:**
1. Clique em **"🔧 Client Flow"**
2. Clique em **"🔧 Autenticar como Cliente"**

**O que acontece:**

```bash
# 1. Enviar credenciais do cliente
POST /oauth2_complete.php?action=token
  grant_type=client_credentials
  client_id=cliente_teste
  client_secret=senha_secreta_123
  scope=openid

# 2. Retorna token (sem usuário)
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "openid"
  // Sem refresh_token!
}

# 3. Usar token
GET /oauth2_complete.php?action=userinfo
  Authorization: Bearer TOKEN
```

**Características:**
- Sem usuário final envolvido
- Autenticação entre máquinas
- Sem refresh token
- Rápido e direto

**Quando usar:**
- ✅ Integração entre APIs
- ✅ Serviços backend
- ✅ Cron jobs
- ✅ Comunicação máquina-máquina
- ❌ Nunca com usuário final

---

## 🔄 Renovar Token (Refresh Token)

Quando você está autenticado e o token está prestes a expirar:

1. Clique em **"🔄 Renovar Token"**
2. Um novo access_token é obtido automaticamente
3. Você continua autenticado

**Como funciona:**

```bash
POST /oauth2_complete.php?action=token
  grant_type=refresh_token
  refresh_token=SEU_REFRESH_TOKEN
  client_id=cliente_teste
  client_secret=senha_secreta_123

# Resposta
{
  "access_token": "NEW_TOKEN",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "NEW_REFRESH_TOKEN",
  "scope": "openid profile email"
}
```

**Notas:**
- Refresh token permite renovar sem fazer login de novo
- Não precisa de username/password
- Novo refresh token pode ser emitido
- Válido apenas para Authorization Code e Password flows

---

## 📊 Entender a Resposta

Quando autenticado, você vê:

### Token de Acesso
```
Access Token: eyJ0eXAi...
Type: Bearer
Expires: 3600 segundos (1 hora)
Scope: openid profile email phone address
```

### Refresh Token
```
RefreshToken: a3f8c2d4...
Usado para renovar o access_token quando expirar
```

### Dados do Usuário (JSON)
```json
{
  "sub": "usuario1",
  "name": "Usuário Um",
  "email": "usuario1@example.com",
  "email_verified": true,
  "phone_number": "+55 11 98765-4321",
  "phone_number_verified": false,
  "address": "Rua A, 123",
  "picture": "https://via.placeholder.com/150",
  "locale": "pt_BR"
}
```

---

## 🧪 Exemplos de Código

### JavaScript - Authorization Code Flow

```javascript
// 1. Redirecionar para login
function login() {
    const state = generateRandomString();
    const nonce = generateRandomString();
    
    sessionStorage.setItem('oauth_state', state);
    sessionStorage.setItem('oauth_nonce', nonce);
    
    const params = new URLSearchParams({
        client_id: 'cliente_teste',
        redirect_uri: window.location.origin + '/callback',
        response_type: 'code',
        scope: 'openid profile email',
        state: state,
        nonce: nonce
    });
    
    window.location.href = 'http://localhost:8000/oauth2_complete.php?action=authorize&' + params;
}

// 2. No callback (backend)
async function handleCallback(code) {
    const response = await fetch('/api/exchange-code', {
        method: 'POST',
        body: JSON.stringify({ code })
    });
    return response.json();
}

// 3. Usar token
async function getUserInfo(accessToken) {
    const response = await fetch('http://localhost:8000/oauth2_complete.php?action=userinfo', {
        headers: {
            'Authorization': `Bearer ${accessToken}`
        }
    });
    return response.json();
}

function generateRandomString() {
    return Math.random().toString(36).substr(2, 9);
}
```

### PHP - Password Flow

```php
<?php
$username = 'usuario1';
$password = 'senha123';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://localhost:8000/oauth2_complete.php?action=token',
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'password',
        'username' => $username,
        'password' => $password,
        'scope' => 'openid profile email',
        'client_id' => 'cliente_teste',
        'client_secret' => 'senha_secreta_123',
    ]),
    CURLOPT_RETURNTRANSFER => 1,
]);

$response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($response, true);
$access_token = $token_data['access_token'];

// Usar token
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://localhost:8000/oauth2_complete.php?action=userinfo',
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access_token],
    CURLOPT_RETURNTRANSFER => 1,
]);
$userinfo = json_decode(curl_exec($ch), true);
curl_close($ch);

print_r($userinfo);
?>
```

### cURL - Client Credentials

```bash
curl -X POST http://localhost:8000/oauth2_complete.php?action=token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=client_credentials" \
  -d "client_id=cliente_teste" \
  -d "client_secret=senha_secreta_123" \
  -d "scope=openid"

# Resultado:
# {
#   "access_token": "...",
#   "token_type": "Bearer",
#   "expires_in": 3600,
#   "scope": "openid"
# }
```

---

## 📋 Parâmetros do Authorization Endpoint

Ao fazer login, você pode passar estes parâmetros customizados:

| Parâmetro | Descrição | Exemplo |
|-----------|-----------|---------|
| `client_id` | ID do cliente (obrigatório) | `cliente_teste` |
| `redirect_uri` | URI de callback (obrigatório) | `http://localhost:8001/...` |
| `response_type` | Tipo de resposta | `code`, `token`, `id_token` |
| `scope` | Permissões solicitadas | `openid profile email phone` |
| `state` | Anti-CSRF (obrigatório) | `xyz123...` |
| `nonce` | Anti-replay (OpenID) | `abc456...` |
| `prompt` | Comportamento | `login`, `consent`, `none` |
| `login_hint` | Dica de usuário | `usuario1` |
| `display` | Modo exibição | `page`, `popup`, `touch` |
| `max_age` | Idade máxima auth | `3600` |

---

## 🔍 Debugar

1. **Ver Storage do Servidor:**
   - Acesse: http://localhost:8000/oauth2_complete.php?action=debug

2. **Ver Endpoints:**
   - Acesse: http://localhost:8000/oauth2_complete.php?action=well-known

3. **Ver Requests no Browser:**
   - Abra DevTools (F12)
   - Aba Network
   - Veja cada request/response

4. **Ver Tokens e Dados:**
   - Quando autenticado, aparecem na tela

---

## ⚠️ Erros Comuns

| Erro | Causa | Solução |
|------|-------|---------|
| `invalid_client` | Client ID/Secret errado | Verifique credenciais |
| `invalid_redirect_uri` | URI não registrada | Use `http://localhost:8001/oauth2_client.php` |
| `State mismatch` | CSRF - state alterado | Verifique sessão/cookies |
| `invalid_grant` | Code expirou | Code expira em 10 minutos |
| `token_expired` | Token expirou | Use refresh_token |

---

## 📚 Resumo Visual dos Flows

```
AUTHORIZATION CODE (Mais Seguro)
┌─────────────────────────────────────────────────┐
│ 1. Redirecionar para authorize                   │
│ 2. Login & Consentimento                         │
│ 3. Recebe authorization code                     │
│ 4. Backend troca code por token                  │
│ 5. Usa access_token para acessar API             │
└─────────────────────────────────────────────────┘

IMPLICIT (Rápido)
┌─────────────────────────────────────────────────┐
│ 1. Redirecionar para authorize                   │
│ 2. Login & Consentimento                         │
│ 3. Token retorna na URL (#access_token=...)      │
│ 4. Usa token imediatamente                       │
│ 5. Sem refresh token (expira, faz login de novo) │
└─────────────────────────────────────────────────┘

PASSWORD (Simples)
┌─────────────────────────────────────────────────┐
│ 1. Digita username e password na app             │
│ 2. App envia para token endpoint                 │
│ 3. Recebe access_token diretamente               │
│ 4. Usa token para acessar API                    │
│ 5. Pode renovar com refresh_token                │
└─────────────────────────────────────────────────┘

CLIENT CREDENTIALS (Backend)
┌─────────────────────────────────────────────────┐
│ 1. App envia client_id + client_secret           │
│ 2. Sem usuário final                             │
│ 3. Recebe access_token                           │
│ 4. Usa token para acessar API                    │
│ 5. Sem refresh token                             │
└─────────────────────────────────────────────────┘
```

---

## ✅ Checklist - O Que Você Pode Fazer Agora

- ✅ Login com Authorization Code Flow
- ✅ Login com Implicit Flow
- ✅ Login com Password Flow
- ✅ Autenticar com Client Credentials
- ✅ Renovar token com Refresh Token
- ✅ Customizar parâmetros (nonce, state, prompt)
- ✅ Ver dados do usuário retornados
- ✅ Fazer logout
- ✅ Testar diferentes scopes
- ✅ Debugar requisições

---

## 🚀 Próximos Passos

1. **Teste todos os 4 flows** - Entenda as diferenças
2. **Customize parâmetros** - Veja como afetam o fluxo
3. **Analise requisições** - Use DevTools para entender
4. **Integrate em suas apps** - Use o conhecimento adquirido
5. **Adicione segurança** - HTTPS, PKCE, etc em produção

---

**Divirta-se testando OAuth2! 🎉**
