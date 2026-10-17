# Plano: Token Exchange (RFC 8693)

**Projeto:** `php-oauth`
**Escopo:** `server/oauth2_complete.php`, `client/oauth2_client.php`
**Status:** Implementado (validado com cURL)
**Data:** 17 de Outubro de 2026
**Especificação:** RFC 8693 - OAuth 2.0 Token Exchange

---

## 1. Visão Geral

O **Token Exchange** (RFC 8693) permite que um cliente solicite a emissão de um novo token
**a partir de um token que já possui** (o `subject_token`), sem refazer login nem passar por
todo o Authorization Code Flow. O servidor de autorização valida o token apresentado e emite
um novo access token, opcionalmente com **escopo menor**, **audiência diferente** e/ou
registro de **delegação** (um `actor_token` representando quem está agindo em nome do titular).

### Casos de uso

| Caso | Exemplo |
|---|---|
| **Delegação** (on-behalf-of) | Um serviço B atinge a API A apresentando o token do usuário + seu próprio token de serviço (`actor_token`). |
| **Impersonation** | Um admin opera temporariamente "como" outro usuário, com auditoria da atuação. |
| **Brokering de identidade** | Um AS interno troca um token de um AS federado (OIDC) por token local. |
| **Downscoping / audiência** | App com token largo troca por token de escopo menor e audiência específica antes de chamar uma API. |
| **Migração / rotação** | Troca de formato de token (ex.: JWT externo → token opaco local) sem novo consentimento. |

### Comparação com outros grant types do projeto

| Aspecto | `refresh_token` | `jwt-bearer` | **`token-exchange`** |
|---|---|---|---|
| Entrada | refresh token | JWT assertion | subject token (+ actor opcional) |
| Precisa de novo login/consent | Não | Depende | Não |
| Pode reduzir escopo | Não | Sim | **Sim (principal caso)** |
| Registra delegação (`act`) | Não | Não | **Sim (actor_token)** |
| RFC | 6749 §6 | 7523 | **8693** |

---

## 2. Objetivos

1. Implementar o grant `urn:ietf:params:oauth:grant-type:token-exchange` no token endpoint.
2. Aceitar `subject_token` opaco emitido pelo próprio servidor (buckets `access_tokens` e `refresh_tokens`).
3. Suportar `actor_token` (delegação), gravando `actor_id` na metadata do token novo.
4. Validar `scope` como **subset** do escopo do `subject_token` (nunca escala permissões).
5. Validar `audience`/`resource` contra a lista `allowed_audiences` do cliente (`400 invalid_target`).
6. Retornar `issued_token_type` em todas as respostas (exigência da RFC 8693 §2.2.1).
7. Anunciar o grant no discovery (`handleWellKnown()`) e criar aba de teste no cliente web.
8. Não alterar o comportamento dos grant types existentes (implementação somente aditiva).

---

## 3. Arquitetura do Fluxo

### 3.1 Troca simples (downscoping)

```mermaid
sequenceDiagram
    autonumber
    participant C as Cliente (confidential)
    participant S as Servidor OAuth2 (:8000)

    C->>S: POST ?action=token (Basic Auth)
       grant_type=urn:ietf:params:oauth:grant-type:token-exchange
       subject_token=<AT original>
       subject_token_type=urn:ietf:params:oauth:token-type:access_token
       scope=openid
    S->>S: Autentica cliente (já existente)
    S->>S: Localiza subject_token no storage (não expirado, mesmo client_id)
    S->>S: Valida subset de escopo
    S-->>C: 200 {access_token, issued_token_type, token_type, expires_in, scope}
```

### 3.2 Delegação com actor_token

```mermaid
sequenceDiagram
    autonumber
    participant C as Serviço B (requesting party)
    participant S as Servidor OAuth2 (:8000)

    C->>S: POST ?action=token
       subject_token=<AT do usuário>
       actor_token=<AT do Serviço B>
       actor_token_type=urn:ietf:params:oauth:token-type:access_token
       scope=openid profile
    S->>S: Valida subject_token e actor_token (ambos do mesmo cliente, ativos)
    S->>S: Emite novo token com metadata actor_id (delegação auditável)
    S-->>C: 200 {access_token, issued_token_type, ...}
    Note over S: Introspecção do novo token exibe act (fase opcional)
```

---

## 4. Parâmetros da RFC 8693

### 4.1 Requisição (form-data no `POST ?action=token`)

