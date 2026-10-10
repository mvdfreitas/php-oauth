# Plano - Testes do Implicit Flow (PHP puro, sem dependências)

## Objetivo
Suíte de testes black-box (HTTP) para o fluxo Implicit de `server/oauth2_complete.php`.

## Estrutura
- `tests/run_implicit.php` (executável: `php tests/run_implicit.php`)
- (opcional) `tests/Harness.php` - helpers reaproveitáveis para outros fluxos

## Harness
- `startServer()`: sobe `php -S 127.0.0.1:8999 server/oauth2_complete.php`
  (`proc_open` + `register_shutdown_function` para matar o processo)
- `httpRequest()`: curl com `CURLOPT_HEADER=true`, `FOLLOWLOCATION=false` (captura `Location`)
- `parseFragment()`: extrai params após `#`
- `decodeJwt()` / `verifyHs256()`: valida `id_token` com o `jwt_secret`
  (`segredo_jwt_local_dev_1234567890`)
- `clearStorage()`: remove `/tmp/oauth2_storage_$USER.json`
- `assert`/`check` + resumo + `exit(1)` em caso de falha

## Casos de teste

Sucesso:
1. `response_type=token` -> 302, fragment com `access_token`/`token_type=Bearer`/`expires_in=3600`/`scope`/`state`
2. `response_type=id_token` -> JWT válido (`sub`, `aud`, `nonce`, assinatura HS256)
3. `response_type=id_token token` -> ambos os tokens presentes
4. `access_token` do caso 1 em `?action=userinfo` -> 200 com `sub=usuario1`
5. `scope=email` -> userinfo sem `phone_number` (filtragem de claims)

Erros:
6. `client_id` inválido -> 400 ("client_id inválido")
7. `redirect_uri` não cadastrada -> 400
8. `scope` inexistente -> 400
9. `response_type` não permitido (ex.: `foo`) -> 400
10. senha incorreta -> 200 HTML com "Senha incorreta"
11. `prompt=none` com credenciais válidas -> 302 para `redirect_uri?error=interaction_required`

## Execução
```bash
php tests/run_implicit.php
php tests/run_implicit.php --base-url=http://127.0.0.1:8000
```

## Notas
- Black-box pois os arquivos executam o roteador no top-level (`session_start`, `exit`)
- `oauth2_server.php` tem caminho Windows fixo e `password_hash` -> follow-up
