# Plano: OIDC Hybrid Flow (OIDC Core 1.0)

**Projeto:** `php-oauth`
**Escopo:** `server/oauth2_complete.php`, `client/oauth2_client.php`
**Status:** Planejado
**Data:** 18 de Outubro de 2026
**Especificação:** OpenID Connect Core 1.0 - Hybrid Flow

---

## 1. Visão Geral

O **OIDC Hybrid Flow** combina Authorization Code Flow e Implicit Flow: o Authorization Endpoint retorna, na mesma resposta, um `authorization code` e alguns tokens (`access_token` e/ou `id_token`), dependendo do valor de `response_type`.

**Response types híbridos (OIDC Core 1.0):**
- `code token` → retorna `code` + `access_token`
- `code id_token` → retorna `code` + `id_token`
- `code id_token token` → retorna `code` + `access_token` + `id_token`

**Diferenças vs fluxos existentes:**
- **vs Authorization Code puro (`code`)**: evita 1 round-trip (token obtido já na autorização), mas ainda permite validação do `code` no token endpoint (com PKCE).
- **vs Implicit puro (`token`, `id_token`, `id_token token`)**: ainda emite `code` que pode ser trocado no token endpoint; reduz exposição direta dependendo do caso.

---

## 2. Comportamento de Redirecionamento (response_mode)

Por padrão (OIDC Core 1.0), em fluxos híbridos:
- O `code` vai na **query string** (`?code=...`)
- Os tokens (`access_token`, `id_token`) vão no **fragment** (`#access_token=...&id_token=...`)

Exemplo: `http://localhost:8001/callback.php?code=XXX#access_token=YYY&id_token=ZZZ&state=...`

Também deve-se considerar `response_mode` quando informado:
- `query` → tudo na query (menos comum para híbridos com tokens)
- `fragment` → tudo no fragmento

Para este projeto, seguiremos: **default = code em query + tokens em fragmento**. Se `response_mode=fragment`, tudo no fragmento. Se `response_mode=query`, tudo na query.

---

## 3. Estado Atual vs Necessário

| Item | Atual | Necessário |
|---|---|---|
| `redirectWithError()` | Decide `?` vs `#` baseado apenas em response_types puramente implícitos (`token`, `id_token`, `id_token token`) | Deve considerar fragmento quando `response_type` contém `token` ou `id_token` (implícito OU híbrido) |
| `completeAuthorization()` | Trata apenas implícito puro OU code puro | Tratar híbridos: gerar `code` E retornar tokens (fragmento por default) na mesma resposta |
| `handleAuthorize()` | Não lê `response_mode`. Não valida `nonce` quando `id_token` presente | Ler `response_mode`. Exigir `nonce` quando `id_token` estiver em `response_type` (implícito ou híbrido). Passar `response_mode` adiante |
| `$clients['cliente_teste']['response_types']` | Não inclui híbridos | Incluir `code token`, `code id_token`, `code id_token token` |
| Discovery (`.well-known`) | Já lista híbridos em `response_types_supported` | OK (já presente) |
| Cliente web | Sem aba Hybrid Flow | Adicionar aba "🔀 Hybrid Flow" com UI e parsing de query+fragment |

---

## 4. Alterações no Servidor (`server/oauth2_complete.php`)

### 4.1 `redirectWithError()` (linhas ~340-355)

Atualmente:
```php
$is_fragment = in_array($response_type, ['token', 'id_token', 'id_token token'], true);
```

Alterar para detectar qualquer response_type que contenha tokens:
```php
$is_fragment = (strpos($response_type, 'token') !== false || strpos($response_type, 'id_token') !== false);
```

Ou `preg_match('/\b(token|id_token)\b/', $response_type) === 1`.

**Justificativa:** Em híbridos (ex.: `code token`), erros também devem ser retornados no fragmento, pois tokens seriam retornados lá.

### 4.2 `handleAuthorize()` (linhas ~562-680)

Adicionar:
- Leitura de `response_mode`: `$response_mode = $_REQUEST['response_mode'] ?? null;`
- Validação de `nonce`: se `response_type` contém `id_token`, `nonce` é **obrigatório** (OIDC Core 1.0). Se ausente, retornar `invalid_request` com descrição apropriada.
- Passar `response_mode` para `completeAuthorization()` via `$authParams` (já existe estrutura similar)
- Validar `response_mode` vs `response_type` se desejado (básico: se contém tokens, `fragment` é mais comum; não bloquear por enquanto, seguir default)

Também incluir `response_mode` em `$authParams` salvos:
```php
'response_mode' => $response_mode,
```

### 4.3 `completeAuthorization()` (linhas ~420-460)

Detectar fluxos híbridos: response_types que contêm **ambos** `code` e (`token` ou `id_token`).