| Parâmetro | Obrigatório | Descrição neste projeto |
|---|:---:|---|
| `grant_type` | ✅ | `urn:ietf:params:oauth:grant-type:token-exchange` |
| `subject_token` | ✅ | Token opaco emitido por este AS (AT ou RT) |
| `subject_token_type` | ✅ | `urn:ietf:params:oauth:token-type:access_token` ou `...:refresh_token` |
| `actor_token` | ❌ | Token do requesting party (delegação) |
| `actor_token_type` | ⚠️ | Obrigatório se `actor_token` presente; mesmos URNs acima |
| `requested_token_type` | ❌ | Se presente, deve ser `...:token-type:access_token` (único tipo emitido) |
| `scope` | ❌ | Subset do escopo do `subject_token`. Ausente ⇒ herda o escopo inteiro |
| `audience` / `resource` | ❌ | Audiência alvo; validada contra `allowed_audiences` do cliente |
| `client_id` + `client_secret` | ⚠️ | Alternativa ao Basic Auth (padrão já suportado pelo `handleToken`) |

URNs aceitos como `subject_token_type` / `actor_token_type`:

```
urn:ietf:params:oauth:token-type:access_token
urn:ietf:params:oauth:token-type:refresh_token
```

> `...:token-type:jwt` (tokens de outros AS) fica para uma fase futura reaproveitando o
> `verifyJwt()` já existente no projeto (RFC 7523).

### 4.2 Resposta (HTTP 200)

```json
{
  "access_token": "9f3c...",
  "issued_token_type": "urn:ietf:params:oauth:token-type:access_token",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "openid"
}
```

| Campo | Obrigatório | Observação |
|---|:---:|---|
| `issued_token_type` | ✅ | Sempre `...:token-type:access_token` (RFC 8693 §2.2.1) |
| `access_token` | ✅ | Token opaco novo (`generateToken()`), estilo do projeto |
| `token_type` | ✅ | `Bearer` |
| `expires_in` | ✅ | `$config['access_token_expires']` (3600) |
| `scope` | ✅ | Escopo efetivamente concedido |
| `refresh_token` | ❌ | **Não emitido** nesta versão (a RFC permite; simplifica rotação/revogação) |

### 4.3 Erros

| Situação | HTTP | `error` |
|---|:---:|---|
| Método diferente de POST | 405 | `invalid_request` |
| `client_id` ausente/desconhecido | 400 | `invalid_client` (comportamento herdado do `handleToken`) |
| `client_secret` inválido | 401 | `invalid_client` |
| `subject_token` ou `subject_token_type` ausente | 400 | `invalid_request` |
| `subject_token_type` desconhecido | 400 | `invalid_request` |
| `subject_token` inexistente, expirado ou de outro cliente | 400 | `invalid_grant` |
| `actor_token` inválido / de outro cliente | 400 | `invalid_grant` |
| `actor_token` sem `actor_token_type` | 400 | `invalid_request` |
| `scope` não é subset do escopo do subject | 400 | `invalid_scope` |
| `audience`/`resource` não permitida para o cliente | 400 | `invalid_target` |
| `requested_token_type` diferente de `access_token` | 400 | `invalid_request` |
| Grant desconhecido para o cliente | 400 | `unsupported_grant_type` |

---

## 5. Modificações no Código

### 5.1 `server/oauth2_complete.php`

#### 5.1.1 Helper: localizar um token opaco no storage

Adicionar após `parseScopes()` (linha ~465), reaproveitando a lógica de buckets do
`handleIntrospect()`:

```php
// ============================================================================
// TOKEN EXCHANGE (RFC 8693) — helpers
// ============================================================================

/**
 * Localiza um token opaco (access ou refresh) no storage.
 * Retorna ['bucket' => ..., 'data' => ...] ou null.
 */
function findOpaqueToken($token, $token_type_hint) {
    $storage = loadStorage();

    $buckets = ['access_tokens', 'refresh_tokens'];
    if ($token_type_hint === 'urn:ietf:params:oauth:token-type:refresh_token') {
        $buckets = ['refresh_tokens', 'access_tokens'];
    }

    foreach ($buckets as $bucket) {
        if (!isset($storage[$bucket][$token])) {
            continue;
        }
        $data = $storage[$bucket][$token];
        if (time() > ($data['expires_at'] ?? 0)) {
            return null;
        }
        return ['bucket' => $bucket, 'data' => $data];
    }

    return null;
}

/** true se todo escopo pedido está contido no escopo concedido ao subject_token. */
function isScopeSubset($requested_scope, $granted_scope) {
    $requested = parseScopes($requested_scope);
    $granted   = parseScopes($granted_scope);
    return empty(array_diff($requested, $granted));
}
```

