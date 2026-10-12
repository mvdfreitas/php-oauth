# Device Authorization Grant (RFC 8628) - Implementação

## Visão Geral

O Device Authorization Grant (Device Flow) foi implementado conforme a RFC 8628 para permitir autenticação em dispositivos com entrada limitada (CLIs, TVs, consoles, etc.).

## Arquivos Modificados/Criados

### Servidor
- **`server/oauth2_complete.php`**: Implementação completa do Device Flow
  - Configurações: `device_code_expires` (600s), `device_polling_interval` (5s)
  - Funções: `generateDeviceCode()`, `generateUserCode()`
  - Endpoints: `device_code`, `device`
  - Suporte a grant types no token endpoint

### Cliente Web
- **`client/oauth2_client.php`**: Adicionada aba "📺 Device Flow (CLI / TV)" com polling via JavaScript

### Cliente CLI
- **`client/device_cli.php`** (novo): Script CLI independente simulando `gh auth login`

## Endpoints

### 1. Device Authorization Endpoint
```http
POST /oauth2_complete.php?action=device_code
Content-Type: application/x-www-form-urlencoded

client_id=cliente_teste&scope=openid profile email
```

**Resposta (200 OK):**
```json
{
  "device_code": "4f9a8b1c2d3e...",
  "user_code": "BCDF-GHJK",
  "verification_uri": "http://localhost:8000/oauth2_complete.php?action=device",
  "verification_uri_complete": "http://localhost:8000/oauth2_complete.php?action=device&user_code=BCDF-GHJK",
  "expires_in": 600,
  "interval": 5
}
```

### 2. Device Verification Endpoint (UI)
```http
GET /oauth2_complete.php?action=device&user_code=BCDF-GHJK
```
Renderiza telas para: inserção de user_code, login do usuário e consentimento de escopos.

### 3. Token Endpoint (Polling)
```http
POST /oauth2_complete.php?action=token
Content-Type: application/x-www-form-urlencoded

grant_type=urn:ietf:params:oauth:grant-type:device_code
&device_code=SEU_DEVICE_CODE
&client_id=cliente_teste
&client_secret=senha_secreta_123
```

**Respostas:**
- `200 OK` com tokens (quando aprovado)
- `400` com `{"error": "authorization_pending"}` (ainda pendente)
- `400` com `{"error": "slow_down"}` (polling muito rápido - intervalo +5s)
- `400` com `{"error": "expired_token"}` (código expirado)
- `400` com `{"error": "access_denied"}` (usuário negou)

## Discovery (.well-known)

```json
{
  "device_authorization_endpoint": "http://localhost:8000/oauth2_complete.php?action=device_code",
  "grant_types_supported": [
    "authorization_code",
    "implicit",
    "refresh_token",
    "password",
    "client_credentials",
    "urn:ietf:params:oauth:grant-type:device_code",
    "device_code"
  ]
}
```

## Como Testar

### Via Cliente Web
1. Iniciar servidores: `php -S localhost:8000 -t server` e `php -S localhost:8001 -t client`
2. Acessar: http://localhost:8001/oauth2_client.php?flow=device
3. Clicar em "Iniciar Device Flow"
4. Abrir URL exibida no navegador, fazer login (usuario1/senha123) e autorizar
5. O dispositivo recebe tokens automaticamente via polling

### Via CLI
```bash
php client/device_cli.php
```
Seguir instruções exibidas no terminal.

### Via cURL
```bash
# 1. Obter device code
curl -s -X POST "http://localhost:8000/oauth2_complete.php?action=device_code" \
  -d "client_id=cliente_teste" \
  -d "scope=openid profile email"

# 2. Polling (enquanto pendente)
curl -s -X POST "http://localhost:8000/oauth2_complete.php?action=token" \
  -d "client_id=cliente_teste" \
  -d "client_secret=senha_secreta_123" \
  -d "grant_type=device_code" \
  -d "device_code=SEU_DEVICE_CODE"
```

## Fluxo

1. Dispositivo solicita `device_code` + `user_code`
2. Exibe instruções ao usuário
3. Usuário acessa `verification_uri` no navegador, autentica e consente
4. Dispositivo faz polling periódico até aprovação/negação/expiração
5. Ao aprovado, recebe `access_token`, `refresh_token` e `id_token` (se scope openid)

## Características

- Geração de `user_code` no formato `XXXX-XXXX` com caracteres legíveis (sem 0/O/I/1)
- Expiração em 600s (10 minutos)
- Intervalo de polling padrão 5s, com `slow_down` automático
- Suporte a ambos os formatos de grant_type (com e sem URN completa)
- Armazenamento em arquivo JSON temporário
- Telas HTML responsivas e consistentes com o resto do projeto