```php
$has_code = strpos($response_type, 'code') !== false;
$has_token = strpos($response_type, 'token') !== false;
$has_id_token = strpos($response_type, 'id_token') !== false;
$response_mode = $authParams['response_mode'] ?? null;
```

**Caso 1: Híbrido** (`$has_code && ($has_token || $has_id_token)`)
1. Gerar `auth code` e salvar no storage (igual ao fluxo code puro). Incluir `nonce`, `code_challenge`, `code_challenge_method`, `response_type`, `response_mode`.
2. Montar resposta conforme `response_mode` (default: code em query + tokens em fragmento)
3. Adicionar tokens ao fragmento se solicitados:
   - `access_token` + `token_type=Bearer` + `expires_in` + `scope` se `$has_token`
   - `id_token` se `$has_id_token` (gerar com `nonce`)
4. Montar URL:
   - Default: `$redirect_uri . '?' . http_build_query($query) . '#' . http_build_query($frag)`
   - Se `response_mode === 'fragment'`: tudo em fragmento → `$redirect_uri . '#' . http_build_query(array_merge($query, $frag))`
   - Se `response_mode === 'query'`: tudo em query → `$redirect_uri . '?' . http_build_query(array_merge($query, $frag))`

**Caso 2: Implícito puro** (já existente) - sem `code`, apenas tokens → fragmento
**Caso 3: Code puro** (já existente) - apenas `code` → query

### 4.4 Clientes (`$clients`)

Atualizar `cliente_teste`:
```php
'response_types' => ['code', 'token', 'id_token', 'id_token token', 'code token', 'code id_token', 'code id_token token'],
```

Atualizar `spa_publica` (opcional, mas válido):
```php
'response_types' => ['code', 'code token', 'code id_token', 'code id_token token'],
```

PKCE continua obrigatório para clientes públicos em qualquer fluxo com `code`.

### 4.5 Discovery

Já correto em `handleWellKnown()` - `response_types_supported` já inclui híbridos. `response_modes_supported` já tem `query`, `fragment`.

---

## 5. Alterações no Cliente (`client/oauth2_client.php`)

Adicionar nova aba **"🔀 Hybrid Flow"** após "🔄 Token Exchange" (ou em posição lógica).

### 5.1 UI
Campos:
- `client_id` (default: `cliente_teste`)
- `redirect_uri` (default: `http://localhost:8001/oauth2_client.php` ou `callback.php` conforme padrão)
- `response_type` (select): `code token`, `code id_token`, `code id_token token`
- `scope` (default: `openid profile`)
- `state` (gerado aleatoriamente)
- `nonce` (gerado aleatoriamente - importante quando id_token presente)
- `code_challenge` / `code_verifier` (PKCE) - opcional para cliente confidencial, mas bom ter toggle
- Botão "Iniciar Hybrid Flow"

### 5.2 Lógica
1. Ao submeter, montar URL para `authorize` com `response_type`, `nonce`, `state`, `scope`, `client_id`, `redirect_uri`, `code_challenge` se habilitado.
2. Redirecionar para servidor.
3. Na página de callback/mesma página, detectar se URL contém `code` em query E tokens em fragmento (ou vice-versa). Extrair ambos:
   - Parse query: `code`, `state`, `error`, etc.
   - Parse fragment: `access_token`, `id_token`, `token_type`, `expires_in`, `scope`, `state`
4. Exibir resultados separados: "Authorization Code", "Access Token", "ID Token (JWT)".
5. Botão "Trocar code por token" (grant_type=authorization_code) - deve funcionar normalmente com o `code` recebido.
6. Botão "Decodificar ID Token" (header/payload) se presente.

### 5.3 Parsing de URL com query+fragment
Em PHP no cliente: após redirect, ler `$_GET` (query) e `$_SERVER['REQUEST_URI']` ou extrair fragmento via JS? Ou processar no lado servidor/PHP.

No `oauth2_client.php`, ao carregar, verificar se há parâmetros em query e também fragmento. Como fragmento não chega ao PHP, melhor fazer parsing via JavaScript para extrair fragmento e exibi-lo.

Exemplo JS:
```javascript
if (window.location.hash) {
  const params = new URLSearchParams(window.location.hash.substring(1));
  // preencher campos
}
```

---

## 6. Testes Planejados

### 6.1 Testes via cURL (após login via browser ou simulando)

**Teste 1: `code token`**
```bash
# Após obter code+token via browser flow, ou testar fluxo completo
curl -X GET "http://localhost:8000/oauth2_complete.php?action=authorize&client_id=cliente_teste&redirect_uri=http://localhost:8001/oauth2_client.php&response_type=code%20token&scope=openid%20profile&state=test123"
```
Resultado esperado: redirect com `?code=...#access_token=...&token_type=Bearer&expires_in=3600&scope=openid%20profile&state=test123`