#### 5.1.2 Novo branch em `handleToken()`

Inserir **antes** do `else { unsupported_grant_type }` (linha ~1298), após o bloco
`device_code`:

```php
// ======================== Token Exchange (RFC 8693) ========================
elseif ($grant_type === 'urn:ietf:params:oauth:grant-type:token-exchange') {
    $subject_token       = $_POST['subject_token'] ?? null;
    $subject_token_type  = $_POST['subject_token_type'] ?? null;
    $actor_token         = $_POST['actor_token'] ?? null;
    $actor_token_type    = $_POST['actor_token_type'] ?? null;
    $requested_token_type = $_POST['requested_token_type'] ?? null;
    $requested_scope     = $_POST['scope'] ?? null;
    $audience            = $_POST['audience'] ?? $_POST['resource'] ?? null;

    $allowed_types = [
        'urn:ietf:params:oauth:token-type:access_token',
        'urn:ietf:params:oauth:token-type:refresh_token',
    ];

    if (!$subject_token || !$subject_token_type) {
        return jsonResponse(['error' => 'invalid_request', 'error_description' => 'subject_token e subject_token_type são obrigatórios'], 400);
    }
    if (!in_array($subject_token_type, $allowed_types, true)) {
        return jsonResponse(['error' => 'invalid_request', 'error_description' => 'subject_token_type não suportado'], 400);
    }
    if ($requested_token_type !== null
        && $requested_token_type !== 'urn:ietf:params:oauth:token-type:access_token') {
        return jsonResponse(['error' => 'invalid_request', 'error_description' => 'requested_token_type não suportado'], 400);
    }

    $subject = findOpaqueToken($subject_token, $subject_token_type);
    if (!$subject) {
        return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'subject_token inválido ou expirado'], 400);
    }
    if ($subject['data']['client_id'] !== $client_id) {
        return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'subject_token não pertence a este cliente'], 400);
    }

    // Delegação: valida o actor_token com as mesmas regras do subject
    $actor_id = null;
    if ($actor_token !== null) {
        if (!$actor_token_type || !in_array($actor_token_type, $allowed_types, true)) {
            return jsonResponse(['error' => 'invalid_request', 'error_description' => 'actor_token_type obrigatório e deve ser suportado'], 400);
        }
        $actor = findOpaqueToken($actor_token, $actor_token_type);
        if (!$actor || $actor['data']['client_id'] !== $client_id) {
            return jsonResponse(['error' => 'invalid_grant', 'error_description' => 'actor_token inválido ou não pertence a este cliente'], 400);
        }
        $actor_id = $actor['data']['username'];
    }

    // Escopo: subset do escopo do subject_token (nunca escala)
    $scope = $requested_scope ?: $subject['data']['scope'];
    if (!isScopeSubset($scope, $subject['data']['scope'])) {
        return jsonResponse(['error' => 'invalid_scope', 'error_description' => 'scope solicitado excede o escopo do subject_token'], 400);
    }

    // Audiência: política por cliente (secure default: rejeitar se não configurado)
    if ($audience !== null) {
        $allowed_audiences = $clients[$client_id]['allowed_audiences'] ?? [];
        if (!in_array($audience, $allowed_audiences, true)) {
            return jsonResponse(['error' => 'invalid_target', 'error_description' => 'audience não permitida para este cliente'], 400);
        }
    }

    // O subject_token NÃO é revogado: a troca é não destrutiva (RFC 8693 §2.1)
    $username = $subject['data']['username'];

    // sinaliza ao bloco comum de emissão que não deve gerar novo refresh_token
    $grant_type = 'token_exchange_opaque';
}
```

> [!IMPORTANT]
> O truque `$grant_type = 'token_exchange_opaque';` é intencional: o bloco comum de emissão
> (linhas 1321–1335) pula a geração de refresh token quando `$grant_type === 'client_credentials'`.
> Trocar essa condição para `in_array($grant_type, ['client_credentials', 'token_exchange_opaque'], true)`
> evita duplicar a lógica de emissão. Atributos extras do token (abaixo) são gravados logo após,
> no mesmo ponto onde o bloco comum grava `$storage['access_tokens'][$access_token]` — para isso,
> o bloco comum passa a aceitar um array `$extra_token_meta` (inicialmente `[]`, preenchido pelo
> branch do token exchange com `actor_id` e `audience`):

