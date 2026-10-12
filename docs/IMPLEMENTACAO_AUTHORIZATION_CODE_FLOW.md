# Registro de Alterações — Authorization Code Flow

**Stack:** `server/oauth2_complete.php` + `client/oauth2_client.php`
**Data:** 2026-10-11
**Base:** `docs/PLAN_AUTHORIZATION_CODE_FLOW.md` (Parte 2)
**Status:** Implementado e validado

---

## 1. Objetivo

Implementar as 5 melhorias de segurança planejadas para o Authorization Code Flow,
corrigindo os bugs da Parte 1 do plano:

1. Corrigir state/nonce e tratamento de erros
2. Corrigir o consent form (separar login de consentimento)
3. PKCE (RFC 7636)
4. Refresh token rotation + revogação (RFC 7009)
5. id_token assinado (JWT)

---

## 2. Resumo das mudanças

| # | Item | Arquivo(s) | Tipo |
|---|------|-----------|------|
| 1 | State/erros + helpers de autorização | server | Correção |
| 2 | Consent form | server | Correção |
| 3 | PKCE | server + client | Feature |
| 4 | Refresh rotation + revocation | server + client | Feature |
| 5 | JWT (`auth_time`) + discovery | server | Ajuste |
| — | `code_expires` via `$config` | server | Correção |
| — | `well-known` aponta para `oauth2_complete.php` | server | Correção |

---

## 3. Servidor (`server/oauth2_complete.php`)

### 3.1 Funções novas

| Função | Papel |
|--------|-------|
| `redirectWithError($redirect_uri, $error, $description, $state, $response_type)` | Devolve erro OAuth2 ao cliente via redirect usando `#` (implicit) ou `?` (code), preservando `state`. |
| `verifyPkce($verifier, $challenge, $method)` | Valida `code_verifier` contra o `code_challenge` (S256/plain, RFC 7636). Retorna `true` quando o code não usou PKCE. |
| `consentIsGranted($client_id, $requested_scopes)` | Verifica se os scopes pedidos já foram consentidos na sessão. |
| `grantConsent($client_id, $scopes)` | Memoriza o consentimento por client em `$_SESSION['oauth_consent']`. |
| `completeAuthorization(array $authParams, $username)` | Emite a resposta final (tokens do implicit ou authorization code de uso único), já com PKCE e `code_expires` do `$config`. |
| `handleRevoke()` | Endpoint `?action=revoke` (RFC 7009), com auth do client via POST ou Basic. |

### 3.2 `handleAuthorize()` reescrito

- Lê `code_challenge` / `code_challenge_method` (default `plain` quando ausente), valida contra `['plain','S256']`.
- Erros de `scope` e `response_type` passam a usar `redirectWithError()` (com `state`) após o `redirect_uri` ser validado.
- **Autenticação separada do consentimento:**
  - Login bem-sucedido grava `$_SESSION['oauth_authenticated_user']`; nova tentativa invalida autenticação anterior.
  - Sem login/sessão → mostra `showLoginForm`.
  - Autenticado e sem consentimento (ou `prompt=consent`) → mostra `showConsentForm`.
  - Consentimento concedido (`consent=1`) → grava consentimento e emite o code/token; recusado (`consent=0`) → `error=access_denied`.
  - Aplica apenas os scopes marcados no consent (interseção com os solicitados).
- `prompt=none`: sem interação; sem sessão → `interaction_required`; sem consentimento → `consent_required`.
- Estado do request centralizado em `$authParams` (client_id, redirect_uri, scope, state, nonce, response_type, prompt, code_challenge, code_challenge_method).

### 3.3 Formulários

- `showLoginForm(array $authParams, $error, $login_hint)`: assinatura simplificada, hidden inputs gerados a partir de `$authParams`, **removido o hidden `consent=1`** que burlava o consentimento; exibe info de PKCE/nonce.
- `showConsentForm(array $authParams, array $requested_scopes)`: **removido o campo `password`** e o `username` oculto (usa sessão); botão "Recusar" agora é `submit name="consent" value="0"` tratado no servidor; hidden inputs gerados de `$authParams`.

