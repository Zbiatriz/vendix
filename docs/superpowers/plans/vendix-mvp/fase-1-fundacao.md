# Vendix MVP — Fase 1 — Fundação (Tasks 1–4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Projeto Laravel + Vue rodando no Sail, perfis operador/gerente, produtos/categorias e o StockService com movimentos de estoque imutáveis.

**Architecture:** App Laravel monolítico com front em Vue 3 + Inertia (Laravel Vue Starter Kit). Regra de negócio em `app/Services`; controllers só validam, autorizam via Policies e chamam o service. Estoque só muda pelo `StockService`.

**Tech Stack:** Laravel (PHP 8.4 no container), Vue 3 + Inertia + TypeScript, Vite, MySQL (Sail), Pest, Vitest, Pint, Larastan, ESLint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-15-vendix-pdv-design.md`
**Visão geral do plano (fases, estrutura de arquivos, cobertura):** `docs/superpowers/plans/vendix-mvp/00-visao-geral.md`

**Pré-requisito:** Docker Desktop ligado.

## Global Constraints

- O projeto roda pelo Docker: **todo comando PHP/Composer/npm do projeto usa `./vendor/bin/sail ...`** (na Task 1, antes do Sail existir, usa-se o container `laravelsail/php84-composer`). O Mac tem PHP 8.5 e Composer instalados via Homebrew, mas eles não são usados para rodar o projeto, para o ambiente local ser igual ao do Sail/CI.
- Regra de negócio sempre em `app/Services`, nunca no controller. Permissões sempre via Policies (`Gate::define('gerenciar')` só para telas sem model: painel e relatórios).
- Perfis: `operador` (caixa) e `gerente`.
- Dinheiro sempre em **centavos inteiros** (colunas `*_centavos`, tipo `unsignedBigInteger`/`bigInteger`). Nunca `float` para dinheiro.
- Quantidades de produto são inteiras (unidades). Produtos pesados ficam fora do MVP.
- Desconto da venda é percentual (0 a 100, até 2 casas). `desconto_centavos = round(subtotal_centavos * percentual / 100)` (arredondamento half-up de `round()` do PHP).
- Formas de pagamento: `dinheiro`, `cartao`, `pix`. Em `dinheiro`, `valor_recebido_centavos >= total_centavos` e `troco = recebido - total`.
- Status da venda: `concluida` / `cancelada`.
- Uma `CashRegisterSession` aberta por operador por vez.
- Valor esperado no fechamento = valor inicial + soma de `total_centavos` das vendas **concluídas** em **dinheiro** da sessão.
- `StockMovement` nunca é editado nem apagado; cancelamento gera novo movimento de entrada (motivo `estorno`).
- `SaleItem.preco_unitario_centavos` é snapshot do preço no momento da venda.
- Mensagens de interface e de erro em português.
- Fora de escopo: compras/fornecedores (módulo), NFC-e, promoções complexas, múltiplas lojas.
- Commits sem linha `Co-Authored-By` e sem menção a Claude/Anthropic (instrução global da usuária).

## Review Focus

Nenhum item do Review Focus geral é testado nesta fase (ver `00-visao-geral.md`).

---

### Task 1: Scaffold do projeto (Laravel + Vue Starter Kit + Sail/MySQL + Pest)

**Files:**
- Create: todo o esqueleto Laravel na raiz do repo (preservando `AGENTS.md`, `README.md`, `docs/`)
- Modify: `.env.example`, `phpunit.xml` (se necessário), `tests/Pest.php`

**Interfaces:**
- Produces: app rodando em `http://localhost` via Sail; `./vendor/bin/sail pest` executa a suíte; login/registro do starter kit funcionando; `tests/Pest.php` aplica `RefreshDatabase` em `tests/Feature`.

- [ ] **Step 1: Ligar o Docker Desktop**

Run: `open -a Docker` e aguardar até `docker info` responder sem erro.
Expected: `docker info >/dev/null && echo ok` imprime `ok`.

