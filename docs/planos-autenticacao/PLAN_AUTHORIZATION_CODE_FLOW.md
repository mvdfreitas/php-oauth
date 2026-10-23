# Plano: Documentação e Melhoria — Authorization Code Flow

**Stack:** `server/oauth2_complete.php` + `client/oauth2_client.php`
**Data:** 2026-10-09
**Status:** Implementado (2026-10-11)

---

## TL;DR

Documentar o Authorization Code Flow como está hoje na stack completa, mapear cada passo com parâmetros, e planejar 5 melhorias de segurança (PKCE, refresh token rotation, JWT assinado, state/nonce fix, consent form fix) para implementação futura.

---

## Parte 1 — Documentação do Fluxo Existente

### 1.1 Visão Geral

O Authorization Code Flow na stack completa segue a sequência:

```
┌──────────┐          ┌──────────────────┐          ┌──────────────────┐
│  Browser │          │  Cliente (8001)  │          │  Servidor (8000) │
│ (usuário)│          │ oauth2_client.php│          │oauth2_complete.php│
└────┬─────┘          └────────┬─────────┘          └────────┬─────────┘
     │                         │                            │
     │ 1. Clica "Login"        │                            │
     ├─────────────────────────>│                            │
     │                         │                            │
     │ 2. Redireciona para     │                            │
     │    authorize endpoint   │                            │
     │<────────────────────────┤                            │
     │                         │                            │
     │ 3. GET /authorize       │                            │
     │    (com state, nonce,   │                            │
     │     scope, redirect_uri)│                            │
     ├──────────────────────────────────────────────────────>│
     │                         │                            │
     │ 4. Servidor valida      │                            │
     │    parâmetros, mostra   │                            │
     │    formulário de login  │                            │
     │<──────────────────────────────────────────────────────┤
     │                         │                            │
     │ 5. Usuário digita       │                            │
     │    credenciais          │                            │
     ├──────────────────────────────────────────────────────>│
     │                         │                            │
     │ 6. Servidor autentica,  │                            │
     │    gera auth code,      │                            │
     │    redireciona com      │                            │
     │    code + state         │                            │
     │<──────────────────────────────────────────────────────┤
     │                         │                            │
     │ 7. Browser redireciona  │                            │
     │    para redirect_uri    │                            │
     │    com ?code=...&state= │                            │
     ├─────────────────────────>│                            │
     │                         │                            │
     │                         │ 8. Valida state            │
     │                         │    (BUG: mismatch não      │
     │                         │     interrompe)            │
     │                         │                            │
     │                         │ 9. POST /token             │
     │                         │    (code → access_token)   │
     │                         ├───────────────────────────>│
     │                         │                            │
     │                         │ 10. Servidor valida code,  │
     │                         │     gera tokens, retorna   │
     │                         │<───────────────────────────┤
     │                         │                            │
     │                         │ 11. GET /userinfo          │
     │                         │    (Bearer token)          │
     │                         ├───────────────────────────>│
     │                         │                            │
     │                         │ 12. Retorna dados do       │
     │                         │     usuário (filtrados     │
     │                         │     por scope)             │
     │                         │<───────────────────────────┤
     │                         │                            │
     │ 13. Mostra dados do     │                            │
     │     usuário + tokens    │                            │
     │<────────────────────────┤                            │
```

### 1.2 Parâmetros por Passo

**Passo 2-3: Autorização (GET)**

| Parâmetro | Valor | Obrigatório |
|-----------|-------|-------------|
| `action` | `authorize` | Sim |
| `client_id` | `cliente_teste` | Sim |
| `redirect_uri` | `http://localhost:8001/client/oauth2_client.php` | Sim |
| `response_type` | `code` | Sim |
| `scope` | `openid profile email` | Não (default: `openid profile`) |
| `state` | string aleatória (32 chars) | Recomendado |
| `nonce` | string aleatória (32 chars) | Para OIDC |
| `prompt` | `consent` | Opcional |

**Passo 5: Login (POST)**

