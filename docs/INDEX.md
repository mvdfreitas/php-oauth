# 📚 Índice Completo - Servidor OAuth2 em PHP

## 🚀 Começar Agora (3 linhas de comando)

```bash
# Terminal 1
php -S localhost:8000 server/oauth2_complete.php

# Terminal 2  
php -S localhost:8001 client/oauth2_client.php

# Browser
http://localhost:8001/client/oauth2_client.php
```

---

## 📂 Estrutura de Arquivos

```
php-oauth/
├── server/
│   ├── oauth2_server.php        ← Servidor simples
│   └── oauth2_complete.php      ← Servidor completo
├── client/
│   ├── callback.php             ← Cliente simples
│   └── oauth2_client.php        ← Cliente completo
├── session/
│   └── oauth2_storage.json
├── docs/
│   ├── GUIA_CLIENTE.md
│   ├── INDEX.md
│   ├── LEIA_PRIMEIRO.txt
│   ├── QUICK_REFERENCE.md
│   ├── PLAN_AUTHORIZATION_CODE_FLOW.md
│   └── PLAN_IMPLICIT_FLOW.md
└── README.md
```

### ⭐ Essencial (Use Esses)

| Arquivo | Porta | O que faz |
|---------|-------|----------|
| `server/oauth2_complete.php` | 8000 | Servidor OAuth2 com TODOS os flows |
| `client/oauth2_client.php` | 8001 | Cliente web para testar |

### 📖 Documentação (Leia Esses)

| Arquivo | Descrição |
|---------|-----------|
| `docs/LEIA_PRIMEIRO.txt` | ⭐ Comece aqui |
| `docs/QUICK_REFERENCE.md` | Resumo dos 4 flows |
| `docs/GUIA_CLIENTE.md` | Como usar o cliente |
| `docs/PARAMETROS.md` | Referência de parâmetros |
| `docs/QUICKSTART.md` | Começar muito rápido |
| `docs/PLAN_AUTHORIZATION_CODE_FLOW.md` | 📋 Plano: Authorization Code Flow + melhorias de segurança |
| `docs/PLAN_IMPLICIT_FLOW.md` | 📋 Plano: Implicit Flow + melhorias de segurança |
| `docs/PLAN_DEVICE_AUTHORIZATION_GRANT.md` | 📋 Plano: Device Authorization Grant (RFC 8628 - Device Flow) |
| `docs/ANALISE_METODOS_AUTENTICACAO.md` | 📊 Análise: Métodos de Autenticação OAuth 2.0/2.1 e Mercado |
| `README.md` | Overview geral |
| `docs/INDEX.md` | Este arquivo |

### 🗂️ Arquivos Simples (Versão 1)

- `server/oauth2_server.php` - Versão 1 (sem parâmetros avançados)
- `client/callback.php` - Versão 1 (simples demais)

---

## 🎯 Roadmap de Aprendizado

### 1️⃣ Passo 1: Entender (5 minutos)
- Leia: `LEIA_PRIMEIRO.txt`
- Leia: `QUICK_REFERENCE.md` (seção "4 Flows OAuth2")

### 2️⃣ Passo 2: Testar (10 minutos)
- Inicie servidor e cliente
- Teste os 4 flows no cliente
- Veja os dados retornados

### 3️⃣ Passo 3: Aprofundar (30 minutos)
- Leia: `GUIA_CLIENTE.md` (completo)
- Entenda cada flow em detalhe

### 4️⃣ Passo 4: Referenciar (conforme necessário)
- Consulte: `PARAMETROS.md` (todos os parâmetros)
- Use DevTools para debugar

### 5️⃣ Passo 5: Integrar
- Use o conhecimento em suas apps
- Veja exemplos em JavaScript/PHP/cURL

---

## 🔐 4 Grant Types - Resumido

