# Plano: SAML 2.0 Assertion (RFC 7522)

**Projeto:** `php-oauth`
**Escopo:** `server/oauth2_complete.php`, `client/oauth2_client.php`
**Status:** Planejado
**Data:** 18 de Outubro de 2026
**Especificações:** RFC 7522, OASIS SAML 2.0 Core, XML Signature (XMLDSig)

---

## 1. Visão Geral

O **RFC 7522** (SAML 2.0 Bearer Assertion Profiles for OAuth 2.0) define dois perfis de uso de SAML 2.0 Assertions com OAuth 2.0:

1. **Client Authentication** (autenticação de cliente no Token Endpoint)
   - `client_assertion_type = urn:ietf:params:oauth:client-assertion-type:saml2-bearer`
   - `client_assertion = <SAML 2.0 Assertion>`

2. **Authorization Grant** (concessão via SAML2 Bearer)
   - `grant_type = urn:ietf:params:oauth:grant-type:saml2-bearer`
   - `assertion = <SAML 2.0 Assertion>`

O projeto já possui **JWT Bearer Assertion (RFC 7523)**, o que serve como referência arquitetural.

---

## 2. Decisões de Implementação (Definidas)

| Item | Decisão | Justificativa |
|---|---|---|
| **XMLDSig** | Mínimo (Enveloped Signature, RSA-SHA256, C14N). Rejeitar casos não suportados com erro claro. | Alinha-se ao caráter didático do projeto. Reduz complexidade e riscos. |
| **Encoding da Assertion** | Aceitar XML bruto, Base64 e Base64URL. | Robusto e compatível com diferentes IdPs/geradores. |
| **Mapeamento Subject → usuário** | Usar `NameID` diretamente como username (igual ao array `$users`). | Simples e consistente com o projeto. Pode evoluir futuramente. |
| **Replay Protection** | Não implementar na primeira versão. Apenas validação temporal (NotBefore/NotOnOrAfter, SubjectConfirmation). Documentar limitação. | Reduz complexidade inicial. Essencial para produção, mas não crítico para didático. |
| **Perfis suportados** | Ambos: Client Authentication + Grant Type SAML2 Bearer. | Completo conforme RFC 7522. Útil para testes. |
| **Formato de chaves** | Suportar X.509 (`-----BEGIN CERTIFICATE-----`) e PEM de chave pública (`-----BEGIN PUBLIC KEY-----`). | Comum em SAML (muitos IdPs fornecem certificado X.509). |
| **Clock Skew** | 60 segundos. | Padrão conservador e comum. |
| **Geração de SAML de teste** | Gerar Assertion SAML mínima com assinatura RSA-SHA256 no próprio cliente (PHP/JS) para facilitar testes. | Mantém projeto autocontido, sem depender de IdP externo. |

---

## 3. Arquitetura

### 3.1 Trust Model (Confiança por Cliente)

Seguindo padrão do JWT Bearer, a confiança em Issuers SAML será configurada por cliente:

```php
'cliente_saml' => [
    'client_id' => 'cliente_saml',
    'client_secret' => null,
    'redirect_uris' => [],
    'grant_types' => ['urn:ietf:params:oauth:grant-type:saml2-bearer'],
    'token_endpoint_auth_methods' => ['client_secret_basic'],
    'saml_trust' => [
        'issuers' => ['https://idp.example.com/issuer', 'test-saml-issuer'],
        'audience' => ['http://localhost:8000/oauth2_complete.php', 'cliente_saml'],
        'keys' => [
            'x509' => '-----BEGIN CERTIFICATE----- ... -----END CERTIFICATE-----',
            // ou 'public_key_pem' => '-----BEGIN PUBLIC KEY----- ... -----END PUBLIC KEY-----'
        ],
        'clock_skew' => 60,
    ],
]
```

### 3.2 Validações RFC 7522

| Validação | Descrição |
|---|---|
| **XML válido** | Assertion deve ser XML SAML 2.0 bem formada (DOMDocument). |
| **Issuer** | `saml:Issuer` deve estar em `issuers` confiáveis para contexto (cliente). |
| **Assinatura XMLDSig** | Verificar assinatura (Enveloped). Suportar RSA-SHA256 + C14N. |
| **Conditions** | Validar `NotBefore`/`NotOnOrAfter` com clock skew. |
| **Audience** | `AudienceRestriction/Audience` deve incluir token endpoint ou client_id (conforme RFC 7522). |
| **SubjectConfirmation (Bearer)** | `Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"`. `Recipient` deve corresponder ao token endpoint (`http://localhost:8000/oauth2_complete.php?action=token` ou base). `NotOnOrAfter` válido. |
| **Subject/NameID** | Extrair para mapear → username. |

---

## 4. Funções a Implementar

| Função | Propósito |
|---|---|
| `samlDecodeAssertion(string $assertion)` | Tenta decodificar: XML bruto → se inválido tenta Base64 → se inválido tenta Base64URL. Retorna XML string ou false. |
| `parseSamlAssertion(string $xml)` | Parse DOMDocument com namespaces SAML. Extrai Issuer, ID, IssueInstant, Conditions, Subject (NameID, SubjectConfirmation), Attributes. Retorna ['valid'=>bool, 'error'=>..., 'data'=>...] |
| `extractPublicKeyFromCert(string $certPem)` | Extrai chave pública PEM de certificado X.509. |
| `validateSamlSignature(DOMDocument $doc, string $publicKeyPemOrCert)` | Validação XMLDSig mínima (Enveloped). Suporta RSA-SHA256 + C14N. Retorna ['valid'=>bool, 'error'=>...] |
| `validateSamlConditions(array $data, array $trustConfig, string $expectedAudience)` | Valida temporalidade + Audience. |
| `validateSamlSubjectConfirmation(array $data, string $recipient)` | Valida Bearer confirmation (Recipient, NotOnOrAfter). |
| `validateSamlAssertion(string $assertion, array $trustConfig, string $recipient, ?string $clientId)` | Validação completa RFC 7522. Retorna ['valid'=>bool, 'error'=>..., 'subject'=>string, 'issuer'=>string] |
| `findClientBySamlIssuer(string $issuer)` | Busca cliente cujo `saml_trust.issuers` contenha o issuer. |

