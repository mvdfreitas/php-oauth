# Convenções de Código

Regras obrigatórias para todo código novo a partir da refatoração MVC (PHP 8.5).

## Obrigatório

- `declare(strict_types=1);` na primeira instrução de **todos** os arquivos PHP.
- Namespaces PSR-4: `App\` mapeado para `app/`.
- PSR-12 para formatação.
- Classes `final` por padrão; `readonly` para DTOs e Value Objects.
- Enums para valores fechados (grant types, response types, erros etc.).
- Sem `global`, sem estado compartilhado em `$_SESSION` fora da camada de sessão.
- Entrada externa sempre validada; SQL sempre com prepared statements.
- Sem credenciais, tokens ou dados sensíveis em logs.

## Código legado

Os arquivos em `server/` e `client/` permanecem procedurais até serem migrados nas fases
seguintes do plano. Não adicione novas responsabilidades a eles: toda implementação nova
deve ir para `app/`.
