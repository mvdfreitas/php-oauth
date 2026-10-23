# Plano: MFA / TOTP no Login (RFC 6238)

**Projeto:** `php-oauth`
**Escopo:** `server/oauth2_complete.php` + docs
**Status:** Implementado
**Data:** 18 de Outubro de 2026
**Especificação:** RFC 6238 (TOTP), RFC 4648 (Base32), RFC 4226 (HOTP)

---

## 1. Decisões de Implementação (Confirmadas)

| Item | Decisão |
|---|---|
| Ativação | Flag por usuário: `'mfa' => true` no array `$users`. Só quem tem a flag passa por enrollment no primeiro login + exige TOTP. |
| Exibição do enrollment | Texto: URI `otpauth://totp/...` copiável + segredo base32 (sem QR imagem, zero dependências). |
| Escopo | Login interativo do `/authorize` + login do Device Flow. **Não** ROPC (password grant). |
| Replay protection | Contador (passo de 30s) usado gravado em `$storage['totp_used']` com TTL 90s; janela de verificação ±1 passo. |

---

## 2. Máquina de Estados do Login

```
POST username+password
        │
        ▼
   senha OK? ──não──▶ showLoginForm(erro)
        │sim
        ▼
  usuário tem flag 'mfa'?
   │                │
  não              sim
   │                │
   ▼                ▼
set oauth_     secret já existe?
authenticated      │          │
(user - atual)    não        sim
                  │          │
                  ▼          ▼
            enrollment    showTotpForm
            (uri+segredo   (6 dígitos)
             + código)         │
                  │            ▼
                  └──▶ verifica código (±1 passo, replay, max 5 tentativas)
                             │ OK
                             ▼
                  set oauth_authenticated_user
```

Estados intermediários na sessão:
- `mfa_pending_user`: senha OK, TOTP pendente
- `mfa_pending_secret`: secret de enrollment (só até confirmar)
- `mfa_attempts`: contador de falhas (máx. 5)

---

## 3. Funções TOTP (Helpers)

| Função | Descrição |
|---|---|
| `base32Encode(string): string` / `base32Decode(string): string` | RFC 4648 |
| `generateTotpSecret(): string` | `random_bytes(20)` (160 bits) → base32 |
| `totpGenerate(string $secret, ?int $time = null): string` | counter = `floor(time/30)` → HMAC-SHA1 → dynamic truncation → 6 dígitos |
| `totpVerify(string $secret, string $code, int $window = 1): bool` | Testa counter-1, counter, counter+1; comparação com `hash_equals` |
| `totpOtpauthUri(string $username, string $secret): string` | `otpauth://totp/php-oauth:usuario1?secret=...&issuer=php-oauth&algorithm=SHA1&digits=6&period=30` |

Parâmetros fixos: SHA1, 6 dígitos, período 30s.

---

## 4. Storage (Schema)

```php
$storage['totp_secrets'][$username] = ['secret' => '<base32>', 'created_at' => time()];
$storage['totp_used'][$username][$counter] = timestamp; // TTL 90s, poda expirados
```

---

## 5. Fluxo de Implementação

### 5.1 `handleAuthorize()`
- Novo `$is_totp_post` (POST com `totp_code`)
- Novo login invalida `mfa_pending_user/secret` e `mfa_attempts`
- Após senha OK com flag mfa:
  - Sem secret → gerar → salvar em `mfa_pending_secret` → `showTotpForm(enrollment: true)`
  - Com secret → `showTotpForm(enrollment: false)`
- POST de TOTP:
  - Enrollment: valida contra `mfa_pending_secret` → persiste em `$storage['totp_secrets']`
  - Normal: valida contra secret do storage
  - Replay: check `$storage['totp_used']` → se usado dentro de 90s → erro
  - Sucesso: grava counter, limpa pendências, seta `oauth_authenticated_user`
  - Falha: incrementa `mfa_attempts`; após 5 falhas → volta ao form de senha

### 5.2 Device Flow (`handleDevice()`)
- POST de login: se tem flag mfa → seta pendência e mostra TOTP form
- GET: continua checando apenas `oauth_authenticated_user` (só setado após TOTP OK)

### 5.3 UI
- `showTotpForm()`: input `totp_code` (6 dígitos), re-posta `authParams`, exibe enrollment info se aplicável

---

## 6. Segurança

- `hash_equals` para comparação de códigos (timing-safe)
- Replay bloqueado por contador + TTL 90s
- Rate limit: 5 tentativas → volta ao login de senha
- Segredo nunca exposto após enrollment confirmado

---

## 7. Testes

| # | Caso | Esperado |
|---|---|---|
| 1 | Login `usuario2` (sem MFA) | Fluxo inalterado |
| 2 | `usuario1` 1º login → enrollment → código | Secret persistido, login completa |
| 3 | `usuario1` login seguinte → código válido | Aprova |
| 4 | Código inválido/expirado | Rejeitado |
| 5 | Reuso do mesmo código | Bloqueado |
| 6 | 5 tentativas inválidas | Volta ao login de senha |
| 7 | `prompt=none` com sessão autenticada | Sem interação |
| 8 | Device Flow com `usuario1` | TOTP exigido |
| 9 | Storage JSON | Buckets `totp_secrets` e `totp_used` corretos |

---

## 8. Utilitário de Teste

`server/totp_code.php` — CLI: `php server/totp_code.php usuario1` imprime código atual.