---

## 5. Integração com Código Existente

### 5.1 `handleToken()` - Client Authentication SAML2 Bearer

Quando `client_assertion_type === 'urn:ietf:params:oauth:client-assertion-type:saml2-bearer'`:
1. Extrair `client_assertion`
2. Decodificar (XML/Base64/Base64URL)
3. Parsear + validar assinatura/condições com trust config do cliente (inferir cliente via `client_id` POST ou via `findClientBySamlIssuer`)
4. Se válido → autenticar cliente (dispensar client_secret conforme configuração)
5. Prosseguir com grant

### 5.2 `handleToken()` - Grant Type SAML2 Bearer

Quando `grant_type === 'urn:ietf:params:oauth:grant-type:saml2-bearer'`:
1. Requer `assertion`. Cliente já autenticado.
2. Validar assertion com `saml_trust` do cliente autenticado, `recipient = token endpoint URL`
3. Extrair `NameID` → mapear para username em `$users`
4. Se usuário existe → emitir tokens (access_token, refresh_token, id_token se scope openid)
5. Resposta JSON padrão OAuth2

### 5.3 `handleWellKnown()` - Discovery

Adicionar:
```php
'grant_types_supported' => [..., 'urn:ietf:params:oauth:grant-type:saml2-bearer'],
```

### 5.4 Configuração de Exemplo

Adicionar cliente `cliente_saml` com trust config (chaves de teste auto-geradas/documentadas).

---

## 6. Segurança (Hardening)

| Medida | Implementação |
|---|---|
| **XXE** | Desabilitar entidades externas: `libxml_disable_entity_loader(true)` (PHP < 8.0 compat) ou usar `LIBXML_NOENT` com extremo cuidado. Preferir parsing seguro. |
| **XML Bomb** | Validar tamanho da assertion (limitar a ~256KB razoável). Rejeitar muito grandes. |
| **Signature Validation** | Apenas algoritmos suportados (RSA-SHA256). Rejeitar outros explicitamente. |
| **Canonicalization** | Suportar C14N padrão; rejeitar transforms não suportados com erro claro. |

---

## 7. Testes

### 7.1 Testes Manuais (cURL)

**Grant SAML2 Bearer:**
```bash
curl -X POST "http://localhost:8000/oauth2_complete.php?action=token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=cliente_saml" \
  -d "grant_type=urn:ietf:params:oauth:grant-type:saml2-bearer" \
  -d "assertion=<SAML_ASSERTION>"
```

**Client Auth + Grant (combinado):**
```bash
curl -X POST "http://localhost:8000/oauth2_complete.php?action=token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_assertion_type=urn:ietf:params:oauth:client-assertion-type:saml2-bearer" \
  -d "client_assertion=<SAML_CLIENT_ASSERTION>" \
  -d "grant_type=urn:ietf:params:oauth:grant-type:saml2-bearer" \
  -d "assertion=<SAML_ASSERTION>"
```

### 7.2 Casos de Teste

| Caso | Resultado Esperado |
|---|---|
| Assertion válida (assinada, prazos OK, Recipient/ Audience OK) | 200 OK + access_token |
| Assinatura inválida | 400 invalid_grant |
| Expirada (NotOnOrAfter < agora) | 400 invalid_grant |
| NotBefore futuro | 400 invalid_grant |
| Audience incorreto | 400 invalid_grant |
| Recipient incorreto | 400 invalid_grant |
| Issuer não confiável | 400 invalid_grant/invalid_client |
| Algoritmo não suportado | 400 invalid_grant |
| XML mal formado | 400 invalid_grant |
| Sem assinatura | 400 invalid_grant |

---

## 8. Ordem de Execução

1. **Configuração**: Adicionar cliente `cliente_saml` + trust config + chaves de teste
2. **Decodificação**: `samlDecodeAssertion()` (XML/Base64/Base64URL)
3. **Parsing**: `parseSamlAssertion()` com DOMDocument + namespaces SAML
4. **XMLDSig**: `validateSamlSignature()` (validação mínima enveloped RSA-SHA256)
5. **Validações RFC 7522**: Conditions, SubjectConfirmation, Audience
6. **Validação completa**: `validateSamlAssertion()` + helpers
7. **Integração `handleToken()`**: Client Auth + Grant Type
8. **Discovery**: Atualizar `.well-known`
9. **Cliente Web**: Aba "SAML2 Bearer" + gerador de SAML de teste
10. **Testes**: Validação com SAML gerado localmente
11. **Documentação**: Atualizar `ANALISE_METODOS_AUTENTICACAO.md`

---

## 9. Limitações Conhecidas

- **XMLDSig**: Suporte apenas a subset comum (Enveloped, RSA-SHA256, C14N). Transformações complexas (ExclusiveC14N com comentários, XPath, etc.) não são suportadas → rejeitadas com erro claro.
- **Replay Protection**: Não implementada (apenas validação temporal). Para produção, recomenda-se cache de Assertion ID com TTL.
- **Atributos SAML**: Foco em `NameID`. `AttributeStatement` é parseado mas mapeamento customizado não implementado nesta versão.
