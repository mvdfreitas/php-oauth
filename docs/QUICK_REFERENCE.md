# 🎯 Quick Reference - OAuth2 Completo

## 📦 Arquivos Principais

### Para Começar AGORA:

```
server/oauth2_complete.php  ← Servidor OAuth2 (Porta 8000)
client/oauth2_client.php    ← Cliente Web (Porta 8001)
docs/GUIA_CLIENTE.md        ← Como usar o cliente
docs/PARAMETROS.md          ← Todos os parâmetros OAuth2
```

---

## ⚡ 3 Comandos para Começar

```bash
# Terminal 1
php -S localhost:8000 server/oauth2_complete.php

# Terminal 2
php -S localhost:8001 client/oauth2_client.php

# Browser
http://localhost:8001/client/oauth2_client.php
```

---

## 🔐 4 Flows OAuth2 - Resumo Rápido

### 1. Authorization Code (Seguro)
```
Uso: Web apps, apps com backend
URL: /authorize com response_type=code
Token: Via POST /token
Refresh: Sim
Segurança: ⭐⭐⭐⭐⭐
```

**Testar no cliente:** Clique em "📝 Code Flow" → "Login"

---

### 2. Implicit (Rápido)
```
Uso: SPAs, JavaScript no navegador
URL: /authorize com response_type=token
Token: Retorna na URL (#access_token=...)
Refresh: Não
Segurança: ⭐⭐⭐
```

**Testar no cliente:** Clique em "⚡ Implicit Flow" → "Login"

---

### 3. Password (Direto)
```
Uso: Apps desktop, mobile nativa
URL: POST /token com username + password
Token: Direto
Refresh: Sim
Segurança: ⭐⭐
```

**Testar no cliente:** Clique em "🔐 Password Flow" → Digite senha

---

### 4. Client Credentials (Backend)
```
Uso: Integração máquina-máquina
URL: POST /token com client_id + client_secret
Token: Direto (sem usuário)
Refresh: Não
Segurança: ⭐⭐⭐⭐
```

**Testar no cliente:** Clique em "🔧 Client Flow" → Autenticar

---

## 📊 Parâmetros Principais

### Authorize Endpoint
```
GET /oauth2_complete.php?action=authorize&
  client_id=cliente_teste &
  response_type=code &
  redirect_uri=http://localhost:8001/oauth2_client.php &
  scope=openid profile email &
  state=random_state &
  nonce=random_nonce &
  prompt=consent
```

| Param | Obrigatório | Valores |
|-------|---------|---------|
| `client_id` | ✅ | `cliente_teste` |
| `response_type` | ✅ | `code`, `token`, `id_token` |
| `redirect_uri` | ✅ | URL de callback |
| `scope` | ❌ | `openid profile email address phone` |
| `state` | ✅ | Valor aleatório |
| `nonce` | ❌ | Valor aleatório |
| `prompt` | ❌ | `login`, `consent`, `none` |

### Token Endpoint
```
POST /oauth2_complete.php?action=token

grant_type=authorization_code  (ou refresh_token, password, client_credentials)
code=CODE
client_id=cliente_teste
client_secret=senha_secreta_123
redirect_uri=http://localhost:8001/oauth2_client.php
```

---

## 🔑 Credenciais de Teste

```
Usuário:        usuario1
Senha:          senha123

ou

Usuário:        usuario2  
Senha:          senha456

Client ID:      cliente_teste
Client Secret:  senha_secreta_123
```

---

## 🎮 Como Usar o Cliente

### Passo 1: Escolher Flow
Clique em um dos 4 botões:
- 📝 Code Flow (recomendado)
- ⚡ Implicit Flow
- 🔐 Password Flow
- 🔧 Client Flow

### Passo 2: Fazer Login
- **Code Flow:** Clique "Login" → Redireciona → Faz login
- **Implicit:** Clique "Login" → Redireciona → Faz login
- **Password:** Digite usuário/senha → Clique "Login"
- **Client:** Clique "Autenticar"

### Passo 3: Ver Dados
Após autenticar:
- Vê seu nome, email, telefone, etc
- Token aparece na tela
- Pode renovar com "🔄 Renovar Token"
- Pode fazer logout com "🚪 Logout"

---

## 📝 Exemplos de Uso

### JavaScript - Get Token (Authorization Code)
```javascript
// 1. Redirecionar
window.location = 'http://localhost:8000/oauth2_complete.php?action=authorize&' +
  'client_id=cliente_teste&' +
  'response_type=code&' +
  'redirect_uri=' + encodeURIComponent(window.location) +
  '&scope=openid profile email&' +
  'state=' + Math.random();

// 2. No callback, trocar code por token (no backend)
const token_response = await fetch('http://localhost:8000/oauth2_complete.php?action=token', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: 'grant_type=authorization_code&code=' + code + '&...'
});

// 3. Usar token
const userinfo = await fetch('http://localhost:8000/oauth2_complete.php?action=userinfo', {
  headers: { 'Authorization': 'Bearer ' + access_token }
});
```

