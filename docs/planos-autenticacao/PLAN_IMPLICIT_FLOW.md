# Plano: Documentação e Melhoria — Implicit Flow

**Stack:** `server/oauth2_complete.php` + `client/oauth2_client.php`
**Data:** 2026-10-09
**Status:** Planejamento (sem codificação)

---

## TL;DR

Documentar o Implicit Flow como está hoje na stack completa, mapear cada passo com parâmetros, e planejar melhorias de segurança para implementação futura. **Bug crítico identificado:** o servidor envia o token no fragment (`#`), mas o callback do cliente lê `$_GET['access_token']` — o browser nunca envia o fragment ao servidor, então o token nunca é capturado.

---

## Parte 1 — Documentação do Fluxo Existente

### 1.1 Visão Geral

O Implicit Flow retorna o token **diretamente na URL (fragment `#`)** após o login, sem etapa de token exchange no backend.

```
┌──────────┐          ┌──────────────────┐          ┌──────────────────┐
│  Browser │          │  Cliente (8001)  │          │  Servidor (8000) │
│ (usuário)│          │ oauth2_client.php│          │oauth2_complete.php│
└────┬─────┘          └────────┬─────────┘          └────────┬─────────┘
     │                         │                            │
     │ 1. Clica "Implicit"     │                            │
     ├─────────────────────────>│                            │
     │                         │                            │
     │ 2. Gera state,          │                            │
     │    redireciona para     │                            │
     │    /authorize           │                            │
     │    (response_type=token)│                            │
     │<────────────────────────┤                            │
     │                         │                            │
     │ 3. GET /authorize       │                            │
     │    (response_type=token,│                            │
     │     state, scope)       │                            │
     ├──────────────────────────────────────────────────────>│
     │                         │                            │
     │ 4. Servidor valida,     │                            │
     │    mostra login         │                            │
     │<──────────────────────────────────────────────────────┤
     │                         │                            │
     │ 5. Usuário digita       │                            │
     │    credenciais          │                            │
     ├──────────────────────────────────────────────────────>│
     │                         │                            │
     │ 6. Servidor autentica,  │                            │
     │    gera access_token,   │                            │
     │    redireciona com      │                            │
     │    #access_token=...    │                            │
     │    (FRAGMENT)           │                            │
     │<──────────────────────────────────────────────────────┤
     │                         │                            │
     │ 7. Browser volta para   │                            │
     │    redirect_uri com     │                            │
     │    #access_token=...    │                            │
     │    (fragment NÃO vai    │                            │
     │     ao servidor!)       │                            │
     ├─────────────────────────>│  ⚠️ BUG: $_GET não vê     │
     │                         │     o fragment             │
     │                         │                            │
     │ 8. (deveria) JS lê o    │                            │
     │    fragment, guarda     │                            │
     │    token, limpa URL     │                            │
     │                         │                            │
     │ 9. GET /userinfo        │                            │
     │    (Bearer token)       │                            │
     │                         ├───────────────────────────>│
     │                         │                            │
     │ 10. Mostra dados +      │                            │
     │     token               │                            │
     │<────────────────────────┤                            │
```

### 1.2 Parâmetros por Passo

**Passo 2-3: Autorização (GET)**

| Parâmetro | Valor | Obrigatório |
|-----------|-------|-------------|
| `action` | `authorize` | Sim |
| `client_id` | `cliente_teste` | Sim |
| `redirect_uri` | `http://localhost:8001/client/oauth2_client.php` | Sim |
| `response_type` | `token` (ou `id_token`, `id_token token`) | Sim |
| `scope` | `openid profile email` | Não (default: `openid profile`) |
| `state` | string aleatória (32 chars) | Recomendado |

**Passo 5: Login (POST)** — idêntico ao code flow (username, password, consent)

**Passo 6-7: Resposta (fragment `#`)**

```
http://localhost:8001/client/oauth2_client.php#access_token=...&token_type=Bearer&expires_in=3600&scope=openid+profile+email&state=...
```

| Parâmetro | Descrição |
|-----------|-----------|
| `access_token` | 64 chars hex |
| `token_type` | `Bearer` |
| `expires_in` | 3600 |
| `scope` | scopes concedidos |
| `state` | devolvido do passo 2 |
| `id_token` | (se `id_token`/`id_token token`) base64 JSON, não assinado |

**Passo 9: UserInfo (GET)** — `Authorization: Bearer {access_token}`

### 1.3 Mapeamento de Funções

**Servidor (`server/oauth2_complete.php`):**

| Função | Linha | Papel no fluxo |
|--------|-------|----------------|
| `handleAuthorize()` | 218 | Valida, autentica, gera token, redireciona |
| Bloco implicit | 329-372 | Gera access_token, monta response_params |
| Escolha de separador | 374 | `#` para token/id_token, `?` para code |
| `generateToken()` | 121 | Gera access_token (32 bytes hex) |
| `generateIdToken()` | 125 | Gera id_token (base64 JSON) |
| `showLoginForm()` | 386 | Formulário de login |
| `showConsentForm()` | 633 | Formulário de consentimento |
| `handleUserInfo()` | 950 | Retorna dados do usuário |

**Cliente (`client/oauth2_client.php`):**

| Função | Linha | Papel no fluxo |
|--------|-------|----------------|
| Botão "Implicit" | 807-835 | Form GET, dispara `implicit_auth` |
| Iniciação | 825-834 | Gera state, monta auth_url, redireciona via JS |
| Callback implicit | 109-145 | `if (isset($_GET['access_token']))` — ⚠️ **CÓDIGO MORTO** |
| `makeRequest()` | 25 | Wrapper cURL |
| `generateRandomString()` | 21 | Gera strings aleatórias |

