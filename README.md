# Servidor OAuth2 Simples em PHP Puro

Um servidor OAuth2 minimalista em PHP 7.3+ sem frameworks. Perfeito para testes e aprendizado.

## 🚀 Como Usar

### 1. Iniciar o Servidor OAuth2 (Porta 8000)

```bash
# Servidor completo (4 grant types + OpenID Connect)
php -S localhost:8000 server/oauth2_complete.php

# Ou servidor simples (apenas Authorization Code)
php -S localhost:8000 server/oauth2_server.php
```

### 2. Iniciar o Cliente de Teste (Porta 8001)

```bash
# Cliente completo (4 flows)
php -S localhost:8001 client/oauth2_client.php

# Ou cliente simples
php -S localhost:8001 client/callback.php
```

### 3. Testar o Fluxo

#### Via Browser:
- Acesse: http://localhost:8001/client/oauth2_client.php (cliente completo)
- Ou: http://localhost:8001/client/callback.php (cliente simples)
- Clique em "Login com OAuth2"
- Use as credenciais:
  - **Usuário:** usuario1
  - **Senha:** senha123
  
  ou
  
  - **Usuário:** usuario2
  - **Senha:** senha456

#### Via cURL (Teste Manual):

**1. Obter Authorization Code:**
```bash
curl -X GET "http://localhost:8000/oauth2_server.php?action=authorize&client_id=cliente_teste&redirect_uri=http://localhost:8001/callback.php&response_type=code&scope=openid%20profile&state=xyz123"
```

**2. Trocar Code por Access Token:**
```bash
curl -X POST "http://localhost:8000/oauth2_server.php?action=token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=cliente_teste&client_secret=senha_secreta_123&grant_type=authorization_code&code=SEU_CODE_AQUI&redirect_uri=http://localhost:8001/callback.php"
```

**3. Usar Access Token para Obter Dados:**
```bash
curl -X GET "http://localhost:8000/oauth2_server.php?action=userinfo" \
  -H "Authorization: Bearer SEU_TOKEN_AQUI"
```

## 📋 Endpoints Disponíveis

### Authorization Endpoint
```
GET /oauth2_server.php?action=authorize
```
**Parâmetros:**
- `client_id` (obrigatório): ID do cliente
- `redirect_uri` (obrigatório): URI de callback
- `response_type` (obrigatório): deve ser "code"
- `scope` (opcional): escopos solicitados
- `state` (recomendado): valor aleatório para CSRF protection

### Token Endpoint
```
POST /oauth2_server.php?action=token
```
**Parâmetros (form-data):**
- `client_id` (obrigatório): ID do cliente
- `client_secret` (obrigatório): Senha do cliente
- `grant_type` (obrigatório): "authorization_code"
- `code` (obrigatório): Authorization code recebido
- `redirect_uri` (obrigatório): Mesmo redirect_uri usado antes

**Resposta:**
```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "...",
  "scope": "openid profile"
}
```

### UserInfo Endpoint
```
GET /oauth2_server.php?action=userinfo
Header: Authorization: Bearer ACCESS_TOKEN
```

**Resposta:**
```json
{
  "sub": "usuario1",
  "name": "Usuário Um",
  "email": "usuario1@example.com",
  "picture": "https://via.placeholder.com/150",
  "scope": "openid profile"
}
```

### Well-Known Endpoint (OpenID Connect Discovery)
```
GET /oauth2_server.php?action=well-known
```

### Debug Page
```
GET /oauth2_server.php?action=debug
```
Interface visual para ver:
- Clientes registrados
- Usuários cadastrados
- Storage atual (tokens e codes)
- Exemplos de endpoints

## 🔐 Credenciais de Teste

### Usuários:
```
usuario1 / senha123
usuario2 / senha456
```

### Cliente OAuth2:
```
Client ID: cliente_teste
Client Secret: senha_secreta_123
Redirect URIs:
  - http://localhost:8001/callback
  - http://localhost:3000/callback
```

## 📦 Configurações

### Arquivo: oauth2_server.php

Para adicionar novos usuários, edite a array `$users`:
```php
$users = [
    'seu_usuario' => [
        'password' => password_hash('sua_senha', PASSWORD_BCRYPT),
        'email' => 'seu_email@example.com',
        'name' => 'Seu Nome',
        'picture' => 'https://exemplo.com/foto.jpg',
    ],
];
```

Para adicionar novos clientes OAuth2, edite a array `$clients`:
```php
$clients = [
    'seu_client_id' => [
        'client_id' => 'seu_client_id',
        'client_secret' => 'sua_senha_secreta',
        'redirect_uris' => [
            'http://seu-app.com/callback',
        ],
        'allowed_scopes' => ['openid', 'profile', 'email'],
    ],
];
```

## 🔄 Fluxo OAuth2 Authorization Code

```
┌─────────┐                                    ┌──────────────┐
│ Cliente │                                    │ OAuth2       │
│         │                                    │ Server       │
└────┬────┘                                    └──────┬───────┘
     │                                                │
     │  1. Redireciona para authorize                │
     ├───────────────────────────────────────────────>
     │                                                │
     │  2. Usuário faz login e autoriza              │
     │     (form POST)                               │
     │<───────────────────────────────────────────────┤
     │                                                │
     │  3. Redireciona com authorization code        │
     │<───────────────────────────────────────────────┤
     │                                                │
     │  4. Troca code por token (POST /token)        │
     ├───────────────────────────────────────────────>
     │                                                │
     │  5. Retorna access_token                      │
     │<───────────────────────────────────────────────┤
     │                                                │
     │  6. Usa token para acessar userinfo           │
     ├───────────────────────────────────────────────>
     │                                                │
     │  7. Retorna dados do usuário                  │
     │<───────────────────────────────────────────────┤
```