### cURL - Get Token (Password Flow)
```bash
curl -X POST http://localhost:8000/oauth2_complete.php?action=token \
  -d "grant_type=password" \
  -d "username=usuario1" \
  -d "password=senha123" \
  -d "client_id=cliente_teste" \
  -d "client_secret=senha_secreta_123"

# Resultado:
# {
#   "access_token": "...",
#   "refresh_token": "...",
#   "expires_in": 3600
# }
```

### cURL - Usar Token
```bash
curl http://localhost:8000/oauth2_complete.php?action=userinfo \
  -H "Authorization: Bearer SEU_TOKEN_AQUI"

# Resultado:
# {
#   "sub": "usuario1",
#   "name": "Usuário Um",
#   "email": "usuario1@example.com"
# }
```

---

## 🔄 Renovar Token

Quando seu token está prestes a expirar:

```bash
curl -X POST http://localhost:8000/oauth2_complete.php?action=token \
  -d "grant_type=refresh_token" \
  -d "refresh_token=SEU_REFRESH_TOKEN" \
  -d "client_id=cliente_teste" \
  -d "client_secret=senha_secreta_123"

# Novo token retorna
```

No cliente: Clique em "🔄 Renovar Token"

---

## 🔍 Debugar

### Ver Storage do Servidor
```
http://localhost:8000/oauth2_complete.php?action=debug
```

Mostra:
- Clientes registrados
- Usuários cadastrados
- Tokens gerados
- Auth codes pendentes

### Ver Endpoints
```
http://localhost:8000/oauth2_complete.php?action=well-known
```

Metadados do servidor OAuth2

### No Browser DevTools
```
F12 → Network → Veja cada requisição
Veja headers, query params, POST body, responses
```

---

## 📚 Documentação Completa

- **GUIA_CLIENTE.md** - Como usar o cliente (este arquivo base)
- **PARAMETROS.md** - Todos os parâmetros OAuth2
- **QUICKSTART.md** - Começar rápido
- **README.md** - Overview geral

---

## ⚙️ Customizar

### Adicionar Novo Usuário

Edite `server/oauth2_complete.php`, procure por `$users`:

```php
$users = [
    'seu_usuario' => [
        'password' => 'sua_senha',
        'email' => 'seu@email.com',
        'name' => 'Seu Nome',
        'phone_number' => '+55 11 99999-9999',
        'address' => 'Rua X, 123',
        'city' => 'São Paulo',
        'country' => 'Brasil',
        'picture' => 'https://...',
        'locale' => 'pt_BR',
        'email_verified' => true,
        'phone_number_verified' => false,
    ],
];
```

### Adicionar Novo Cliente OAuth2

Edite `server/oauth2_complete.php`, procure por `$clients`:

```php
$clients = [
    'meu_app' => [
        'client_id' => 'meu_app',
        'client_secret' => 'super_secreta',
        'client_name' => 'Minha App',
        'redirect_uris' => [
            'http://localhost:3000/callback',
            'http://meu-app.com/callback'
        ],
        'response_types' => ['code', 'token', 'id_token'],
        'grant_types' => ['authorization_code', 'refresh_token', 'implicit'],
    ],
];
```

---

## 🎯 Checklist - O Que Testar

- [ ] Authorization Code Flow
- [ ] Implicit Flow
- [ ] Password Flow
- [ ] Client Credentials Flow
- [ ] Refresh Token
- [ ] Scopes (profile, email, phone, address)
- [ ] State Parameter (CSRF protection)
- [ ] Nonce Parameter (OpenID)
- [ ] Prompt Parameter (login, consent, none)
- [ ] Logout
- [ ] Ver dados do usuário
- [ ] Debug Page
- [ ] Well-Known Endpoint

---

## 🚨 Erros e Soluções

| Erro | Causa | Solução |
|------|-------|---------|
| Página em branco | Servidor não está rodando | `php -S localhost:8000` |
| `invalid_client` | Client ID errado | Use `cliente_teste` |
| `invalid_redirect_uri` | URI não autorizada | Use `http://localhost:8001/...` |
| `State mismatch` | Session perdida | Cookies bloqueados? |
| `Code expired` | Esperou muito | Code expira em 10min |
| Sem refresh_token | Flow não suporta | Implicit não tem refresh |

---

## 📞 Links Úteis

- [RFC 6749 - OAuth 2.0](https://tools.ietf.org/html/rfc6749)
- [OpenID Connect](https://openid.net/connect/)
- [IANA OAuth Parameters](https://www.iana.org/assignments/oauth-parameters/)

---

## 🎓 Ordem de Aprendizado Recomendada

1. **Comece com Authorization Code** (mais seguro, padrão)
2. **Teste Implicit Flow** (diferente mas simples)
3. **Tente Password Flow** (menos seguro, mas direto)
4. **Experimente Client Credentials** (backend-to-backend)
5. **Customize parâmetros** (state, nonce, scope, prompt)
6. **Leia PARAMETROS.md** (entenda cada um em detalhe)
7. **Implemente em suas apps** (use o conhecimento)

---

## ✅ Você Está Pronto Para:

✅ Entender os 4 flows OAuth2  
✅ Implementar login seguro  
✅ Integrar OAuth2 em suas apps  
✅ Debugar problemas  
✅ Customizar para seu caso de uso  
✅ Preparar para produção (com HTTPS)  

---

**Divirta-se aprendendo OAuth2! 🚀**