```php
// No branch do token-exchange, antes do bloco comum:
$extra_token_meta = [];
if ($actor_id !== null) {
    $extra_token_meta['actor_id'] = $actor_id;   // delegação auditável
}
if ($audience !== null) {
    $extra_token_meta['audience'] = $audience;
}

// No bloco comum de emissão (linha ~1306):
$storage['access_tokens'][$access_token] = array_merge([
    'client_id'  => $client_id,
    'username'   => $username,
    'scope'      => $scope,
    'created_at' => time(),
    'expires_at' => time() + $config['access_token_expires'],
], $extra_token_meta);
```

E no fim de `handleToken()` (antes do `jsonResponse($response)`, linha ~1344), registrar o
tipo emitido quando o grant for o de troca:

```php
if ($grant_type === 'token_exchange_opaque') {
    $response['issued_token_type'] = 'urn:ietf:params:oauth:token-type:access_token';
}
```

#### 5.1.3 Habilitar o grant para o cliente de teste

No array `$clients['cliente_teste']['grant_types']` (linha 68), adicionar:

```diff
- 'grant_types' => ['authorization_code', 'refresh_token', 'implicit', 'urn:ietf:params:oauth:grant-type:device_code', 'device_code', 'urn:ietf:params:oauth:grant-type:jwt-bearer'],
+ 'grant_types' => ['authorization_code', 'refresh_token', 'implicit', 'urn:ietf:params:oauth:grant-type:device_code', 'device_code', 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'urn:ietf:params:oauth:grant-type:token-exchange'],
```

Opcional — política de audiência por cliente (usada na validação acima):

```php
'allowed_audiences' => ['http://localhost:8000/api', 'api://gateway'],
```

#### 5.1.4 Atualizar `handleWellKnown()`

```diff
             'urn:ietf:params:oauth:grant-type:device_code',
             'device_code',
-            'urn:ietf:params:oauth:grant-type:jwt-bearer'
+            'urn:ietf:params:oauth:grant-type:jwt-bearer',
+            'urn:ietf:params:oauth:grant-type:token-exchange'
         ],
```

#### 5.1.5 (Opcional) Expor `act` na introspecção

Em `handleIntrospect()` (linha ~1475), no bloco em que a resposta ativa é montada, acrescentar:

```php
if (isset($data['actor_id'])) {
    $response['act'] = ['sub' => $data['actor_id']];   // RFC 8693 §4.1 (claim de delegação)
}
if (isset($data['audience'])) {
    $response['aud'] = $data['audience'];
}
```

#### 5.1.6 (Opcional) Página de debug

Em `handleDebug()`, na seção de endpoints, documentar:

```html
<h3 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.5rem;">Token Exchange (RFC 8693):</h3>
<div class="endpoint">
    <strong>POST /token (grant_type=urn:ietf:params:oauth:grant-type:token-exchange)</strong>
    <code>subject_token=AT&amp;subject_token_type=urn:ietf:params:oauth:token-type:access_token&amp;scope=openid</code>
</div>
```

---

### 5.2 `client/oauth2_client.php`

#### 5.2.1 Botão de fluxo

Após o botão JWT (linha ~906):

```html
<a href="?flow=exchange" class="flow-button <?php echo $flow_type === 'exchange' ? 'active' : ''; ?>">
    🔄 Token Exchange
</a>
```

#### 5.2.2 Seção do fluxo

Novo bloco `<?php if ($flow_type === 'exchange'): ?>` após a seção JWT (~linha 1400),
seguindo o padrão das seções Device/JWT:

