# Plano de Refatoração para Padrão MVC - PHP 8.5

## 1. Análise do Projeto Atual

### Estado Atual
- **Sem Composer** - Não possui `composer.json`
- **Arquitetura procedural** - Tudo em um único arquivo (`server/oauth2_complete.php` com ~1400 linhas, `server/oauth2_server.php` ~593 linhas)
- **Sem namespaces** - Código PHP puro sem `namespace`
- **Lógica misturada** - Rotas, regras de negócio, views HTML, validação tudo junto
- **Sessão e storage global** - Uso extensivo de `global` e `$_SESSION`
- **Arquivos múltiplos** - `server/`, `client/`, `session/` com estrutura bem simples

### Pontos a refatorar
1. **Separação de responsabilidades** - Aplicar MVC (Model, View, Controller)
2. **Namespaces** - Usar PSR-4 com namespaces
3. **Composer** - Adicionar autoload PSR-4
4. **Organização** - Reestruturar pastas de forma lógica
5. **Nomenclatura** - Renomear arquivos para seguir convenções PHP modernas (PascalCase para classes, etc.)
6. **Reutilização** - Extrair serviços, helpers, validações

## 2. Novas Features PHP 8.5 a Utilizar

| Feature | Onde aplicar | Justificativa |
| :-- | :-- | :-- |
| `readonly` classes e propriedades | DTOs, Value Objects, Configurações e modelos imutáveis. | Garante imutabilidade e previne alterações acidentais em objetos sensíveis (Tokens, Claims, etc). |
| Enums (Backed Enums) | `GrantType`, `ResponseType`, `TokenType`, `ErrorCode` | Substitui strings mágicas por tipos fortes, melhorando autocomplete e validação. |
| Property Hooks | Entidades/Models que precisem de normalização (ex: escopos) | Elimina getters/setters verbosos sem perder controle sobre os dados. |
| Visibilidade assimétrica | Propriedades que precisam ser lidas externamente, mas modificadas apenas internamente | Ótimo para services e repositórios, evitando métodos `get` desnecessários. |
| Atributos (`#[...]`) | Rotas, Middlewares, Permissões e Validações | Permite definir rotas diretamente nos controllers ao invés de um arquivo gigante de rotas. |
| `match`, constructor promotion, `first-class callable syntax` | Em todo o código novo | Já utilizado em projetos modernos, deixa o código mais limpo. |
| `array_first()` e `array_last()` | Manipulação de arrays (tokens, scopes) | Mais legível que `reset()/end()` ou indexação manual. |
| Pipe Operator (`\|>`) | Transformações de dados (ex: parsing de scope, filtragem de claims) | Melhora a fluidez e legibilidade de pipelines. |

## 3. Nova Estrutura Proposta

```text
php-oauth/
├── app/
│   ├── Attributes/      # Atributos para rotas/middlewares (ex: #[Route], #[Middleware])
│   ├── Config/           # Configurações da aplicação
│   ├── Contracts/        # Interfaces para Services/Repositories (facilita injeção de dependência)
│   ├── Controllers/      # Controllers (Auth, Token, UserInfo, WellKnown, etc.)
│   ├── Core/            # Núcleo da aplicação (Router, Application, Request, Response)
│   ├── Enums/           # Enums para tipos do OAuth2
│   ├── Helpers/         # Funções auxiliares
│   ├── Middleware/      # Middlewares
│   ├── Models/          # Models/Entities (Client, User, Token, etc.)
│   ├── Repositories/    # Repositórios para acesso a dados
│   ├── Services/        # Regras de negócio (OAuthService, JwtService, TotpService, etc.)
│   ├── ValueObjects/    # DTOs imutáveis com readonly classes
│   └── Views/           # Views (templates HTML)
├── bootstrap/           # Arquivos de inicialização
├── public/              # Ponto de entrada web (index.php, assets)
├── routes/              # Definição de rotas
├── storage/             # Arquivos de armazenamento (migrado de session/)
├── tests/               # Testes PHPUnit (futuro)
├── vendor/              # Composer dependencies
├── composer.json
└── README.md
```

## 4. Composer.json Proposto

```json
{
    "name": "projeto/php-oauth",
    "description": "Servidor OAuth2 completo em PHP 8.5",
    "type": "project",
    "require": {
        "php": "^8.5"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        },
        "files": [
            "app/Helpers/functions.php"
        ]
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true,
        "platform": {
            "php": "8.5.0"
        }
    },
    "scripts": {
        "start": "php -S localhost:8000 -t public",
        "dump": "composer dump-autoload -o"
    }
}
```

## 5. Exemplos de Implementação com PHP 8.5

### Enum
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum GrantType: string
{
    case AUTHORIZATION_CODE = 'authorization_code';
    case REFRESH_TOKEN = 'refresh_token';
    case CLIENT_CREDENTIALS = 'client_credentials';
    case PASSWORD = 'password';
    case DEVICE_CODE = 'urn:ietf:params:oauth:grant-type:device_code';
    case JWT_BEARER = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    case TOKEN_EXCHANGE = 'urn:ietf:params:oauth:grant-type:token-exchange';
    case SAML2_BEARER = 'urn:ietf:params:oauth:grant-type:saml2-bearer';
}
```

### Value Object com readonly class
```php
<?php