### 1.4 Storage

- **Local:** `sys_get_temp_dir().'/oauth2_storage_{USER}.json'`
- **Access token (implicit):** 3600s (hardcoded)
- **Sem refresh token** no implicit flow (não é emitido)
- **Sem revogação**

### 1.5 Problemas Identificados

| # | Problema | Severidade | Local |
|---|----------|-----------|-------|
| 1 | **Callback implicit é código morto** — servidor envia token no fragment (`#`), mas o cliente lê `$_GET['access_token']`. O browser NÃO envia o fragment ao servidor, então `isset($_GET['access_token'])` nunca é true. O token nunca é capturado. | **Crítica** | `oauth2_client.php` linha 112 |
| 2 | **Token exposto na URL** — mesmo no fragment, o token pode vazar via history, logs de proxy, Referer header | Alta | Inerente ao flow |
| 3 | **State mismatch não interrompe** — mesmo bug do code flow | Alta | `oauth2_client.php` ~linha 115 |
| 4 | **Sem nonce** — iniciação não envia `nonce`, então id_token (se usado) não tem replay protection | Média | `oauth2_client.php` 825-834 |
| 5 | **id_token não assinado** — base64 JSON | Média | `generateIdToken()` |
| 6 | **Access token sem revogação** | Média | `handleUserInfo()` |
| 7 | **Implicit flow é DEPRECIADO** — RFC 8252 / OIDC 1.0 §8.2.3 recomendam Authorization Code + PKCE mesmo para SPAs | Baixa (design) | Arquitetura |

---

## Parte 2 — Plano de Melhorias (Para Implementação Futura)

### 2.1 Corrigir Callback Implicit (CRÍTICO)

**O que é:** O token chega no fragment, que só o JavaScript do browser acessa. O callback PHP server-side não pode lê-lo.

**Mudanças no cliente (`oauth2_client.php`):**
- Adicionar bloco JS no topo da página (antes do HTML) que:
  1. Lê `window.location.hash`
  2. Parseia os parâmetros (access_token, token_type, expires_in, scope, state, id_token)
  3. Valida `state` contra um valor conhecido (ver 2.2)
  4. Guarda o token (em `sessionStorage`/`localStorage` para SPA, ou envia via POST para o PHP guardar em `$_SESSION`)
  5. Limpa a URL com `history.replaceState` (remove o fragment)
  6. Faz POST para o PHP com o token → PHP guarda em `$_SESSION` e chama `/userinfo`
- Remover/ajustar o callback `$_GET['access_token']` (código morto)

**Alternativa (recomendada):** Migrar para Authorization Code + PKCE (ver 2.5)

### 2.2 Corrigir State Handling

**Mudanças no cliente:**
- Iniciação: gerar `state`, armazenar em `$_SESSION['oauth_state']`
- JS do callback: comparar `state` do fragment com valor enviado via POST; se mismatch, abortar
- Limpar `$_SESSION` após uso

### 2.3 Adicionar Nonce (para id_token)

**Mudanças no cliente:**
- Iniciação: gerar `nonce`, enviar no authorize, guardar em `$_SESSION['oauth_nonce']`
- Callback: validar `nonce` no id_token

**Mudanças no servidor:**
- `handleAuthorize()`: já aceita `nonce` (linha 227) — garantir que seja incluído no id_token

### 2.4 JWT Assinado (id_token)

Mesma melhoria do code flow (ver `PLAN_AUTHORIZATION_CODE_FLOW.md` seção 2.3):
- `generateIdToken()`: gerar JWT HS256 real
- Cliente: validar assinatura, exp, iss, aud, nonce

### 2.5 (Recomendado) Migrar para Authorization Code + PKCE

**Por quê:** O Implicit Flow é depreciado (OIDC Core 1.0 §8.2.3, RFC 8252). Para SPAs, o padrão atual é Authorization Code + PKCE, que:
- Não expõe token na URL
- Permite refresh token
- Tem PKCE contra code interception

**Mudanças:**
- Cliente: usar `response_type=code` + PKCE em vez de `response_type=token`
- Servidor: já suporta code flow + (plano PKCE do doc anterior)
- Elimina o problema crítico #1

---

## Parte 3 — Ordem de Implementação Recomendada

| Ordem | Melhoria | Dependências | Complexidade |
|-------|----------|-------------|-------------|
| 1 | Corrigir callback implicit (JS lê fragment) | Nenhuma | Média |
| 2 | Corrigir state handling | 1 | Baixa |
| 3 | Adicionar nonce | 1 | Baixa |
| 4 | JWT assinado (id_token) | Nenhuma | Média |
| 5 | (Opcional) Migrar para Auth Code + PKCE | Plano PKCE | Alta |

> **Nota:** Se o objetivo é segurança real, a opção 5 torna 1-3 desnecessárias. Para fins educacionais (mostrar como o implicit funciona), 1-4 são o caminho.

---

## Arquivos Alvo (Stack Completa)

- `c:\Projetos\estudos\php-oauth\server\oauth2_complete.php` — servidor (1358 linhas)
- `c:\Projetos\estudos\php-oauth\client\oauth2_client.php` — cliente (945 linhas)

## Fora de Escopo

- Stack simples (`oauth2_server.php` + `callback.php`)
- Outros grant types (password, client credentials)
- Mudanças de estrutura de pastas (já concluído)
- Banco de dados real (manter storage JSON)