```html
<div class="card">
    <h2>🔄 Token Exchange (RFC 8693)</h2>

    <div class="info-box">
        <strong>Como funciona:</strong>
        <ol style="margin-left: 1.5rem; margin-top: 0.5rem;">
            <li>Você já possui um access token (obtido em qualquer outro flow)</li>
            <li>Apresenta esse token como <code>subject_token</code></li>
            <li>O servidor valida e emite um novo token (ex.: com escopo menor)</li>
        </ol>
    </div>

    <div class="form-group">
        <label>Subject Token (AT ou RT existente):</label>
        <textarea id="exchange_subject_token" rows="2" placeholder="Cole aqui o access_token obtido em outro flow"></textarea>
    </div>

    <div class="form-group">
        <label>Subject Token Type:</label>
        <select id="exchange_subject_type">
            <option value="urn:ietf:params:oauth:token-type:access_token">access_token</option>
            <option value="urn:ietf:params:oauth:token-type:refresh_token">refresh_token</option>
        </select>
    </div>

    <div class="form-group">
        <label>Scope (subset do original; vazio = herda tudo):</label>
        <input type="text" id="exchange_scope" placeholder="openid">
    </div>

    <div class="form-group">
        <label>Audience (opcional):</label>
        <input type="text" id="exchange_audience" placeholder="http://localhost:8000/api">
    </div>

    <div class="form-group">
        <label>Actor Token (opcional — delegação):</label>
        <textarea id="exchange_actor_token" rows="2"></textarea>
    </div>

    <button type="button" onclick="requestTokenExchange()" style="width: 100%;">
        🔄 Solicitar Token Exchange
    </button>

    <div id="exchange-result" style="display:none; margin-top: 1rem;"></div>
</div>

<script>
function requestTokenExchange() {
    const result = document.getElementById('exchange-result');
    result.style.display = 'block';
    result.textContent = 'Consultando...';

    const formData = new URLSearchParams();
    formData.append('grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange');
    formData.append('client_id', '<?php echo $client_id; ?>');
    formData.append('client_secret', '<?php echo $client_secret; ?>');
    formData.append('subject_token', document.getElementById('exchange_subject_token').value.trim());
    formData.append('subject_token_type', document.getElementById('exchange_subject_type').value);

    const scope = document.getElementById('exchange_scope').value.trim();
    if (scope) formData.append('scope', scope);

    const aud = document.getElementById('exchange_audience').value.trim();
    if (aud) formData.append('audience', aud);

    const actor = document.getElementById('exchange_actor_token').value.trim();
    if (actor) {
        formData.append('actor_token', actor);
        formData.append('actor_token_type', 'urn:ietf:params:oauth:token-type:access_token');
    }

    fetch('<?php echo $oauth_server; ?>/oauth2_complete.php?action=token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(r => r.json())
    .then(data => {
        result.innerHTML = data.error
            ? '<pre style="color:#c00;">erro: ' + data.error + '\n' + (data.error_description || '') + '</pre>'
            : '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
    })
    .catch(err => { result.textContent = 'Erro: ' + err; });
}
</script>
```

---

## 6. Testes e Validação

### 6.1 Preparação

```bash
php -l server/oauth2_complete.php
php -S localhost:8000 -t server

# Obter um access token de usuário (Password Grant)
curl -s -u cliente_teste:senha_secreta_123 \
  -d "grant_type=password&username=usuario1&password=senha123&scope=openid profile email" \
  "http://localhost:8000/oauth2_complete.php?action=token"
```

### 6.2 Cenários

| # | Cenário | Como testar | Resultado esperado |
|:-:|---|---|---|
| 1 | Troca simples (downscoping) | `subject_token=<AT>&scope=openid` | 200, `issued_token_type` presente, `scope: openid` |
| 2 | Herda escopo | omitir `scope` | 200, `scope` = escopo original (`openid profile email`) |
| 3 | Escala de escopo | `scope=openid profile email phone` (subject tem menos) | 400 `invalid_scope` |
| 4 | Subject expirado | editar `expires_at` no storage para o passado | 400 `invalid_grant` |
| 5 | Subject de outro cliente | obter AT com outro `client_id` e trocar com `cliente_teste` | 400 `invalid_grant` |
| 6 | Subject inexistente | `subject_token=abc` | 400 `invalid_grant` |
| 7 | type errado | informar `refresh_token` para um AT | 200 — o type é só hint de ordem (RFC 8693 §2.1); o fallback encontra o AT |
| 8 | `subject_token_type` desconhecido | `...:token-type:jwt` | 400 `invalid_request` |
| 9 | Delegação | `subject_token=<AT do user>&actor_token=<AT de serviço>` | 200; introspecção exibe `act.sub` |
| 10 | Actor inválido | `actor_token=xyz` | 400 `invalid_grant` |
| 11 | Audience não permitida | `audience=http://evil.com` | 400 `invalid_target` |
| 12 | Audience permitida | `audience` listada em `allowed_audiences` | 200; introspecção exibe `aud` |
| 13 | `requested_token_type` errado | `...:token-type:refresh_token` | 400 `invalid_request` |
| 14 | Sem credenciais | remover `-u` | 400 `invalid_client` (client_id ausente); secret errado ⇒ 401 |
| 15 | Novo token funciona | usar o AT devolvido em `?action=userinfo` | 200 com dados do usuário |
| 16 | Subject não é revogado | usar o AT original após a troca | ainda válido (troca é não destrutiva) |
| 17 | Regressão | `authorization_code`, `refresh_token`, `client_credentials`, `revoke`, `introspect` | comportamento inalterado |
| 18 | Discovery | `?action=well-known` | `grant_types_supported` contém o URN do token exchange |