declare(strict_types=1);

namespace App\ValueObjects;

readonly class TokenResponse
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int $expiresIn,
        public ?string $refreshToken = null,
        public string $scope = '',
        public ?string $idToken = null,
    ) {}
}
```

### Atributo para rotas
```php
<?php

declare(strict_types=1);

namespace App\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
readonly class Route
{
    public function __construct(
        public string $method,
        public string $path,
    ) {}
}
```

### Controller utilizando atributos
```php
<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Attributes\Route;
use App\Core\Controller;

final readonly class AuthorizeController extends Controller
{
    #[Route(method: 'GET', path: '/authorize')]
    #[Route(method: 'POST', path: '/authorize')]
    public function index(): void
    {
        // ...
    }
}
```

## 6. Plano de Execução (Fases)

### Fase 1: Preparação e Configuração
- [ ] **1.1** Validar versão do PHP localmente (`php -v`) antes de iniciar. Garantir que o ambiente rode PHP 8.5.
- [ ] **1.2** Criar `composer.json` com autoload PSR-4 (conforme proposto acima)
- [ ] **1.3** Criar estrutura de pastas base (app/, public/, routes/, storage/, bootstrap/)
- [ ] **1.4** Configurar `.gitignore` adequado
- [ ] **1.5** Adicionar `declare(strict_types=1);` como regra obrigatória em todos os arquivos

### Fase 2: Núcleo da Aplicação
- [ ] **2.1** Criar `app/Core/Application.php` - Classe principal
- [ ] **2.2** Criar `app/Core/Router.php` - Sistema de rotas com suporte a atributos (reflection)
- [ ] **2.3** Criar `app/Core/Request.php` - Abstração de requisição
- [ ] **2.4** Criar `app/Core/Response.php` - Abstração de resposta
- [ ] **2.5** Criar `app/Core/Controller.php` - Base Controller
- [ ] **2.6** Criar `bootstrap/app.php` - Bootstrap da aplicação

### Fase 3: Configuração, Enums, Attributes e Helpers
- [ ] **3.1** Migrar configurações para `app/Config/`
  - `App.php` - Configurações gerais
  - `OAuth.php` - Configurações OAuth2
  - `Clients.php` - Clientes cadastrados
  - `Users.php` - Usuários cadastrados
- [ ] **3.2** Criar Enums em `app/Enums/` (GrantType, ResponseType, TokenType, ErrorCode)
- [ ] **3.3** Criar Attributes em `app/Attributes/` (Route, Middleware)
- [ ] **3.4** Criar Contracts/Interfaces em `app/Contracts/`
- [ ] **3.5** Criar `app/Helpers/functions.php` - Helpers globais

### Fase 4: Services (Regras de Negócio)
- [ ] **4.1** `app/Services/OAuth/OAuthService.php` - Lógica principal OAuth2 (usar `match` para grant types)
- [ ] **4.2** `app/Services/Jwt/JwtService.php` - Gerenciamento JWT
- [ ] **4.3** `app/Services/Totp/TotpService.php` - TOTP/MFA
- [ ] **4.4** `app/Services/Storage/StorageService.php` - Gerenciamento de storage
- [ ] **4.5** `app/Services/Saml/SamlService.php` - SAML 2.0
- [ ] **4.6** `app/Services/Token/TokenService.php` - Geração e validação de tokens
- [ ] **4.7** Utilizar Enums em vez de strings mágicas

### Fase 5: Models, ValueObjects e Repositories
- [ ] **5.1** Criar ValueObjects imutáveis com `readonly` em `app/ValueObjects/`
- [ ] **5.2** Criar Models simples
  - `app/Models/Client.php`
  - `app/Models/User.php`
  - `app/Models/AuthorizationCode.php`
  - `app/Models/AccessToken.php`
- [ ] **5.3** Criar Repositories para abstrair acesso a dados
  - `app/Repositories/ClientRepository.php`
  - `app/Repositories/UserRepository.php`
  - `app/Repositories/TokenRepository.php`
  - `app/Repositories/AuthCodeRepository.php`
- [ ] **5.4** Utilizar property hooks caso algum modelo precise de computação

### Fase 6: Controllers
- [ ] **6.1** `app/Controllers/Auth/AuthorizeController.php` - Endpoint authorize (usar atributos #[Route])
- [ ] **6.2** `app/Controllers/Auth/TokenController.php` - Endpoint token
- [ ] **6.3** `app/Controllers/Auth/UserInfoController.php` - Endpoint userinfo
- [ ] **6.4** `app/Controllers/Auth/DeviceController.php` - Device Flow
- [ ] **6.5** `app/Controllers/Auth/RevokeController.php` - Token revocation
- [ ] **6.6** `app/Controllers/Auth/IntrospectController.php` - Token introspection
- [ ] **6.7** `app/Controllers/Discovery/WellKnownController.php` - .well-known/openid-configuration
- [ ] **6.8** `app/Controllers/Debug/DebugController.php` - Página de debug
- [ ] **6.9** Tornar controllers `final` quando não forem herdados, usar constructor promotion

### Fase 7: Views e Templates
- [ ] **7.1** Criar sistema de views simples
- [ ] **7.2** Migrar HTML inline para templates
  - `app/Views/auth/login.php`
  - `app/Views/auth/consent.php`
  - `app/Views/auth/totp.php`
  - `app/Views/auth/totp-enroll.php`
  - `app/Views/debug/index.php`

### Fase 8: Rotas e Ponto de Entrada
- [ ] **8.1** Criar `routes/web.php` - Rotas web
- [ ] **8.2** Criar `routes/api.php` - Rotas API OAuth2
- [ ] **8.3** Criar `public/index.php` - Front controller
- [ ] **8.4** Criar `public/oauth.php` ou manter compatibilidade com URLs antigas

### Fase 9: Migração do Cliente
- [ ] **9.1** Reorganizar `client/` seguindo MVC também (opcional mas recomendado)
- [ ] **9.2** Ou mover para `app/Client/` ou manter separado com nova estrutura

### Fase 10: Testes e Ajustes
- [ ] **10.1** Testar todos os fluxos OAuth2
- [ ] **10.2** Verificar compatibilidade de URLs
- [ ] **10.3** Migrar storage de `session/oauth2_storage.json` para `storage/`
- [ ] **10.4** Atualizar README.md
- [ ] **10.5** Verificar com `php -l` em todos os arquivos
- [ ] **10.6** Checar por possíveis deprecações PHP 8.5
- [ ] **10.7** Rodar `composer dump-autoload -o`

## 7. Checklist de Validação

- [ ] `composer dump-autoload` funciona sem erros
- [ ] Todos os arquivos possuem `declare(strict_types=1);`
- [ ] Enums utilizados ao invés de strings mágicas onde apropriado
- [ ] Value Objects imutáveis com `readonly` para dados sensíveis
- [ ] Suporte a atributos para rotas implementado
- [ ] Todos os endpoints OAuth2 funcionam
- [ ] Fluxo Authorization Code funciona
- [ ] Fluxo PKCE funciona
- [ ] MFA/TOTP funciona
- [ ] Device Flow funciona
- [ ] Token Exchange funciona
- [ ] SAML2 Bearer funciona
- [ ] Token Introspection funciona
- [ ] Token Revocation funciona
- [ ] Cliente ainda consegue se comunicar
- [ ] Storage persiste corretamente
- [ ] Sem warnings/erros PHP
- [ ] PHPStan/Psalm configurado para PHP 8.5 (futuro)

## 8. Riscos e Mitigações

| Risco | Impacto | Mitigação |
|-------|---------|-----------|
| Quebrar fluxos existentes | Alto | Manter compatibilidade com URLs antigas, testar cada fluxo |
| Perda de dados de storage | Alto | Copiar `session/oauth2_storage.json` para novo local antes de migrar |
| Complexidade excessiva | Médio | Seguir princípio KISS, não over-engineerizar, migração gradual |
| Incompatibilidade com cliente | Alto | Manter mesma interface de API |
| Incompatibilidade com PHP 8.5 | Alto | Testar ambiente local primeiro, usar PHPStan para detectar issues |

## 9. Recomendações Adicionais

- **Strict Types**: `declare(strict_types=1);` em absolutamente todos os arquivos PHP novos.
- **Testar o ambiente**: Antes de refatorar, rode o projeto atual com PHP 8.5 para identificar qualquer deprecação ou warning.
- **Migração gradual**: Não tente converter tudo de uma vez para atributos. Comece pelo core (Router + Controllers principais) e vá expandindo. Mantenha compatibilidade com rotas registradas manualmente enquanto migra.
- **Ferramentas**: Adicionar `phpstan/phpstan` com `phpVersion: 80500` e `rector/rector` configurado para PHP 8.5 para automatizar atualizações.
- **Performance**: O autoload otimizado (`composer dump-autoload -o`) combinado com OPcache irá trazer bons ganhos.
- **Segurança**: Continuar usando `hash_equals` para comparações sensíveis, prepared statements se migrar para banco real.

## 10. Comando para Iniciar

```bash
# 1. Verificar versão do PHP
php -v

# 2. Criar composer.json e instalar
composer init --no-interaction --name="projeto/php-oauth" --type=project --require="php:^8.5"

# 3. Configurar autoload
composer dump-autoload

# 4. Iniciar servidor de desenvolvimento
php -S localhost:8000 -t public
```

**Observação:** Este é um plano detalhado para execução em modo Build/Agent. Seguir fase por fase, testando após cada etapa para garantir que nenhum fluxo OAuth2 seja quebrado.