## 💾 Armazenamento

Os tokens são armazenados em `/tmp/oauth2_storage.json`:
- Authorization codes (expiram em 10 minutos)
- Access tokens (expiram em 1 hora)
- Refresh tokens (sem expiração definida)

**Nota:** Em produção, use um banco de dados real!

## ⚙️ Tecnologias

- PHP 7.3+
- Sem frameworks
- Sem dependências externas
- Segurança básica:
  - Senha com bcrypt
  - Authorization codes com expiração
  - Access tokens com expiração
  - State parameter para CSRF protection

## 🐛 Debug

Acesse http://localhost:8000/oauth2_server.php?action=debug para ver:
- Todos os clientes registrados
- Todos os usuários cadastrados
- Storage atual (tokens e codes gerados)
- Exemplos de uso dos endpoints

## ⚠️ Notas Importantes

1. **NÃO USE EM PRODUÇÃO** - Este código é apenas para aprendizado e testes
2. **Storage em Memória** - Os tokens são armazenados em arquivo JSON, não em banco de dados
3. **HTTP** - Este servidor usa HTTP, nunca HTTPS. Em produção, use HTTPS obrigatoriamente
4. **CORS** - Sem suporte a CORS implementado
5. **Rate Limiting** - Sem proteção contra brute force

## 📚 Estrutura do Projeto

```
php-oauth/
├── server/
│   ├── oauth2_server.php        ← Servidor simples (Authorization Code)
│   └── oauth2_complete.php      ← Servidor completo (4 grant types + OIDC)
├── client/
│   ├── callback.php             ← Cliente simples
│   └── oauth2_client.php        ← Cliente completo (4 flows)
├── session/
│   └── oauth2_storage.json      ← Storage de tokens/codes
├── docs/
│   ├── GUIA_CLIENTE.md
│   ├── INDEX.md
│   ├── LEIA_PRIMEIRO.txt
│   └── QUICK_REFERENCE.md
└── README.md
```

### Estrutura interna de cada servidor

```
oauth2_server.php / oauth2_complete.php
├── Configurações
├── Banco de dados simulado
│   ├── Clientes
│   ├── Usuários
│   └── Storage (tokens e codes)
├── Funções utilitárias
├── Handlers de ações
│   ├── handleAuthorize() - Passo 1
│   ├── showLoginForm() - Formulário de login
│   ├── handleToken() - Passo 2
│   ├── handleUserInfo() - Obter dados do usuário
│   ├── handleWellKnown() - OpenID Discovery
│   └── handleDebug() - Interface de debug
└── Roteador principal
```

## 🧪 Exemplos de Integração

### JavaScript/Fetch:
```javascript
// 1. Redirecionar para authorize
const authUrl = new URL('http://localhost:8000/oauth2_server.php');
authUrl.searchParams.set('action', 'authorize');
authUrl.searchParams.set('client_id', 'cliente_teste');
authUrl.searchParams.set('redirect_uri', 'http://localhost:8001/callback');
authUrl.searchParams.set('response_type', 'code');
authUrl.searchParams.set('scope', 'openid profile');
authUrl.searchParams.set('state', generateRandomState());
window.location.href = authUrl.toString();

// 2. No callback, trocar code por token
const code = new URLSearchParams(window.location.search).get('code');
const response = await fetch('http://localhost:8000/oauth2_server.php?action=token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
        client_id: 'cliente_teste',
        client_secret: 'senha_secreta_123',
        grant_type: 'authorization_code',
        code: code,
        redirect_uri: 'http://localhost:8001/callback'
    })
});
const token = await response.json();

// 3. Usar token para obter dados
const userinfo = await fetch('http://localhost:8000/oauth2_server.php?action=userinfo', {
    headers: { 'Authorization': `Bearer ${token.access_token}` }
});
const user = await userinfo.json();
```

### Python:
```python
import requests
from urllib.parse import urlencode

# 1. Redirecionar para authorize
client_id = 'cliente_teste'
redirect_uri = 'http://localhost:8001/callback'
auth_url = f"http://localhost:8000/oauth2_server.php?action=authorize&client_id={client_id}&redirect_uri={redirect_uri}&response_type=code&scope=openid profile"

# 2. Trocar code por token
code = "seu_code_aqui"
token_response = requests.post(
    'http://localhost:8000/oauth2_server.php?action=token',
    data={
        'client_id': 'cliente_teste',
        'client_secret': 'senha_secreta_123',
        'grant_type': 'authorization_code',
        'code': code,
        'redirect_uri': 'http://localhost:8001/callback'
    }
)
token = token_response.json()

# 3. Usar token para obter dados
userinfo_response = requests.get(
    'http://localhost:8000/oauth2_server.php?action=userinfo',
    headers={'Authorization': f"Bearer {token['access_token']}"}
)
user = userinfo_response.json()
print(user)
```

## 📞 Suporte

Para dúvidas sobre OAuth2:
- [RFC 6749 - OAuth 2.0 Authorization Framework](https://tools.ietf.org/html/rfc6749)
- [OpenID Connect Core](https://openid.net/specs/openid-connect-core-1_0.html)

---

**Criado para aprendizado e testes. Não use em produção!** 🚀