| Campo | Tipo |
|-------|------|
| `username` | text |
| `password` | password |
| `consent` | hidden (valor `1`) |

**Passo 9: Token Exchange (POST)**

| Parâmetro | Valor |
|-----------|-------|
| `action` | `token` |
| `client_id` | `cliente_teste` |
| `client_secret` | `senha_secreta_123` |
| `grant_type` | `authorization_code` |
| `code` | auth code recebido |
| `redirect_uri` | mesmo do passo 2 |

**Resposta do Token:**

```json
{
  "access_token": "64 chars hex",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "64 chars hex",
  "scope": "openid profile email",
  "id_token": "base64 JSON (NÃO assinado)"
}
```

**Passo 11: UserInfo (GET)**
- Header: `Authorization: Bearer {access_token}`
- Resposta: JSON com claims filtrados por scope

### 1.3 Mapeamento de Funções (Stack Completa)

**Servidor (`server/oauth2_complete.php`):**

| Função | Linha | Papel no fluxo |
|--------|-------|----------------|
| `handleAuthorize()` | 218 | Valida parâmetros, mostra login/consent |
| `showLoginForm()` | 386 | Renderiza formulário de login |
| `showConsentForm()` | 633 | Renderiza formulário de consentimento |
| `handleToken()` | 795 | Troca code por tokens |
| `handleUserInfo()` | 950 | Retorna dados do usuário |
| `handleWellKnown()` | 997 | Discovery endpoint |
| `generateCode()` | 117 | Gera auth code (16 bytes hex) |
| `generateToken()` | 121 | Gera access/refresh token (32 bytes hex) |
| `generateIdToken()` | 125 | Gera id_token (base64 JSON) |
| `parseScopes()` | 159 | Parseia string de scopes |
| `validateScopes()` | 164 | Valida scopes contra allowed |
| `getClaimsForScopes()` | 173 | Mapeia scopes → claims |
| `filterUserDataByClaims()` | 186 | Filtra dados do usuário |
| `loadStorage()` / `saveStorage()` | 103/112 | Persistência em JSON |

**Cliente (`client/oauth2_client.php`):**

| Função | Linha | Papel no fluxo |
|--------|-------|----------------|
| Iniciação do flow | 855-885 | Gera state/nonce, redireciona |
| Callback (code) | 78-118 | Recebe code, valida state, troca por token |
| `makeRequest()` | 25 | Wrapper cURL |
| `generateRandomString()` | 21 | Gera strings aleatórias |

### 1.4 Storage

- **Local:** `sys_get_temp_dir().'/oauth2_storage_{USER}.json'`
- **Estrutura:** `{ auth_codes: [], access_tokens: [], refresh_tokens: [] }`
- **Code expiry:** 600s (hardcoded, `$config['code_expires']` definido mas não usado)
- **Access token expiry:** 3600s (hardcoded)
- **Refresh token:** sem expiração

### 1.5 Problemas Identificados no Fluxo Atual

| # | Problema | Severidade | Local |
|---|----------|-----------|-------|
| 1 | **State mismatch não interrompe** — erro é setado mas o fluxo continua | Alta | `oauth2_client.php` ~linha 85 |
| 2 | **Consent form quebrado** — campo `password` vazio → autenticação sempre falha; login form tem `consent=1` hidden que bypassa | Alta | `oauth2_complete.php` `showConsentForm()` |
| 3 | **Sem PKCE** — vulnerável a code interception | Alta | Ambos os lados |
| 4 | **Refresh token sem rotação/expiração/revogação** — tokens vivem para sempre | Média | `handleToken()` |
| 5 | **id_token não assinado** — base64 JSON, não é JWT real | Média | `generateIdToken()` |
| 6 | **Senhas em plaintext** no servidor completo | Média | `$users` array |
| 7 | **`$config` code_expires/refresh_token_expires definidos mas não usados** | Baixa | `handleAuthorize()`/`handleToken()` |
| 8 | **`handleWellKnown()` aponta para `/index.php`** (arquivo inexistente) | Baixa | `handleWellKnown()` |