```
1. AUTHORIZATION CODE (Seguro)
   ✓ Web apps com backend
   ✓ Melhor segurança
   ✓ Com refresh token
   Fluxo: /authorize → login → /token
   
2. IMPLICIT (Rápido)
   ✓ SPAs, JavaScript
   ✓ Token direto na URL
   ✗ Sem refresh token
   Fluxo: /authorize → login → token na URL
   
3. PASSWORD (Direto)
   ✓ Apps desktop/mobile
   ✓ Usuário digita senha
   ✓ Com refresh token
   Fluxo: /token com username+password
   
4. CLIENT CREDENTIALS (Backend)
   ✓ Integração máquina-máquina
   ✓ Sem usuário final
   ✗ Sem refresh token
   Fluxo: /token com client_id+secret
```

---

## ✅ Funcionalidades Incluídas

- ✅ Todos os 4 grant types
- ✅ Access tokens + Refresh tokens
- ✅ ID tokens (OpenID Connect)
- ✅ STATE parameter (CSRF protection)
- ✅ NONCE parameter (Replay attack protection)
- ✅ PROMPT parameter (login, consent, none)
- ✅ Múltiplos scopes (openid, profile, email, phone, address)
- ✅ Tela de consentimento
- ✅ UserInfo endpoint
- ✅ Well-Known discovery endpoint
- ✅ Debug page
- ✅ Múltiplos usuários
- ✅ Múltiplos clientes OAuth2

---

## 🔑 Credenciais Padrão

```
Usuários:
  usuario1 / senha123
  usuario2 / senha456

Cliente OAuth2:
  ID: cliente_teste
  Secret: senha_secreta_123
  URIs: http://localhost:8001/oauth2_client.php (+ outras)
```

---

## 📱 Como Usar o Cliente Web

### Fluxo 1: Authorization Code (Recomendado)
1. Clique em "📝 Code Flow"
2. Clique em "🔐 Fazer Login"
3. Você será redirecionado
4. Faça login com usuario1/senha123
5. Autorize as permissões
6. Voltará autenticado com tokens