- [ ] **Step 2: Criar o projeto numa pasta temporária usando o container do Composer**

```bash
cd /Users/marabeatriz/vendix
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd)":/app -w /app \
  laravelsail/php84-composer:latest \
  composer create-project laravel/vue-starter-kit _scaffold --no-interaction
```
Expected: termina com `Application key set successfully` e cria `_scaffold/artisan`.

- [ ] **Step 3: Mover o esqueleto para a raiz sem sobrescrever nossos arquivos**

```bash
rsync -a --exclude .git --exclude README.md --exclude AGENTS.md --exclude CLAUDE.md _scaffold/ ./
rm -rf _scaffold
ls artisan composer.json package.json resources/js/pages
```
Expected: os quatro caminhos existem; `AGENTS.md` e `README.md` continuam com o conteúdo do Vendix (`git diff AGENTS.md README.md` vazio).

- [ ] **Step 4: Instalar o Sail com MySQL**

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd)":/app -w /app \
  laravelsail/php84-composer:latest \
  sh -c "composer require laravel/sail --dev && php artisan sail:install --with=mysql"
```
Expected: cria `compose.yaml` (ou `docker-compose.yml`) e o `.env` passa a ter `DB_CONNECTION=mysql`, `DB_HOST=mysql`.

- [ ] **Step 5: Subir o ambiente, migrar e compilar o front**

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```
Expected: `migrate` lista as migrations padrão como `DONE`; build do Vite termina sem erros; `curl -s -o /dev/null -w "%{http_code}" http://localhost` retorna `200`.

- [ ] **Step 6: Garantir Pest e RefreshDatabase**

Run: `./vendor/bin/sail composer show pestphp/pest`
- Se aparecer "Package pestphp/pest not found", rodar:
```bash
./vendor/bin/sail composer remove phpunit/phpunit --dev
./vendor/bin/sail composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
./vendor/bin/sail pest --init
```
Depois garantir que `tests/Pest.php` contenha exatamente este bloco (ajuste se o conteúdo for diferente):
```php
<?php

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```
E que `phpunit.xml` tenha `<env name="DB_CONNECTION" value="sqlite"/>` e `<env name="DB_DATABASE" value=":memory:"/>` (o banco de teste é SQLite em memória; o MySQL fica para desenvolvimento).

- [ ] **Step 7: Rodar a suíte do starter kit**

Run: `./vendor/bin/sail pest`
Expected: todos os testes de autenticação do kit passam (`Tests: N passed`).

- [ ] **Step 8: Commit**

```bash
git add -A
git status --short | grep -E '^A.*\.env$' && echo "ERRO: .env no stage" || true
git commit -m "chore: scaffold Laravel com Vue Starter Kit, Sail (MySQL) e Pest"
```
Expected: nenhum `.env` commitado (só `.env.example`).

---

### Task 2: Perfis de usuário (operador/gerente)

**Files:**
- Create: `app/Enums/UserRole.php`
- Create: `database/migrations/2026_10_15_000001_add_role_to_users_table.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/RolesTest.php`

**Interfaces:**
- Consumes: `User` do starter kit (Task 1).
- Produces: `App\Enums\UserRole` (`Operador = 'operador'`, `Gerente = 'gerente'`); `User::$role` (cast para `UserRole`); `User::isGerente(): bool`; `UserFactory::gerente()`; Gate `gerenciar`.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/RolesTest.php`:
```php
<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('cria usuários como operador por padrão', function () {
    expect(User::factory()->create()->role)->toBe(UserRole::Operador);
});

it('cria gerente pela factory', function () {
    expect(User::factory()->gerente()->create()->isGerente())->toBeTrue();
});

it('cadastro público sempre gera operador, mesmo enviando role', function () {
    $this->post('/register', [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'gerente',
    ]);

    expect(User::where('email', 'ana@example.com')->firstOrFail()->role)->toBe(UserRole::Operador);
});