### 3.4 `handleToken()`

- Novo campo `code_verifier`.
- `authorization_code`: valida PKCE antes de consumir o code (single-use mantido).
- `refresh_token`:
  - Valida `expires_at` (expiração de 30 dias).
  - **Rotação:** o refresh token usado é removido do storage e um novo é emitido.
- Refresh token **não** é emitido para `client_credentials` (antes era gravado e apenas omitido da resposta).
- Todo refresh token agora recebe `expires_at` (`$config['refresh_token_expires']`).

### 3.5 `handleRevoke()` (novo endpoint)

- Aceita `token` + `token_type_hint` (`access_token`/`refresh_token`), autenticando o client.
- Tenta o hint indicado e depois o outro tipo.
- Só revoga tokens do próprio client.
- Revogar um refresh token invalida em cascata os access tokens do mesmo client/usuário.
- Retorna `200` com `{"revoked":bool}` mesmo para token já inválido (RFC 7009). Rota adicionada ao `switch` (`case 'revoke'`).

### 3.6 `generateIdToken()` e `well-known`

- `generateIdToken()`: adicionado claim `auth_time` (além de `iss`, `sub`, `aud`, `exp`, `iat`, `nonce`); assinatura HS256 já existente.
- `handleWellKnown()`:
  - Endpoints corrigidos de `/index.php?...` para `/oauth2_complete.php?...`.
  - Adicionados `revocation_endpoint`, `revocation_endpoint_auth_methods_supported`, `code_challenge_methods_supported`.
  - `id_token_signing_alg_values_supported` ajustado para `['HS256']` (não havia RS256).

---

## 4. Cliente (`client/oauth2_client.php`)

- **PKCE no code flow:** gera `code_verifier` (`base64url(random_bytes(32))`) e `code_challenge` (S256), guarda o verifier em `$_SESSION['oauth_code_verifier']` e envia `code_challenge` + `code_challenge_method=S256`.
- **Token exchange:** envia `code_verifier`; limpa o verifier da sessão ao final (sucesso e em state mismatch).
- **`prompt=none`:** novo checkbox na UI; tem precedência sobre `prompt=consent`.
- **Revogar token:** novo botão (POST `revoke_token`) que chama `?action=revoke` e limpa a sessão local em caso de sucesso.
- Renovação de token (refresh) já substitui o `refresh_token` local — compatível com a rotação do servidor.

---

## 5. Mudanças de comportamento

- O Implicit Flow também passa pela tela de consentimento (antes era dispensado pelo hidden `consent=1`).
- Login e consentimento são etapas distintas; `prompt` é preservado através dos formulários.

---

## 6. Validação

- `php -l` sem erros nos dois arquivos.
- Harness black-box com `php -S` + cURL (executado fora do repositório):
  - **Authorization Code + PKCE:** 22/22 testes — login→consent→code→token (S256), code de uso único, PKCE obrigatório, userinfo, rotação de refresh, revoke (sucesso, cascata, credencial inválida), erros com `state`, `prompt=none`, `access_denied`, `well-known`.
  - **Regressão:** 7/7 testes — implicit (`id_token token`), userinfo do implicit, password grant, client_credentials (sem refresh), credenciais inválidas.

### Teste manual

```bash
php -S localhost:8000 server/oauth2_complete.php
php -S localhost:8001 client/oauth2_client.php
# http://localhost:8001/oauth2_client.php → flow=code
```

---

## 7. Fora de escopo / pendências

- Senhas de `$users` ainda em plaintext — só faz sentido resolver junto de `password_hash`/storage real.
- Página de debug mantém exemplos com `redirect_uri=.../index.php` (URI válida registrada).
- Suíte de testes permanente no repositório (`tests/`) não foi criada (harness foi temporário).