**Teste 2: `code id_token` com nonce**
```bash
curl -X GET "...&response_type=code%20id_token&nonce=abc123&state=s1"
```
→ `?code=XXX#id_token=JWT&state=s1`

**Teste 3: `code id_token token` com nonce**
```bash
curl -X GET "...&response_type=code%20id_token%20token&nonce=abc123&state=s2"
```
→ `?code=XXX#access_token=YYY&id_token=ZZZ&token_type=Bearer&expires_in=3600&scope=openid%20profile&state=s2`

**Teste 4: Trocar code por token (continua funcionando)**
```bash
curl -X POST "http://localhost:8000/oauth2_complete.php?action=token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=cliente_teste&client_secret=senha_secreta_123&grant_type=authorization_code&code=SEU_CODE&redirect_uri=http://localhost:8001/oauth2_client.php"
```
→ deve retornar `access_token`, `refresh_token`, `id_token` (conforme implementação existente)

**Teste 5: `code id_token` sem nonce (deve falhar)**
```bash
curl -X GET "...&response_type=code%20id_token&state=s3" (sem nonce)
```
→ redirect com `error=invalid_request` e `error_description` no fragmento (pois contém id_token)

**Teste 6: Cliente público com PKCE (`spa_publica`)**
```bash
# com code_challenge válido
curl -X GET "http://localhost:8000/oauth2_complete.php?action=authorize&client_id=spa_publica&redirect_uri=http://localhost:8001/oauth2_client.php&response_type=code%20token&scope=openid%20profile&state=s4&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256"
```
→ deve funcionar (code em query + token em fragmento)

**Teste 7: Fluxos existentes não quebram**
- `response_type=code` puro → continua funcionando
- `response_type=token` puro → continua funcionando (fragmento)
- `response_type=id_token token` puro → continua funcionando

---

## 7. Riscos e Cuidados

- **Nonce obrigatório**: OIDC Core 1.0 exige `nonce` quando `id_token` é solicitado. Rejeitar com `invalid_request` é correto.
- **PKCE**: clientes públicos devem ter `code_challenge` obrigatório (já validado). Em híbrido continua valendo.
- **Fragment vs Query para erros**: `redirectWithError` deve usar fragmento se response_type contém tokens. Correto para UX (cliente consegue ler fragmento).
- **Backward compatibility**: mudanças são aditivas. Lógica existente preservada.
- **Code reuse**: `auth code` gerado em híbrido é válido para troca no token endpoint - comportamento correto.
- **State**: sempre retornar `state` quando presente.

---

## 8. Plano de Execução (passo a passo)

1. **[Servidor]** Modificar `redirectWithError()` - detectar fragmento para response_types com token/id_token
2. **[Servidor]** Modificar `handleAuthorize()` - ler `response_mode`, validar `nonce` se `id_token` presente, incluir em params
3. **[Servidor]** Modificar `completeAuthorization()` - implementar lógica híbrida (code + tokens)
4. **[Servidor]** Atualizar `$clients['cliente_teste']['response_types']` com híbridos
5. **[Servidor]** Atualizar `$clients['spa_publica']['response_types']` com híbridos (opcional mas recomendado)
6. **[Cliente]** Adicionar aba "🔀 Hybrid Flow" em `oauth2_client.php` com UI
7. **[Cliente]** Implementar parsing JS para query+fragment e exibição de resultados
8. **[Teste]** Testar via navegador: `code token`, `code id_token` (com nonce), `code id_token token`
9. **[Teste]** Testar troca de code por token após hybrid flow
10. **[Teste]** Validar fluxos existentes não quebraram (code puro, implicit puro)
11. **[Documentação]** Atualizar `docs/ANALISE_METODOS_AUTENTICACAO.md` marcando Hybrid Flow como implementado

---

## 9. Critérios de Aceite

- [ ] `code token` retorna `code` em query + `access_token` em fragmento
- [ ] `code id_token` com `nonce` retorna `code` em query + `id_token` em fragmento
- [ ] `code id_token token` com `nonce` retorna todos conforme esperado
- [ ] `code id_token` sem `nonce` retorna `error=invalid_request`
- [ ] `code` gerado em híbrido pode ser trocado com sucesso no token endpoint
- [ ] Erros em híbridos são redirecionados com fragmento (quando tokens envolvidos)
- [ ] Fluxos `code`, `token`, `id_token token` existentes continuam funcionando
- [ ] Cliente público `spa_publica` funciona com híbridos + PKCE
- [ ] Aba Hybrid Flow no cliente exibe corretamente code + tokens