Exemplo do cenário 1:

```bash
curl -s -u cliente_teste:senha_secreta_123 \
  -d "grant_type=urn:ietf:params:oauth:grant-type:token-exchange" \
  -d "subject_token=AT_AQUI" \
  -d "subject_token_type=urn:ietf:params:oauth:token-type:access_token" \
  -d "scope=openid" \
  "http://localhost:8000/oauth2_complete.php?action=token"
```

---

## 7. Considerações de Segurança

| Risco | Mitigação |
|---|---|
| **Escalada de escopo** | `scope` sempre validado como subset do `subject_token` (nunca o contrário). |
| **Theft de token alheio** | `subject_token`/`actor_token` devem pertencer ao `client_id` autenticado (política estrita; troca entre clients exigiria política explícita de brokering). |
| **Replay / contaminação** | A troca não copia `actor_id`/`audience` do subject: metadata extra é construída só a partir do request validado. |
| **Audiência arbitrária** | `invalid_target` como secure default quando `allowed_audiences` não está configurado. |
| **Token como credencial em log** | Não logar `subject_token`/`actor_token`/access tokens (regra do projeto). |
| **Timing attacks** | Comparação de `client_secret` já usa o caminho existente; segredos sempre com `hash_equals` onde houver comparação nova. |
| **Tokens eternos** | Token herdado não herda `expires_at`: o novo token nasce com a expiração padrão (1h). |
| **Clients públicos** | Permitido pelo caminho existente (`isPublicClient`); em produção, token exchange em cliente público deve exigir PKCE ou restrição de grant. |

---

## 8. Ordem de Implementação Recomendada

| Etapa | Tarefa | Complexidade | Testável |
|:-:|---|:-:|:-:|
| 1 | Helpers `findOpaqueToken()` + `isScopeSubset()` | Baixa | Sim (unitário via PHP) |
| 2 | Branch do grant em `handleToken()` + `$extra_token_meta` | Média | Sim (cURL) |
| 3 | `grant_types` do cliente + `handleWellKnown()` | Baixa | Sim (well-known) |
| 4 | Seção 🔄 no cliente web | Baixa | Sim (manual) |
| 5 | Cenários de erro da tabela 6.2 | Baixa | Sim (cURL) |
| 6 | `act`/`aud` na introspecção (opcional) | Baixa | Sim (cURL) |
| 7 | Suporte a `subject_token_type:jwt` via `verifyJwt()` (futuro) | Alta | Opcional |

---

## 9. Status de Implementação

- [x] Helpers `findOpaqueToken()` e `isScopeSubset()`
- [x] Branch `token-exchange` em `handleToken()`
- [x] `$extra_token_meta` no bloco comum de emissão + `issued_token_type` na resposta
- [x] `grant_types` do `cliente_teste` + `handleWellKnown()`
- [x] Seção 🔄 no `oauth2_client.php`
- [x] Cenários de teste com cURL validados
- [x] `act`/`aud` na introspecção (opcional)
- [x] Documentação atualizada (INDEX)

---

## 10. Referências

- [RFC 8693 - OAuth 2.0 Token Exchange](https://datatracker.ietf.org/doc/html/rfc8693)
- [RFC 8707 - Resource Indicators for OAuth 2.0](https://datatracker.ietf.org/doc/html/rfc8707) (`resource`/`audience`)
- [RFC 7662 - OAuth 2.0 Token Introspection](https://datatracker.ietf.org/doc/html/rfc7662) (exposição de `act`)
- [RFC 6749 - OAuth 2.0 Authorization Framework](https://datatracker.ietf.org/doc/html/rfc6749)
- [RFC 7519 - JSON Web Token](https://datatracker.ietf.org/doc/html/rfc7519) (fase futura: subject JWT)