---

## Parte 2 — Plano de Melhorias de Segurança (Para Implementação Futura)

### 2.1 PKCE (Proof Key for Code Exchange)

**O que é:** O cliente gera um `code_verifier` (string aleatória 43-128 chars) e envia o `code_challenge` (SHA-256 do verifier, base64url) no authorize. No token exchange, envia o `code_verifier` original. O servidor valida que `SHA-256(code_verifier) == code_challenge`.

**Mudanças no servidor (`oauth2_complete.php`):**
- `handleAuthorize()`: aceitar parâmetros `code_challenge` e `code_challenge_method` (S256)
- Armazenar `code_challenge` junto com o auth code no storage
- `handleToken()`: aceitar `code_verifier`, validar `hash('sha256', $code_verifier) == $stored_challenge`
- Rejeitar token se PKCE falhar

**Mudanças no cliente (`oauth2_client.php`):**
- Iniciação: gerar `code_verifier` (43+ chars aleatórios), calcular `code_challenge = base64url(sha256(code_verifier))`
- Enviar `code_challenge` + `code_challenge_method=S256` no authorize
- Armazenar `code_verifier` em `$_SESSION`
- Token exchange: enviar `code_verifier` no POST

**Especificação:** RFC 7636

### 2.2 Refresh Token Rotation + Revocation

**O que é:** A cada uso do refresh token, o servidor invalida o antigo e emite um novo. Adicionar endpoint `revoke` para revogar tokens.

**Mudanças no servidor:**
- `handleToken()` (grant_type=refresh_token): ao usar refresh token, remover o antigo do storage e gerar novo
- Novo handler `handleRevoke()`: endpoint `?action=revoke` que aceita `token` + `token_type_hint` (access_token/refresh_token) e remove do storage
- Adicionar `expires_at` aos refresh tokens (ex: 30 dias)
- Validar expiração do refresh token antes de usar
- Adicionar `revoke` ao router

**Mudanças no cliente:**
- Botão "Revogar Token" que chama o endpoint revoke
- Ao renovar, o novo refresh_token substitui o antigo automaticamente

**Especificação:** RFC 7009 (Token Revocation)

### 2.3 JWT Assinado (id_token)

**O que é:** O `id_token` deve ser um JWT real (header.payload.signature) assinado com HMAC-SHA256 (HS256) usando o `client_secret` como chave.

**Mudanças no servidor:**
- `generateIdToken()`: reescrever para gerar JWT real
  - Header: `{"alg":"HS256","typ":"JWT"}`
  - Payload: claims (iss, sub, aud, exp, iat, nonce, auth_time)
  - Signature: `hash_hmac('sha256', header.payload, client_secret)`
  - Formato: `base64url(header).base64url(payload).base64url(signature)`
- `handleUserInfo()`: opcionalmente validar JWT se enviado
- Adicionar `jwks_uri` ao well-known (ou `token_endpoint_auth_methods_supported`)

**Mudanças no cliente:**
- Decodificar e validar o JWT (verificar assinatura, exp, iss, aud)
- Exibir os claims do id_token separadamente

**Especificação:** OpenID Connect Core 1.0, RFC 7519 (JWT)

### 2.4 Corrigir State/Nonce Handling

**O que é:** Garantir que state mismatch interrompa o fluxo e nonce seja validado no id_token.

**Mudanças no cliente (`oauth2_client.php`):**
- Callback code flow (~linha 85): se `$_GET['state'] !== $_SESSION['oauth_state']`, **encerrar o fluxo** com erro (não continuar)
- Validar `nonce` no id_token: comparar com `$_SESSION['oauth_nonce']`
- Limpar `$_SESSION` após uso bem-sucedido

**Mudanças no servidor:**
- `handleAuthorize()`: garantir que `state` seja sempre redirecionado de volta (mesmo em erro)
- `handleToken()`: validar `nonce` se presente no id_token