### Fluxo 2: Implicit
1. Clique em "⚡ Implicit Flow"
2. Clique em "⚡ Fazer Login"
3. Faça login como antes
4. Token retorna na URL (#access_token=...)

### Fluxo 3: Password
1. Clique em "🔐 Password Flow"
2. Digite usuario1 e senha123
3. Clique "✓ Login"
4. Pronto, autenticado!

### Fluxo 4: Client Credentials
1. Clique em "🔧 Client Flow"
2. Clique "🔧 Autenticar"
3. Sem usuário, apenas credenciais da app

---

## 🧪 Exemplos de Código

### JavaScript - Authorization Code
```javascript
const params = new URLSearchParams({
    client_id: 'cliente_teste',
    redirect_uri: window.location.href,
    response_type: 'code',
    scope: 'openid profile email',
    state: Math.random().toString(36)
});

window.location = 'http://localhost:8000/oauth2_complete.php?action=authorize&' + params;
```

### PHP - Password Flow
```php
$ch = curl_init('http://localhost:8000/oauth2_complete.php?action=token');
curl_setopt_array($ch, [
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'password',
        'username' => 'usuario1',
        'password' => 'senha123',
        'client_id' => 'cliente_teste',
        'client_secret' => 'senha_secreta_123'
    ]),
    CURLOPT_RETURNTRANSFER => 1
]);
$response = json_decode(curl_exec($ch), true);
$token = $response['access_token'];
```

### cURL - Client Credentials
```bash
curl -X POST http://localhost:8000/oauth2_complete.php?action=token \
  -d "grant_type=client_credentials" \
  -d "client_id=cliente_teste" \
  -d "client_secret=senha_secreta_123"
```

---

## 🔍 Debugar

### Debug Page do Servidor
```
http://localhost:8000/oauth2_complete.php?action=debug
```
Mostra: clientes, usuários, tokens gerados, auth codes

### Well-Known Endpoint
```
http://localhost:8000/oauth2_complete.php?action=well-known
```
Metadados OpenID Connect

### DevTools do Browser
- Abra: F12 → Network
- Veja cada requisição
- Analise headers e responses

---

## ⚙️ Customizar

### Adicionar Usuário

Edite `server/oauth2_complete.php`, array `$users`:

```php
$users = [
    'novo_user' => [
        'password' => 'senha',
        'email' => 'email@example.com',
        'name' => 'Seu Nome',
        'phone_number' => '+55 11 99999-9999',
        // ... outros campos
    ]
];
```

### Adicionar Cliente OAuth2

Edite `server/oauth2_complete.php`, array `$clients`:

```php
$clients = [
    'novo_client' => [
        'client_id' => 'novo_client',
        'client_secret' => 'super_secreta',
        'client_name' => 'Minha App',
        'redirect_uris' => ['http://meu-app.com/callback'],
        'response_types' => ['code', 'token'],
        'grant_types' => ['authorization_code', 'refresh_token']
    ]
];
```

---

## 📚 Qual Arquivo Ler?

| Seu caso | Leia |
|----------|------|
| Quero começar AGORA | `LEIA_PRIMEIRO.txt` |
| Quero resumo rápido | `QUICK_REFERENCE.md` |
| Quero entender o cliente | `GUIA_CLIENTE.md` |
| Quero ref. de parâmetros | `PARAMETROS.md` |
| Quero overview geral | `README.md` |
| Quero começar muito rápido | `QUICKSTART.md` |

---

## 🚀 Próximos Passos

1. **Teste cada flow** - Entenda as diferenças
2. **Customize parâmetros** - Nonce, state, scope, prompt
3. **Debugue com DevTools** - Veja requisições reais
4. **Implemente em suas apps** - Use o código de exemplo
5. **Prepare para produção** - HTTPS, PKCE, etc

---

## 📞 Erros Comuns

| Erro | Solução |
|------|---------|
| Conexão recusada | Inicie servidor: `php -S localhost:8000` |
| `invalid_client` | Use `cliente_teste` |
| `invalid_redirect_uri` | Use `http://localhost:8001/oauth2_client.php` |
| `State mismatch` | Sessão perdida, tente limpar cookies |
| `Code expired` | Esperou >10min, code expira rápido |

---

## 📖 Documentação Externa

- [RFC 6749 - OAuth 2.0](https://tools.ietf.org/html/rfc6749)
- [OpenID Connect 1.0](https://openid.net/connect/)
- [IANA OAuth Parameters](https://www.iana.org/assignments/oauth-parameters/)

---

## ✨ Funciona Com

- PHP 7.3+
- Sem frameworks
- Sem dependências externas
- Apenas PHP + cURL (built-in)

---

## ⚠️ Importante

**ISSO É PARA DESENVOLVIMENTO/TESTES, NÃO USE EM PRODUÇÃO SEM:**

- HTTPS obrigatório
- Senhas hasheadas (não em texto plano)
- PKCE para apps públicas
- Rate limiting
- Banco de dados real
- Logs e monitoramento
- Certificados SSL

---

## 🎓 Checklist de Aprendizado

- [ ] Entendi os 4 grant types
- [ ] Testei Authorization Code Flow
- [ ] Testei Implicit Flow
- [ ] Testei Password Flow
- [ ] Testei Client Credentials Flow
- [ ] Entendi STATE parameter
- [ ] Entendi NONCE parameter
- [ ] Testei renovar token
- [ ] Entendi os scopes
- [ ] Conseguo debugar requisições
- [ ] Conseguo customizar usuários/clientes
- [ ] Estou pronto para implementar

---

## 💬 Resumo

Você tem um **servidor OAuth2 profissional** com:
- ✅ Todos os 4 flows
- ✅ Segurança básica (state, nonce)
- ✅ Múltiplos scopes
- ✅ Cliente web para testar
- ✅ Documentação completa
- ✅ Exemplos de código

**Use para aprender, testar e desenvolver!**

---

**Versão 2.0 - Completa com Parâmetros Avançados** 🚀