it('só gerente passa no gate gerenciar', function () {
    $operador = User::factory()->create();
    $gerente = User::factory()->gerente()->create();

    expect(Gate::forUser($operador)->allows('gerenciar'))->toBeFalse()
        ->and(Gate::forUser($gerente)->allows('gerenciar'))->toBeTrue();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/RolesTest.php`
Expected: FAIL com `Class "App\Enums\UserRole" not found`.

- [ ] **Step 3: Implementar**

`app/Enums/UserRole.php`:
```php
<?php

namespace App\Enums;

enum UserRole: string
{
    case Operador = 'operador';
    case Gerente = 'gerente';

    public function label(): string
    {
        return match ($this) {
            self::Operador => 'Operador de caixa',
            self::Gerente => 'Gerente',
        };
    }
}
```

`database/migrations/2026_10_15_000001_add_role_to_users_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('operador')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
```

Em `app/Models/User.php`: **não** adicionar `role` ao `$fillable` (impede que o cadastro escolha o perfil). Adicionar o cast e o helper:
```php
use App\Enums\UserRole;

// dentro de casts():
'role' => UserRole::class,

public function isGerente(): bool
{
    return $this->role === UserRole::Gerente;
}
```

Em `database/factories/UserFactory.php`: adicionar `'role' => UserRole::Operador,` ao array de `definition()` e o state:
```php
use App\Enums\UserRole;

public function gerente(): static
{
    return $this->state(fn () => ['role' => UserRole::Gerente]);
}
```

Em `app/Providers/AppServiceProvider.php`, no `boot()`:
```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('gerenciar', fn (User $user): bool => $user->isGerente());
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail pest tests/Feature/RolesTest.php`
Expected: `4 passed`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: perfis operador e gerente com gate gerenciar"
```

---

### Task 3: Categorias e produtos (models, migrations, factories, buscas)

**Files:**
- Create: `database/migrations/2026_10_15_000002_create_categories_table.php`
- Create: `database/migrations/2026_10_15_000003_create_products_table.php`
- Create: `app/Models/Category.php`, `app/Models/Product.php`
- Create: `database/factories/CategoryFactory.php`, `database/factories/ProductFactory.php`
- Test: `tests/Feature/ProductsTest.php`

**Interfaces:**
- Produces:
  - `Category` (`id`, `nome`), `Category::products(): HasMany`.
  - `Product` (`id`, `category_id`, `nome`, `codigo_barras`, `preco_centavos`, `estoque_atual`, `estoque_minimo`), `Product::category(): BelongsTo`.
  - Scopes: `Product::abaixoDoMinimo()` (`estoque_atual < estoque_minimo`) e `Product::busca(string $termo)` (código de barras exato OU nome contendo o termo).
  - `ProductFactory` com state `comEstoque(int $quantidade)`.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/ProductsTest.php`:
```php
<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;

it('lista apenas produtos abaixo do estoque mínimo', function () {
    $baixo = Product::factory()->create(['estoque_atual' => 2, 'estoque_minimo' => 5]);
    Product::factory()->create(['estoque_atual' => 5, 'estoque_minimo' => 5]);
    Product::factory()->create(['estoque_atual' => 10, 'estoque_minimo' => 5]);

    expect(Product::abaixoDoMinimo()->pluck('id')->all())->toBe([$baixo->id]);
});

it('busca por código de barras exato', function () {
    $arroz = Product::factory()->create(['codigo_barras' => '7891000100103', 'nome' => 'Arroz 5kg']);
    Product::factory()->create(['codigo_barras' => '7891000100104', 'nome' => 'Feijão 1kg']);

    expect(Product::busca('7891000100103')->pluck('id')->all())->toBe([$arroz->id]);
});

it('busca por parte do nome', function () {
    $arroz = Product::factory()->create(['nome' => 'Arroz Branco 5kg']);
    Product::factory()->create(['nome' => 'Feijão Carioca']);

    expect(Product::busca('branco')->pluck('id')->all())->toBe([$arroz->id]);
});

it('não permite código de barras duplicado', function () {
    Product::factory()->create(['codigo_barras' => '7891000100103']);
    Product::factory()->create(['codigo_barras' => '7891000100103']);
})->throws(QueryException::class);

it('relaciona produto e categoria', function () {
    $categoria = Category::factory()->create(['nome' => 'Mercearia']);
    $produto = Product::factory()->for($categoria)->create();

    expect($produto->category->nome)->toBe('Mercearia')
        ->and($categoria->products)->toHaveCount(1);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/ProductsTest.php`
Expected: FAIL com `Class "App\Models\Product" not found`.

- [ ] **Step 3: Implementar migrations**

`database/migrations/2026_10_15_000002_create_categories_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
```

`database/migrations/2026_10_15_000003_create_products_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('nome');
            $table->string('codigo_barras', 14)->unique();
            $table->unsignedBigInteger('preco_centavos');
            $table->unsignedInteger('estoque_atual')->default(0);
            $table->unsignedInteger('estoque_minimo')->default(0);
            $table->timestamps();

            $table->index('nome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

- [ ] **Step 4: Implementar models e factories**

`app/Models/Category.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;

    protected $fillable = ['nome'];

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
```

`app/Models/Product.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    // estoque_atual fica fora do fillable: só o StockService altera o estoque.
    protected $fillable = ['category_id', 'nome', 'codigo_barras', 'preco_centavos', 'estoque_minimo'];

    protected function casts(): array
    {
        return [
            'preco_centavos' => 'integer',
            'estoque_atual' => 'integer',
            'estoque_minimo' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @param Builder<Product> $query */
    public function scopeAbaixoDoMinimo(Builder $query): void
    {
        $query->whereColumn('estoque_atual', '<', 'estoque_minimo');
    }

    /** @param Builder<Product> $query */
    public function scopeBusca(Builder $query, string $termo): void
    {
        $termo = trim($termo);

        $query->where(function (Builder $q) use ($termo) {
            $q->where('codigo_barras', $termo)
                ->orWhere('nome', 'like', '%'.$termo.'%');
        });
    }
}
```

> `estoque_atual` não é fillable, mas as factories usam `forceFill` implícito do `Factory::create()` (factories ignoram `$fillable`), então `Product::factory()->create(['estoque_atual' => 2])` funciona nos testes.

`database/factories/CategoryFactory.php`:
```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['nome' => fake()->unique()->word()];
    }
}
```

`database/factories/ProductFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'nome' => fake()->words(2, true),
            'codigo_barras' => fake()->unique()->ean13(),
            'preco_centavos' => fake()->numberBetween(100, 5000),
            'estoque_atual' => 0,
            'estoque_minimo' => 5,
        ];
    }

    public function comEstoque(int $quantidade): static
    {
        return $this->state(fn () => ['estoque_atual' => $quantidade]);
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `./vendor/bin/sail pest tests/Feature/ProductsTest.php`
Expected: `5 passed`.

> Se o teste de relacionamento falhar por `StockMovement` não existir, ignore: o método `stockMovements()` só é chamado a partir da Task 4. O teste acima não o chama.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: models de categoria e produto com buscas e alerta de mínimo"
```

---

### Task 4: Movimentos de estoque e StockService

**Files:**
- Create: `app/Enums/TipoMovimento.php`, `app/Enums/MotivoMovimento.php`
- Create: `app/Exceptions/EstoqueInsuficienteException.php`
- Create: `database/migrations/2026_10_15_000004_create_stock_movements_table.php`
- Create: `app/Models/StockMovement.php`
- Create: `app/Services/StockService.php`
- Test: `tests/Feature/StockServiceTest.php`

**Interfaces:**
- Consumes: `Product`, `ProductFactory::comEstoque()` (Task 3); `User` (Task 2).
- Produces:
  - `TipoMovimento`: `Entrada = 'entrada'`, `Saida = 'saida'`.
  - `MotivoMovimento`: `Venda = 'venda'`, `Estorno = 'estorno'`, `Ajuste = 'ajuste'`, `Fornecedor = 'fornecedor'`.
  - `StockService::entrada(Product $product, int $quantidade, MotivoMovimento $motivo, User $user, ?int $saleId = null, ?string $observacao = null): StockMovement`
  - `StockService::saida(Product $product, int $quantidade, MotivoMovimento $motivo, User $user, ?int $saleId = null, ?string $observacao = null): StockMovement`
  - Ambos travam a linha do produto (`lockForUpdate`), atualizam `$product->estoque_atual` na instância recebida e lançam `InvalidArgumentException` se `$quantidade <= 0`. `saida` lança `EstoqueInsuficienteException` (propriedades públicas `product`, `solicitado`) sem alterar nada.
  - `StockMovement` (`product_id`, `user_id`, `sale_id` nullable, `tipo`, `motivo`, `quantidade`, `saldo_apos`, `observacao`) — `update`/`delete` lançam `LogicException`.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/StockServiceTest.php`:
```php
<?php

use App\Enums\MotivoMovimento;
use App\Enums\TipoMovimento;
use App\Exceptions\EstoqueInsuficienteException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;

beforeEach(function () {
    $this->service = app(StockService::class);
    $this->user = User::factory()->gerente()->create();
});

it('registra entrada, soma no estoque e grava saldo após', function () {
    $produto = Product::factory()->comEstoque(3)->create();

    $mov = $this->service->entrada($produto, 7, MotivoMovimento::Fornecedor, $this->user);

    expect($produto->estoque_atual)->toBe(10)
        ->and($produto->fresh()->estoque_atual)->toBe(10)
        ->and($mov->tipo)->toBe(TipoMovimento::Entrada)
        ->and($mov->motivo)->toBe(MotivoMovimento::Fornecedor)
        ->and($mov->quantidade)->toBe(7)
        ->and($mov->saldo_apos)->toBe(10);
});

it('registra saída e subtrai do estoque', function () {
    $produto = Product::factory()->comEstoque(10)->create();

    $mov = $this->service->saida($produto, 4, MotivoMovimento::Ajuste, $this->user, observacao: 'Avaria');

    expect($produto->fresh()->estoque_atual)->toBe(6)
        ->and($mov->tipo)->toBe(TipoMovimento::Saida)
        ->and($mov->saldo_apos)->toBe(6)
        ->and($mov->observacao)->toBe('Avaria');
});

it('recusa saída maior que o estoque sem alterar nada', function () {
    $produto = Product::factory()->comEstoque(2)->create();

    try {
        $this->service->saida($produto, 3, MotivoMovimento::Venda, $this->user);
        $this->fail('Deveria lançar EstoqueInsuficienteException');
    } catch (EstoqueInsuficienteException $e) {
        expect($e->solicitado)->toBe(3)
            ->and($e->product->id)->toBe($produto->id)
            ->and($e->getMessage())->toContain('disponível 2');
    }

    expect($produto->fresh()->estoque_atual)->toBe(2)
        ->and(StockMovement::count())->toBe(0);
});

it('recusa quantidade zero ou negativa', function (int $qtd) {
    $produto = Product::factory()->comEstoque(5)->create();
    $this->service->entrada($produto, $qtd, MotivoMovimento::Ajuste, $this->user);
})->with([0, -1])->throws(InvalidArgumentException::class);

it('não permite editar movimento de estoque', function () {
    $produto = Product::factory()->comEstoque(5)->create();
    $mov = $this->service->entrada($produto, 1, MotivoMovimento::Ajuste, $this->user);

    $mov->update(['quantidade' => 99]);
})->throws(LogicException::class);

it('não permite apagar movimento de estoque', function () {
    $produto = Product::factory()->comEstoque(5)->create();
    $mov = $this->service->entrada($produto, 1, MotivoMovimento::Ajuste, $this->user);

    $mov->delete();
})->throws(LogicException::class);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/StockServiceTest.php`
Expected: FAIL com `Class "App\Enums\MotivoMovimento" not found`.

- [ ] **Step 3: Implementar enums e exceção**

`app/Enums/TipoMovimento.php`:
```php
<?php

namespace App\Enums;

enum TipoMovimento: string
{
    case Entrada = 'entrada';
    case Saida = 'saida';
}
```

`app/Enums/MotivoMovimento.php`:
```php
<?php

namespace App\Enums;

enum MotivoMovimento: string
{
    case Venda = 'venda';
    case Estorno = 'estorno';
    case Ajuste = 'ajuste';
    case Fornecedor = 'fornecedor';
}
```

`app/Exceptions/EstoqueInsuficienteException.php`:
```php
<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

class EstoqueInsuficienteException extends RuntimeException
{
    public function __construct(public readonly Product $product, public readonly int $solicitado)
    {
        parent::__construct(sprintf(
            'Estoque insuficiente para %s: disponível %d, solicitado %d.',
            $product->nome,
            $product->estoque_atual,
            $solicitado,
        ));
    }
}
```

- [ ] **Step 4: Implementar migration e model**

`database/migrations/2026_10_15_000004_create_stock_movements_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // FK para sales é criada na migration de sales (Task 6), que roda depois desta.
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->string('tipo', 10);
            $table->string('motivo', 20);
            $table->unsignedInteger('quantidade');
            $table->unsignedInteger('saldo_apos');
            $table->string('observacao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
```

`app/Models/StockMovement.php`:
```php
<?php

namespace App\Models;

use App\Enums\MotivoMovimento;
use App\Enums\TipoMovimento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    protected $fillable = ['product_id', 'user_id', 'sale_id', 'tipo', 'motivo', 'quantidade', 'saldo_apos', 'observacao'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimento::class,
            'motivo' => MotivoMovimento::class,
            'quantidade' => 'integer',
            'saldo_apos' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Movimentos de estoque são imutáveis. Registre um novo movimento.');
        });

        static::deleting(function () {
            throw new LogicException('Movimentos de estoque não podem ser apagados.');
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Implementar o StockService**

`app/Services/StockService.php`:
```php
<?php

namespace App\Services;

use App\Enums\MotivoMovimento;
use App\Enums\TipoMovimento;
use App\Exceptions\EstoqueInsuficienteException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    public function entrada(Product $product, int $quantidade, MotivoMovimento $motivo, User $user, ?int $saleId = null, ?string $observacao = null): StockMovement
    {
        return $this->movimentar($product, TipoMovimento::Entrada, $quantidade, $motivo, $user, $saleId, $observacao);
    }

    public function saida(Product $product, int $quantidade, MotivoMovimento $motivo, User $user, ?int $saleId = null, ?string $observacao = null): StockMovement
    {
        return $this->movimentar($product, TipoMovimento::Saida, $quantidade, $motivo, $user, $saleId, $observacao);
    }

    private function movimentar(Product $product, TipoMovimento $tipo, int $quantidade, MotivoMovimento $motivo, User $user, ?int $saleId, ?string $observacao): StockMovement
    {
        if ($quantidade <= 0) {
            throw new InvalidArgumentException('A quantidade deve ser maior que zero.');
        }

        return DB::transaction(function () use ($product, $tipo, $quantidade, $motivo, $user, $saleId, $observacao) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($tipo === TipoMovimento::Saida && $locked->estoque_atual < $quantidade) {
                throw new EstoqueInsuficienteException($locked, $quantidade);
            }

            $locked->estoque_atual += $tipo === TipoMovimento::Entrada ? $quantidade : -$quantidade;
            $locked->save();
            $product->estoque_atual = $locked->estoque_atual;

            return StockMovement::create([
                'product_id' => $locked->id,
                'user_id' => $user->id,
                'sale_id' => $saleId,
                'tipo' => $tipo,
                'motivo' => $motivo,
                'quantidade' => $quantidade,
                'saldo_apos' => $locked->estoque_atual,
                'observacao' => $observacao,
            ]);
        });
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/sail pest tests/Feature/StockServiceTest.php`
Expected: `7 passed`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: StockService com movimentos de estoque imutáveis"
```