### 2.5 Corrigir Consent Form

**O que é:** O consent form deve funcionar corretamente — separar autenticação (login) de autorização (consent).

**Mudanças no servidor (`oauth2_complete.php`):**
- `showConsentForm()`: remover campo `password` (não é necessário no consent — o usuário já está autenticado)
- `handleAuthorize()`: quando `prompt=consent` e usuário já autenticado (sessão), pular login e ir direto para consent
- `showLoginForm()`: remover o hidden `consent=1` que bypassa o consent
- Fluxo correto:
  1. Primeiro acesso → login form → autentica → mostra consent form
  2. Consent form → usuário aprova/recusa → gera code
  3. Acesso subsequente (já autenticado) → direto para consent (ou skip se `prompt=none`)

**Mudanças no cliente:**
- Manter `prompt=consent` quando checkbox "Pedir Consentimento" está ativo
- Adicionar `prompt=none` como opção (skip consent se já autorizado)

---

## Parte 3 — Ordem de Implementação Recomendada

| Ordem | Melhoria | Dependências | Complexidade |
|-------|----------|-------------|-------------|
| 1 | Corrigir state/nonce handling | Nenhuma | Baixa |
| 2 | Corrigir consent form | Nenhuma | Média |
| 3 | PKCE | Nenhuma | Média |
| 4 | Refresh token rotation + revocation | Nenhuma | Média |
| 5 | JWT assinado (id_token) | Nenhuma | Média |

> Itens 1-2 são correções de bugs. Itens 3-5 são novas funcionalidades. Todos são independentes entre si e podem ser implementados em qualquer ordem.

---

## Arquivos Alvo (Stack Completa)

- `c:\Projetos\estudos\php-oauth\server\oauth2_complete.php` — servidor (1358 linhas)
- `c:\Projetos\estudos\php-oauth\client\oauth2_client.php` — cliente (945 linhas)

## Fora de Escopo

- Stack simples (`oauth2_server.php` + `callback.php`)
- Outros grant types (implicit, password, client credentials)
- Mudanças de estrutura de pastas (já concluído)
- Banco de dados real (manter storage JSON)

---

## Parte 4 — Status de Implementação (2026-10-11)

| # | Melhoria | Status | Onde |
|---|----------|--------|------|
| 1 | State/nonce handling | ✅ | Erros do servidor devolvem `error`+`state` via redirect (`redirectWithError`); cliente já aborta em mismatch e valida `nonce` |
| 2 | Consent form | ✅ | `showLoginForm` sem `consent=1`; `showConsentForm` sem `password`; login (sessão) separado do consentimento; recusa via `consent=0`; memória de consentimento por client |
| 3 | PKCE (RFC 7636) | ✅ | Servidor aceita/valida `code_challenge` (S256/plain) e exige `code_verifier`; cliente gera verifier/challenge |
| 4 | Refresh rotation + revoke | ✅ | Rotação a cada uso, `expires_at` (30 dias), endpoint `?action=revoke` (RFC 7009) com cascata para access tokens |
| 5 | JWT assinado | ✅ | Já era HS256; adicionado claim `auth_time`; `well-known` anuncia `HS256` |

Pendências menores também resolvidas: `code_expires` agora vem do `$config`; `well-known` aponta para `oauth2_complete.php` e anuncia `revocation_endpoint` e `code_challenge_methods_supported`.

### Como testar manualmente

```bash
php -S localhost:8000 server/oauth2_complete.php
php -S localhost:8001 client/oauth2_client.php
# Acesse http://localhost:8001/oauth2_client.php → flow=code
# Após login: consentimento → código → token (com PKCE) → userinfo
# Botões "Renovar Token" (rotação) e "Revogar Token"
```

### Fora de escopo remanescente

- Senhas de `$users` ainda em plaintext (problema #6 da Parte 1) — só faz sentido junto com um armazenamento real/`password_hash`.
- Debug page mantém exemplos com `redirect_uri=.../index.php` (URI válida registrada).
