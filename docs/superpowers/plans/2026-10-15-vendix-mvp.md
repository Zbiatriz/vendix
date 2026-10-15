# Vendix MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir o MVP do Vendix — PDV de supermercado com perfis, produtos/estoque auditável, abertura/fechamento de caixa, vendas com baixa de estoque, cancelamento com estorno, alerta de estoque mínimo e relatórios.

**Architecture:** App Laravel monolítico com front em Vue 3 + Inertia (Laravel Vue Starter Kit). Toda regra de negócio fica em `app/Services` (`StockService`, `CashRegisterService`, `SaleService`, `ReportService`); controllers só validam entrada, autorizam via Policies e chamam o service. Estoque só muda através do `StockService`, que sempre grava um `StockMovement` (auditoria imutável).

**Tech Stack:** Laravel (PHP 8.4 no container), Vue 3 + Inertia + TypeScript, Vite, MySQL (Sail), Pest, Vitest + @vue/test-utils, Laravel Pint, Larastan nível 5, ESLint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-15-vendix-pdv-design.md`

## Global Constraints

- Não há PHP nem Composer instalados no Mac: **todo comando PHP/Composer/npm roda via Docker** (`docker run ... laravelsail/php84-composer` antes do Sail existir; `./vendor/bin/sail ...` depois).
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

1. **Mesmo produto adicionado duas vezes no carrinho** — a validação de estoque precisa somar as quantidades; com estoque 5 e duas linhas de 3, a venda é recusada. (Teste na Task 7.)
2. **Cancelar uma venda já cancelada** — não pode estornar o estoque duas vezes; deve dar erro e não alterar nada. (Teste na Task 7.)
3. **Vender/cancelar em sessão fechada ou de outro operador** — finalizar venda com sessão fechada falha; operador não cancela venda de outro operador nem de sessão já fechada. (Testes nas Tasks 7 e 9.)
4. **Quantidade, desconto ou valor recebido inválidos** — quantidade ≤ 0, desconto < 0 ou > 100, ou dinheiro recebido menor que o total são recusados com mensagem de validação, sem gravar nada. (Testes nas Tasks 7 e 9.)
5. **Arredondamento de centavos com desconto** — subtotal R$ 9,99 com 10% de desconto dá desconto de R$ 1,00 (99,9 → 100 centavos) e total R$ 8,99, igual no backend e no carrinho do front. (Testes nas Tasks 7 e 10.)

---

## File Structure

```
app/
├── Enums/
│   ├── UserRole.php              # operador | gerente
│   ├── FormaPagamento.php        # dinheiro | cartao | pix
│   ├── SaleStatus.php            # concluida | cancelada
│   ├── TipoMovimento.php         # entrada | saida
│   └── MotivoMovimento.php       # venda | estorno | ajuste | fornecedor
├── Exceptions/
│   ├── EstoqueInsuficienteException.php
│   ├── CaixaJaAbertoException.php
│   ├── CaixaNaoAbertoException.php
│   └── VendaJaCanceladaException.php
├── Http/Controllers/
│   ├── Gerente/ProductController.php
│   ├── Gerente/CategoryController.php
│   ├── Gerente/StockEntryController.php
│   ├── Gerente/DashboardController.php
│   ├── Gerente/ReportController.php
│   ├── CashRegisterController.php
│   ├── PdvController.php
│   ├── ProductSearchController.php
│   ├── SaleController.php
│   └── SaleCancellationController.php
├── Models/  User, Category, Product, StockMovement, CashRegisterSession, Sale, SaleItem
├── Policies/ ProductPolicy, CategoryPolicy, CashRegisterSessionPolicy, SalePolicy
└── Services/ StockService, CashRegisterService, SaleService, ReportService
resources/js/
├── composables/useCart.ts        # lógica pura do carrinho (testada no Vitest)
├── components/Cart.vue
├── components/LeitorCodigoBarras.vue
└── pages/  pdv/Index.vue, caixa/Abrir.vue, caixa/Fechar.vue, caixa/Resumo.vue,
            gerente/Dashboard.vue, gerente/Relatorios.vue,
            gerente/produtos/Index.vue, gerente/produtos/Form.vue, gerente/categorias/Index.vue
tests/Feature/ Roles, Products, StockService, ProductCrud, CashRegisterService, SaleService,
               CashRegisterHttp, PdvHttp, Reports, Seeder
resources/js/**/__tests__/ useCart.test.ts, Cart.test.ts
docs/arquitetura.md, docs/regras-de-negocio.md, .github/workflows/ci.yml, phpstan.neon
```

> Observação: o Vue Starter Kit usa diretórios em minúsculas (`resources/js/pages`, `components`, `layouts`). Seguimos o kit; o AGENTS.md é atualizado na Task 13.

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

---

### Task 5: CRUD de produtos/categorias e movimentação manual de estoque (gerente)

**Files:**
- Create: `app/Policies/ProductPolicy.php`, `app/Policies/CategoryPolicy.php`
- Create: `app/Http/Controllers/Gerente/ProductController.php`, `app/Http/Controllers/Gerente/CategoryController.php`, `app/Http/Controllers/Gerente/StockEntryController.php`
- Create: `resources/js/lib/dinheiro.ts`
- Create: `resources/js/pages/gerente/produtos/Index.vue`, `resources/js/pages/gerente/produtos/Form.vue`, `resources/js/pages/gerente/categorias/Index.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProductCrudTest.php`

**Interfaces:**
- Consumes: `Product`, `Category` (Task 3); `StockService`, `MotivoMovimento`, `EstoqueInsuficienteException` (Task 4); `User::isGerente()` (Task 2).
- Produces:
  - Rotas (todas em `auth` + `verified`, prefixo `/gerente`, nomes `gerente.*`): `GET /gerente/produtos`, `GET /gerente/produtos/create`, `POST /gerente/produtos`, `GET /gerente/produtos/{product}/edit`, `PUT /gerente/produtos/{product}`, `POST /gerente/produtos/{product}/movimentos`, `GET /gerente/categorias`, `POST /gerente/categorias`.
  - `resources/js/lib/dinheiro.ts`: `formatarCentavos(centavos: number): string` (ex.: `1250 → "R$ 12,50"`) e `paraCentavos(valor: string): number` (ex.: `"12,50" → 1250`, `"12.5" → 1250`, `"" → 0`).
  - Bloco de rotas autenticadas em `routes/web.php` onde as próximas tasks adicionam suas rotas.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/ProductCrudTest.php`:
```php
<?php

use App\Enums\MotivoMovimento;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gerente = User::factory()->gerente()->create();
    $this->operador = User::factory()->create();
    $this->categoria = Category::factory()->create();
});

it('bloqueia operador nas telas de gerente', function () {
    $this->actingAs($this->operador)->get('/gerente/produtos')->assertForbidden();
    $this->actingAs($this->operador)->get('/gerente/categorias')->assertForbidden();
});

it('lista produtos para o gerente', function () {
    Product::factory()->count(2)->create();

    $this->actingAs($this->gerente)->get('/gerente/produtos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gerente/produtos/Index')
            ->has('products.data', 2));
});

it('gerente cadastra produto com estoque inicial zero', function () {
    $this->actingAs($this->gerente)->post('/gerente/produtos', [
        'category_id' => $this->categoria->id,
        'nome' => 'Arroz 5kg',
        'codigo_barras' => '7891000100103',
        'preco_centavos' => 2590,
        'estoque_minimo' => 10,
        'estoque_atual' => 500,
    ])->assertRedirect('/gerente/produtos');

    $produto = Product::where('codigo_barras', '7891000100103')->firstOrFail();
    expect($produto->preco_centavos)->toBe(2590)
        ->and($produto->estoque_atual)->toBe(0);
});

it('valida código de barras duplicado e preço inválido', function () {
    Product::factory()->create(['codigo_barras' => '7891000100103']);

    $this->actingAs($this->gerente)->post('/gerente/produtos', [
        'category_id' => $this->categoria->id,
        'nome' => 'Outro',
        'codigo_barras' => '7891000100103',
        'preco_centavos' => 0,
        'estoque_minimo' => 0,
    ])->assertSessionHasErrors(['codigo_barras', 'preco_centavos']);
});

it('gerente atualiza produto mas não consegue mexer no estoque pelo formulário', function () {
    $produto = Product::factory()->comEstoque(5)->create();

    $this->actingAs($this->gerente)->put("/gerente/produtos/{$produto->id}", [
        'category_id' => $produto->category_id,
        'nome' => 'Novo nome',
        'codigo_barras' => $produto->codigo_barras,
        'preco_centavos' => 999,
        'estoque_minimo' => 3,
        'estoque_atual' => 1000,
    ])->assertRedirect('/gerente/produtos');

    $produto->refresh();
    expect($produto->nome)->toBe('Novo nome')
        ->and($produto->preco_centavos)->toBe(999)
        ->and($produto->estoque_atual)->toBe(5);
});

it('registra entrada de fornecedor pelo endpoint de movimentos', function () {
    $produto = Product::factory()->comEstoque(5)->create();

    $this->actingAs($this->gerente)->post("/gerente/produtos/{$produto->id}/movimentos", [
        'tipo' => 'entrada',
        'motivo' => 'fornecedor',
        'quantidade' => 20,
    ])->assertRedirect();

    expect($produto->fresh()->estoque_atual)->toBe(25)
        ->and(StockMovement::first()->motivo)->toBe(MotivoMovimento::Fornecedor);
});

it('recusa saída maior que o estoque com mensagem', function () {
    $produto = Product::factory()->comEstoque(2)->create();

    $this->actingAs($this->gerente)->post("/gerente/produtos/{$produto->id}/movimentos", [
        'tipo' => 'saida',
        'motivo' => 'ajuste',
        'quantidade' => 3,
    ])->assertSessionHasErrors('quantidade');

    expect($produto->fresh()->estoque_atual)->toBe(2);
});

it('recusa motivos reservados ao sistema e saída por fornecedor', function (string $tipo, string $motivo) {
    $produto = Product::factory()->comEstoque(10)->create();

    $this->actingAs($this->gerente)->post("/gerente/produtos/{$produto->id}/movimentos", [
        'tipo' => $tipo,
        'motivo' => $motivo,
        'quantidade' => 1,
    ])->assertSessionHasErrors('motivo');

    expect(StockMovement::count())->toBe(0);
})->with([
    ['entrada', 'venda'],
    ['entrada', 'estorno'],
    ['saida', 'fornecedor'],
]);

it('operador não movimenta estoque manualmente', function () {
    $produto = Product::factory()->comEstoque(2)->create();

    $this->actingAs($this->operador)->post("/gerente/produtos/{$produto->id}/movimentos", [
        'tipo' => 'entrada', 'motivo' => 'ajuste', 'quantidade' => 1,
    ])->assertForbidden();
});

it('gerente cadastra categoria', function () {
    $this->actingAs($this->gerente)->post('/gerente/categorias', ['nome' => 'Hortifruti'])
        ->assertRedirect('/gerente/categorias');

    expect(Category::where('nome', 'Hortifruti')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/ProductCrudTest.php`
Expected: FAIL (rotas inexistentes → 404).

- [ ] **Step 3: Policies**

`app/Policies/ProductPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGerente();
    }

    public function create(User $user): bool
    {
        return $user->isGerente();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isGerente();
    }
}
```

`app/Policies/CategoryPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGerente();
    }

    public function create(User $user): bool
    {
        return $user->isGerente();
    }
}
```

(Laravel descobre as policies automaticamente pelo nome `App\Policies\{Model}Policy`.)

- [ ] **Step 4: Controllers**

`app/Http/Controllers/Gerente/ProductController.php`:
```php
<?php

namespace App\Http\Controllers\Gerente;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);

        $busca = (string) $request->query('q', '');

        return Inertia::render('gerente/produtos/Index', [
            'products' => Product::with('category')
                ->when($busca !== '', fn ($q) => $q->busca($busca))
                ->orderBy('nome')
                ->paginate(20)
                ->withQueryString(),
            'busca' => $busca,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Product::class);

        return Inertia::render('gerente/produtos/Form', [
            'product' => null,
            'categories' => Category::orderBy('nome')->get(['id', 'nome']),
            'movimentos' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        Product::create($this->validated($request));

        return redirect('/gerente/produtos')->with('success', 'Produto cadastrado.');
    }

    public function edit(Product $product): Response
    {
        Gate::authorize('update', $product);

        return Inertia::render('gerente/produtos/Form', [
            'product' => $product,
            'categories' => Category::orderBy('nome')->get(['id', 'nome']),
            'movimentos' => $product->stockMovements()->with('user:id,name')->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $product->update($this->validated($request, $product));

        return redirect('/gerente/produtos')->with('success', 'Produto atualizado.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'nome' => ['required', 'string', 'max:255'],
            'codigo_barras' => ['required', 'digits_between:8,14', Rule::unique('products')->ignore($product)],
            'preco_centavos' => ['required', 'integer', 'min:1'],
            'estoque_minimo' => ['required', 'integer', 'min:0'],
        ]);
    }
}
```

`app/Http/Controllers/Gerente/CategoryController.php`:
```php
<?php

namespace App\Http\Controllers\Gerente;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Category::class);

        return Inertia::render('gerente/categorias/Index', [
            'categories' => Category::withCount('products')->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Category::class);

        Category::create($request->validate([
            'nome' => ['required', 'string', 'max:100', 'unique:categories,nome'],
        ]));

        return redirect('/gerente/categorias')->with('success', 'Categoria cadastrada.');
    }
}
```

`app/Http/Controllers/Gerente/StockEntryController.php`:
```php
<?php

namespace App\Http\Controllers\Gerente;

use App\Enums\MotivoMovimento;
use App\Exceptions\EstoqueInsuficienteException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StockEntryController extends Controller
{
    public function __invoke(Request $request, Product $product, StockService $stock): RedirectResponse
    {
        Gate::authorize('update', $product);

        $dados = $request->validate([
            'tipo' => ['required', 'in:entrada,saida'],
            // venda e estorno são gerados só pelo sistema; fornecedor só faz sentido como entrada.
            'motivo' => ['required', $request->input('tipo') === 'saida' ? 'in:ajuste' : 'in:ajuste,fornecedor'],
            'quantidade' => ['required', 'integer', 'min:1'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $motivo = MotivoMovimento::from($dados['motivo']);
        $quantidade = (int) $dados['quantidade'];

        try {
            $dados['tipo'] === 'entrada'
                ? $stock->entrada($product, $quantidade, $motivo, $request->user(), observacao: $dados['observacao'] ?? null)
                : $stock->saida($product, $quantidade, $motivo, $request->user(), observacao: $dados['observacao'] ?? null);
        } catch (EstoqueInsuficienteException $e) {
            throw ValidationException::withMessages(['quantidade' => $e->getMessage()]);
        }

        return back()->with('success', 'Movimento registrado.');
    }
}
```

- [ ] **Step 5: Rotas**

Em `routes/web.php`, adicionar os imports e este bloco no fim do arquivo (as próximas tasks acrescentam rotas dentro dele):
```php
use App\Http\Controllers\Gerente\CategoryController;
use App\Http\Controllers\Gerente\ProductController;
use App\Http\Controllers\Gerente\StockEntryController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('gerente')->name('gerente.')->group(function () {
        Route::resource('produtos', ProductController::class)
            ->except(['show', 'destroy'])
            ->parameters(['produtos' => 'product']);
        Route::post('produtos/{product}/movimentos', StockEntryController::class)->name('produtos.movimentos.store');
        Route::get('categorias', [CategoryController::class, 'index'])->name('categorias.index');
        Route::post('categorias', [CategoryController::class, 'store'])->name('categorias.store');
    });
});
```

- [ ] **Step 6: Helper de dinheiro no front**

`resources/js/lib/dinheiro.ts`:
```ts
const formatador = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

export function formatarCentavos(centavos: number): string {
    // Intl usa espaço não separável depois de "R$"; normalizamos para espaço comum.
    return formatador.format(centavos / 100).replace(/ /g, ' ');
}

export function paraCentavos(valor: string): number {
    const limpo = valor.trim().replace(/[R$\s]/g, '');
    if (limpo === '') return 0;
    // "1.234,56" → "1234.56"; "12.5" continua "12.5"
    const normalizado = limpo.includes(',') ? limpo.replace(/\./g, '').replace(',', '.') : limpo;
    const numero = Number(normalizado);
    return Number.isFinite(numero) ? Math.round(numero * 100) : 0;
}
```

- [ ] **Step 7: Páginas Vue**

`resources/js/pages/gerente/produtos/Index.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatarCentavos } from '@/lib/dinheiro';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Produto {
    id: number;
    nome: string;
    codigo_barras: string;
    preco_centavos: number;
    estoque_atual: number;
    estoque_minimo: number;
    category: { id: number; nome: string };
}

const props = defineProps<{
    products: { data: Produto[]; links: { url: string | null; label: string; active: boolean }[] };
    busca: string;
}>();

const termo = ref(props.busca);

function buscar() {
    router.get('/gerente/produtos', { q: termo.value }, { preserveState: true });
}
</script>

<template>
    <Head title="Produtos" />
    <AppLayout>
        <div class="space-y-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-semibold">Produtos</h1>
                <div class="flex gap-2">
                    <Link href="/gerente/categorias" class="rounded border px-3 py-2">Categorias</Link>
                    <Link href="/gerente/produtos/create" class="rounded bg-primary px-3 py-2 text-primary-foreground">Novo produto</Link>
                </div>
            </div>
            <form class="flex gap-2" @submit.prevent="buscar">
                <input v-model="termo" class="w-full rounded border px-3 py-2" placeholder="Buscar por nome ou código de barras" />
                <button class="rounded border px-3 py-2">Buscar</button>
            </form>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="p-2">Nome</th>
                            <th class="p-2">Código</th>
                            <th class="p-2">Categoria</th>
                            <th class="p-2 text-right">Preço</th>
                            <th class="p-2 text-right">Estoque</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in products.data" :key="p.id" class="border-b">
                            <td class="p-2">{{ p.nome }}</td>
                            <td class="p-2 font-mono">{{ p.codigo_barras }}</td>
                            <td class="p-2">{{ p.category.nome }}</td>
                            <td class="p-2 text-right">{{ formatarCentavos(p.preco_centavos) }}</td>
                            <td class="p-2 text-right" :class="{ 'font-semibold text-red-600': p.estoque_atual < p.estoque_minimo }">
                                {{ p.estoque_atual }}
                            </td>
                            <td class="p-2 text-right"><Link :href="`/gerente/produtos/${p.id}/edit`" class="underline">Editar</Link></td>
                        </tr>
                        <tr v-if="products.data.length === 0">
                            <td colspan="6" class="p-4 text-center text-muted-foreground">Nenhum produto encontrado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav class="flex flex-wrap gap-1">
                <Link
                    v-for="link in products.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    class="rounded border px-2 py-1 text-sm"
                    :class="{ 'bg-primary text-primary-foreground': link.active, 'pointer-events-none opacity-50': !link.url }"
                    v-html="link.label"
                />
            </nav>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/gerente/produtos/Form.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatarCentavos, paraCentavos } from '@/lib/dinheiro';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface Produto {
    id: number;
    category_id: number;
    nome: string;
    codigo_barras: string;
    preco_centavos: number;
    estoque_atual: number;
    estoque_minimo: number;
}
interface Movimento {
    id: number;
    tipo: string;
    motivo: string;
    quantidade: number;
    saldo_apos: number;
    observacao: string | null;
    created_at: string;
    user: { id: number; name: string };
}

const props = defineProps<{
    product: Produto | null;
    categories: { id: number; nome: string }[];
    movimentos: Movimento[];
}>();

const form = useForm({
    category_id: props.product?.category_id ?? props.categories[0]?.id ?? null,
    nome: props.product?.nome ?? '',
    codigo_barras: props.product?.codigo_barras ?? '',
    preco: props.product ? (props.product.preco_centavos / 100).toFixed(2).replace('.', ',') : '',
    estoque_minimo: props.product?.estoque_minimo ?? 0,
});

const movimento = useForm({ tipo: 'entrada', motivo: 'fornecedor', quantidade: 1, observacao: '' });

function salvar() {
    const dados = form.transform(({ preco, ...resto }) => ({ ...resto, preco_centavos: paraCentavos(preco) }));
    if (props.product) {
        dados.put(`/gerente/produtos/${props.product.id}`);
    } else {
        dados.post('/gerente/produtos');
    }
}

function registrarMovimento() {
    if (!props.product) return;
    movimento.post(`/gerente/produtos/${props.product.id}/movimentos`, {
        preserveScroll: true,
        onSuccess: () => movimento.reset('quantidade', 'observacao'),
    });
}
</script>

<template>
    <Head :title="product ? 'Editar produto' : 'Novo produto'" />
    <AppLayout>
        <div class="max-w-2xl space-y-6 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">{{ product ? 'Editar produto' : 'Novo produto' }}</h1>
                <Link href="/gerente/produtos" class="underline">Voltar</Link>
            </div>

            <form class="space-y-3" @submit.prevent="salvar">
                <label class="block">
                    <span>Nome</span>
                    <input v-model="form.nome" class="w-full rounded border px-3 py-2" />
                    <small class="text-red-600">{{ form.errors.nome }}</small>
                </label>
                <label class="block">
                    <span>Código de barras (EAN)</span>
                    <input v-model="form.codigo_barras" inputmode="numeric" class="w-full rounded border px-3 py-2 font-mono" />
                    <small class="text-red-600">{{ form.errors.codigo_barras }}</small>
                </label>
                <label class="block">
                    <span>Categoria</span>
                    <select v-model="form.category_id" class="w-full rounded border px-3 py-2">
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.nome }}</option>
                    </select>
                    <small class="text-red-600">{{ form.errors.category_id }}</small>
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span>Preço (R$)</span>
                        <input v-model="form.preco" inputmode="decimal" placeholder="0,00" class="w-full rounded border px-3 py-2" />
                        <small class="text-red-600">{{ (form.errors as Record<string, string>).preco_centavos }}</small>
                    </label>
                    <label class="block">
                        <span>Estoque mínimo</span>
                        <input v-model.number="form.estoque_minimo" type="number" min="0" class="w-full rounded border px-3 py-2" />
                        <small class="text-red-600">{{ form.errors.estoque_minimo }}</small>
                    </label>
                </div>
                <button :disabled="form.processing" class="rounded bg-primary px-4 py-2 text-primary-foreground">Salvar</button>
            </form>

            <section v-if="product" class="space-y-3 border-t pt-4">
                <h2 class="font-semibold">Estoque atual: {{ product.estoque_atual }}</h2>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="registrarMovimento">
                    <select v-model="movimento.tipo" class="rounded border px-3 py-2" @change="movimento.motivo = movimento.tipo === 'saida' ? 'ajuste' : 'fornecedor'">
                        <option value="entrada">Entrada</option>
                        <option value="saida">Saída</option>
                    </select>
                    <select v-model="movimento.motivo" class="rounded border px-3 py-2">
                        <option v-if="movimento.tipo === 'entrada'" value="fornecedor">Fornecedor</option>
                        <option value="ajuste">Ajuste</option>
                    </select>
                    <input v-model.number="movimento.quantidade" type="number" min="1" class="w-24 rounded border px-3 py-2" />
                    <input v-model="movimento.observacao" placeholder="Observação" class="rounded border px-3 py-2" />
                    <button :disabled="movimento.processing" class="rounded border px-3 py-2">Registrar</button>
                </form>
                <small class="text-red-600">{{ movimento.errors.quantidade || movimento.errors.motivo }}</small>

                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="p-2">Data</th><th class="p-2">Tipo</th><th class="p-2">Motivo</th>
                            <th class="p-2 text-right">Qtd</th><th class="p-2 text-right">Saldo</th><th class="p-2">Usuário</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in movimentos" :key="m.id" class="border-b">
                            <td class="p-2">{{ new Date(m.created_at).toLocaleString('pt-BR') }}</td>
                            <td class="p-2">{{ m.tipo }}</td>
                            <td class="p-2">{{ m.motivo }}</td>
                            <td class="p-2 text-right">{{ m.quantidade }}</td>
                            <td class="p-2 text-right">{{ m.saldo_apos }}</td>
                            <td class="p-2">{{ m.user.name }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-sm text-muted-foreground">Preço atual: {{ formatarCentavos(product.preco_centavos) }}</p>
            </section>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/gerente/categorias/Index.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ categories: { id: number; nome: string; products_count: number }[] }>();

const form = useForm({ nome: '' });

function salvar() {
    form.post('/gerente/categorias', { onSuccess: () => form.reset() });
}
</script>

<template>
    <Head title="Categorias" />
    <AppLayout>
        <div class="max-w-xl space-y-4 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Categorias</h1>
                <Link href="/gerente/produtos" class="underline">Produtos</Link>
            </div>
            <form class="flex gap-2" @submit.prevent="salvar">
                <input v-model="form.nome" placeholder="Nova categoria" class="w-full rounded border px-3 py-2" />
                <button :disabled="form.processing" class="rounded bg-primary px-3 py-2 text-primary-foreground">Adicionar</button>
            </form>
            <small class="text-red-600">{{ form.errors.nome }}</small>
            <ul class="divide-y rounded border">
                <li v-for="c in categories" :key="c.id" class="flex justify-between p-2">
                    <span>{{ c.nome }}</span>
                    <span class="text-sm text-muted-foreground">{{ c.products_count }} produto(s)</span>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 8: Rodar e ver passar**

Run: `./vendor/bin/sail pest tests/Feature/ProductCrudTest.php && ./vendor/bin/sail npm run build`
Expected: `12 passed` e build do Vite sem erros de TypeScript.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: CRUD de produtos e categorias e movimentação manual de estoque"
```

---

### Task 6: Sessões de caixa, tabelas de venda e CashRegisterService

**Files:**
- Create: `app/Enums/FormaPagamento.php`, `app/Enums/SaleStatus.php`
- Create: `app/Exceptions/CaixaJaAbertoException.php`, `app/Exceptions/CaixaNaoAbertoException.php`
- Create: `database/migrations/2026_10_15_000005_create_cash_register_sessions_table.php`
- Create: `database/migrations/2026_10_15_000006_create_sales_tables.php`
- Create: `app/Models/CashRegisterSession.php`, `app/Models/Sale.php`, `app/Models/SaleItem.php`
- Create: `database/factories/CashRegisterSessionFactory.php`, `database/factories/SaleFactory.php`
- Create: `app/Services/CashRegisterService.php`
- Create: `app/Policies/CashRegisterSessionPolicy.php`
- Test: `tests/Feature/CashRegisterServiceTest.php`

**Interfaces:**
- Consumes: `User`, `UserFactory::gerente()` (Task 2); tabela `stock_movements` (Task 4).
- Produces:
  - `FormaPagamento`: `Dinheiro = 'dinheiro'`, `Cartao = 'cartao'`, `Pix = 'pix'`, com `label(): string`.
  - `SaleStatus`: `Concluida = 'concluida'`, `Cancelada = 'cancelada'`.
  - `CashRegisterSession` (`user_id`, `valor_inicial_centavos`, `valor_esperado_centavos?`, `valor_informado_centavos?`, `aberta_em`, `fechada_em?`), métodos `user()`, `sales()`, `estaAberta(): bool`, `diferencaCentavos(): ?int` (informado − esperado; positivo = sobra), scope `aberta()`.
  - `Sale` (`cash_register_session_id`, `user_id`, `subtotal_centavos`, `desconto_percentual`, `desconto_centavos`, `total_centavos`, `forma_pagamento`, `valor_recebido_centavos?`, `troco_centavos`, `status`, `cancelada_em?`, `cancelada_por?`), métodos `items()`, `cashRegisterSession()`, `user()`.
  - `SaleItem` (`sale_id`, `product_id`, `quantidade`, `preco_unitario_centavos`, `subtotal_centavos`), métodos `sale()`, `product()`.
  - Factories: `CashRegisterSession::factory()` (aberta) com state `fechada()`; `Sale::factory()` com states `dinheiro(int $totalCentavos)`, `cartao(int $totalCentavos)`, `cancelada()`.
  - `CashRegisterService::abrir(User $user, int $valorInicialCentavos): CashRegisterSession` — lança `CaixaJaAbertoException` ou `InvalidArgumentException` (valor negativo).
  - `CashRegisterService::sessaoAberta(User $user): ?CashRegisterSession`
  - `CashRegisterService::valorEsperado(CashRegisterSession $session): int`
  - `CashRegisterService::fechar(User $user, int $valorInformadoCentavos): CashRegisterSession` — lança `CaixaNaoAbertoException` ou `InvalidArgumentException`.
  - `CashRegisterSessionPolicy::view(User, CashRegisterSession): bool` (gerente ou dono).

- [ ] **Step 1: Escrever os testes**

`tests/Feature/CashRegisterServiceTest.php`:
```php
<?php

use App\Exceptions\CaixaJaAbertoException;
use App\Exceptions\CaixaNaoAbertoException;
use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashRegisterService;

beforeEach(function () {
    $this->service = app(CashRegisterService::class);
    $this->operador = User::factory()->create();
});

it('abre caixa com valor inicial', function () {
    $sessao = $this->service->abrir($this->operador, 10000);

    expect($sessao->estaAberta())->toBeTrue()
        ->and($sessao->valor_inicial_centavos)->toBe(10000)
        ->and($this->service->sessaoAberta($this->operador)?->id)->toBe($sessao->id);
});

it('não abre duas sessões para o mesmo operador', function () {
    $this->service->abrir($this->operador, 10000);
    $this->service->abrir($this->operador, 5000);
})->throws(CaixaJaAbertoException::class);

it('permite sessões simultâneas de operadores diferentes', function () {
    $this->service->abrir($this->operador, 10000);
    $this->service->abrir(User::factory()->create(), 10000);

    expect(CashRegisterSession::aberta()->count())->toBe(2);
});

it('recusa valor inicial negativo', function () {
    $this->service->abrir($this->operador, -1);
})->throws(InvalidArgumentException::class);

it('fecha caixa somando só vendas concluídas em dinheiro', function () {
    $sessao = $this->service->abrir($this->operador, 10000);
    $base = ['cash_register_session_id' => $sessao->id, 'user_id' => $this->operador->id];

    Sale::factory()->dinheiro(2500)->create($base);
    Sale::factory()->dinheiro(1500)->create($base);
    Sale::factory()->cartao(9999)->create($base);
    Sale::factory()->dinheiro(7000)->cancelada()->create($base);

    expect($this->service->valorEsperado($sessao))->toBe(14000);

    $fechada = $this->service->fechar($this->operador, 13900);

    expect($fechada->estaAberta())->toBeFalse()
        ->and($fechada->valor_esperado_centavos)->toBe(14000)
        ->and($fechada->valor_informado_centavos)->toBe(13900)
        ->and($fechada->diferencaCentavos())->toBe(-100);
});

it('dá erro ao fechar sem sessão aberta', function () {
    $this->service->fechar($this->operador, 0);
})->throws(CaixaNaoAbertoException::class);

it('permite abrir de novo depois de fechar', function () {
    $this->service->abrir($this->operador, 10000);
    $this->service->fechar($this->operador, 10000);

    expect($this->service->abrir($this->operador, 2000)->estaAberta())->toBeTrue();
});

it('operador só vê a própria sessão; gerente vê todas', function () {
    $minha = CashRegisterSession::factory()->for($this->operador)->create();
    $deOutro = CashRegisterSession::factory()->create();
    $gerente = User::factory()->gerente()->create();

    expect($this->operador->can('view', $minha))->toBeTrue()
        ->and($this->operador->can('view', $deOutro))->toBeFalse()
        ->and($gerente->can('view', $deOutro))->toBeTrue();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/CashRegisterServiceTest.php`
Expected: FAIL com `Class "App\Services\CashRegisterService" not found`.

- [ ] **Step 3: Enums e exceções**

`app/Enums/FormaPagamento.php`:
```php
<?php

namespace App\Enums;

enum FormaPagamento: string
{
    case Dinheiro = 'dinheiro';
    case Cartao = 'cartao';
    case Pix = 'pix';

    public function label(): string
    {
        return match ($this) {
            self::Dinheiro => 'Dinheiro',
            self::Cartao => 'Cartão',
            self::Pix => 'Pix',
        };
    }
}
```

`app/Enums/SaleStatus.php`:
```php
<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Concluida = 'concluida';
    case Cancelada = 'cancelada';
}
```

`app/Exceptions/CaixaJaAbertoException.php`:
```php
<?php

namespace App\Exceptions;

use RuntimeException;

class CaixaJaAbertoException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Você já tem um caixa aberto. Feche-o antes de abrir outro.');
    }
}
```

`app/Exceptions/CaixaNaoAbertoException.php`:
```php
<?php

namespace App\Exceptions;

use RuntimeException;

class CaixaNaoAbertoException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Nenhum caixa aberto. Abra o caixa antes de continuar.');
    }
}
```

- [ ] **Step 4: Migrations**

`database/migrations/2026_10_15_000005_create_cash_register_sessions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('valor_inicial_centavos');
            $table->bigInteger('valor_esperado_centavos')->nullable();
            $table->unsignedBigInteger('valor_informado_centavos')->nullable();
            $table->timestamp('aberta_em');
            $table->timestamp('fechada_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fechada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_sessions');
    }
};
```

`database/migrations/2026_10_15_000006_create_sales_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('subtotal_centavos');
            $table->decimal('desconto_percentual', 5, 2)->default(0);
            $table->unsignedBigInteger('desconto_centavos')->default(0);
            $table->unsignedBigInteger('total_centavos');
            $table->string('forma_pagamento', 10);
            $table->unsignedBigInteger('valor_recebido_centavos')->nullable();
            $table->unsignedBigInteger('troco_centavos')->default(0);
            $table->string('status', 10)->default('concluida');
            $table->timestamp('cancelada_em')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('preco_unitario_centavos');
            $table->unsignedBigInteger('subtotal_centavos');
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['sale_id']);
        });
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
```

- [ ] **Step 5: Models**

`app/Models/CashRegisterSession.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegisterSession extends Model
{
    /** @use HasFactory<\Database\Factories\CashRegisterSessionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'valor_inicial_centavos', 'valor_esperado_centavos', 'valor_informado_centavos', 'aberta_em', 'fechada_em'];

    protected function casts(): array
    {
        return [
            'valor_inicial_centavos' => 'integer',
            'valor_esperado_centavos' => 'integer',
            'valor_informado_centavos' => 'integer',
            'aberta_em' => 'datetime',
            'fechada_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @param Builder<CashRegisterSession> $query */
    public function scopeAberta(Builder $query): void
    {
        $query->whereNull('fechada_em');
    }

    public function estaAberta(): bool
    {
        return $this->fechada_em === null;
    }

    public function diferencaCentavos(): ?int
    {
        if ($this->valor_informado_centavos === null || $this->valor_esperado_centavos === null) {
            return null;
        }

        return $this->valor_informado_centavos - $this->valor_esperado_centavos;
    }
}
```

`app/Models/Sale.php`:
```php
<?php

namespace App\Models;

use App\Enums\FormaPagamento;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    /** @use HasFactory<\Database\Factories\SaleFactory> */
    use HasFactory;

    protected $fillable = [
        'cash_register_session_id', 'user_id', 'subtotal_centavos', 'desconto_percentual', 'desconto_centavos',
        'total_centavos', 'forma_pagamento', 'valor_recebido_centavos', 'troco_centavos', 'status',
        'cancelada_em', 'cancelada_por',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_centavos' => 'integer',
            'desconto_percentual' => 'decimal:2',
            'desconto_centavos' => 'integer',
            'total_centavos' => 'integer',
            'forma_pagamento' => FormaPagamento::class,
            'valor_recebido_centavos' => 'integer',
            'troco_centavos' => 'integer',
            'status' => SaleStatus::class,
            'cancelada_em' => 'datetime',
        ];
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** @return BelongsTo<CashRegisterSession, $this> */
    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/SaleItem.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = ['sale_id', 'product_id', 'quantidade', 'preco_unitario_centavos', 'subtotal_centavos'];

    protected function casts(): array
    {
        return [
            'quantidade' => 'integer',
            'preco_unitario_centavos' => 'integer',
            'subtotal_centavos' => 'integer',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

- [ ] **Step 6: Factories**

`database/factories/CashRegisterSessionFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\CashRegisterSession> */
class CashRegisterSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'valor_inicial_centavos' => 10000,
            'aberta_em' => now(),
        ];
    }

    public function fechada(): static
    {
        return $this->state(fn () => [
            'valor_esperado_centavos' => 10000,
            'valor_informado_centavos' => 10000,
            'fechada_em' => now(),
        ]);
    }
}
```

`database/factories/SaleFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\FormaPagamento;
use App\Enums\SaleStatus;
use App\Models\CashRegisterSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Sale> */
class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cash_register_session_id' => CashRegisterSession::factory(),
            'user_id' => User::factory(),
            'subtotal_centavos' => 1000,
            'desconto_percentual' => 0,
            'desconto_centavos' => 0,
            'total_centavos' => 1000,
            'forma_pagamento' => FormaPagamento::Cartao,
            'valor_recebido_centavos' => null,
            'troco_centavos' => 0,
            'status' => SaleStatus::Concluida,
        ];
    }

    public function dinheiro(int $totalCentavos): static
    {
        return $this->state(fn () => [
            'forma_pagamento' => FormaPagamento::Dinheiro,
            'subtotal_centavos' => $totalCentavos,
            'total_centavos' => $totalCentavos,
            'valor_recebido_centavos' => $totalCentavos,
        ]);
    }

    public function cartao(int $totalCentavos): static
    {
        return $this->state(fn () => [
            'forma_pagamento' => FormaPagamento::Cartao,
            'subtotal_centavos' => $totalCentavos,
            'total_centavos' => $totalCentavos,
        ]);
    }

    public function cancelada(): static
    {
        return $this->state(fn () => ['status' => SaleStatus::Cancelada, 'cancelada_em' => now()]);
    }
}
```

- [ ] **Step 7: Service e policy**

`app/Services/CashRegisterService.php`:
```php
<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\SaleStatus;
use App\Exceptions\CaixaJaAbertoException;
use App\Exceptions\CaixaNaoAbertoException;
use App\Models\CashRegisterSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashRegisterService
{
    public function abrir(User $user, int $valorInicialCentavos): CashRegisterSession
    {
        if ($valorInicialCentavos < 0) {
            throw new InvalidArgumentException('O valor inicial não pode ser negativo.');
        }

        return DB::transaction(function () use ($user, $valorInicialCentavos) {
            // Trava a linha do usuário para impedir duas aberturas simultâneas.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($this->sessaoAberta($user) !== null) {
                throw new CaixaJaAbertoException;
            }

            return CashRegisterSession::create([
                'user_id' => $user->id,
                'valor_inicial_centavos' => $valorInicialCentavos,
                'aberta_em' => now(),
            ]);
        });
    }

    public function sessaoAberta(User $user): ?CashRegisterSession
    {
        return CashRegisterSession::query()->where('user_id', $user->id)->aberta()->latest('id')->first();
    }

    public function valorEsperado(CashRegisterSession $session): int
    {
        $vendasEmDinheiro = (int) $session->sales()
            ->where('status', SaleStatus::Concluida)
            ->where('forma_pagamento', FormaPagamento::Dinheiro)
            ->sum('total_centavos');

        return $session->valor_inicial_centavos + $vendasEmDinheiro;
    }

    public function fechar(User $user, int $valorInformadoCentavos): CashRegisterSession
    {
        if ($valorInformadoCentavos < 0) {
            throw new InvalidArgumentException('O valor contado não pode ser negativo.');
        }

        return DB::transaction(function () use ($user, $valorInformadoCentavos) {
            $session = CashRegisterSession::query()
                ->where('user_id', $user->id)
                ->aberta()
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                throw new CaixaNaoAbertoException;
            }

            $session->update([
                'valor_esperado_centavos' => $this->valorEsperado($session),
                'valor_informado_centavos' => $valorInformadoCentavos,
                'fechada_em' => now(),
            ]);

            return $session;
        });
    }
}
```

`app/Policies/CashRegisterSessionPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\CashRegisterSession;
use App\Models\User;

class CashRegisterSessionPolicy
{
    public function view(User $user, CashRegisterSession $session): bool
    {
        return $user->isGerente() || $session->user_id === $user->id;
    }
}
```

- [ ] **Step 8: Rodar e ver passar**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail pest tests/Feature/CashRegisterServiceTest.php`
Expected: `8 passed`.

- [ ] **Step 9: Rodar a suíte inteira (garante que a FK nova em stock_movements não quebrou a Task 4)**

Run: `./vendor/bin/sail pest`
Expected: tudo verde.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: sessões de caixa com abertura, fechamento e conferência"
```

---

### Task 7: SaleService (finalizar venda e cancelar com estorno)

**Files:**
- Create: `app/Exceptions/VendaInvalidaException.php`, `app/Exceptions/VendaJaCanceladaException.php`
- Create: `app/Services/SaleService.php`
- Test: `tests/Feature/SaleServiceTest.php`

**Interfaces:**
- Consumes: `StockService::saida/entrada`, `MotivoMovimento`, `EstoqueInsuficienteException` (Task 4); `CashRegisterService::sessaoAberta/abrir`, `CaixaNaoAbertoException`, `Sale`, `SaleItem`, `FormaPagamento`, `SaleStatus` (Task 6); `Product`, `ProductFactory::comEstoque()` (Task 3).
- Produces:
  - `SaleService::finalizar(User $user, array $itens, float $descontoPercentual, FormaPagamento $forma, ?int $valorRecebidoCentavos = null): Sale` — `$itens` é `list<array{product_id: int, quantidade: int}>`; usa a sessão aberta do usuário; tudo numa transação; retorna a venda com `items` carregados. Linhas repetidas do mesmo produto viram um único `SaleItem` com a soma.
  - `SaleService::cancelar(Sale $sale, User $user): Sale`
  - `VendaInvalidaException` (propriedade pública `campo`: `'itens'`, `'desconto_percentual'` ou `'valor_recebido_centavos'`).
  - `VendaJaCanceladaException`.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/SaleServiceTest.php`:
```php
<?php

use App\Enums\FormaPagamento;
use App\Enums\MotivoMovimento;
use App\Enums\SaleStatus;
use App\Exceptions\CaixaNaoAbertoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\VendaInvalidaException;
use App\Exceptions\VendaJaCanceladaException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\SaleService;

beforeEach(function () {
    $this->service = app(SaleService::class);
    $this->operador = User::factory()->create();
    app(CashRegisterService::class)->abrir($this->operador, 10000);
});

it('finaliza venda, baixa estoque, guarda snapshot do preço e gera movimentos', function () {
    $arroz = Product::factory()->comEstoque(10)->create(['preco_centavos' => 2590]);
    $feijao = Product::factory()->comEstoque(5)->create(['preco_centavos' => 899]);

    $venda = $this->service->finalizar($this->operador, [
        ['product_id' => $arroz->id, 'quantidade' => 2],
        ['product_id' => $feijao->id, 'quantidade' => 1],
    ], 0, FormaPagamento::Cartao);

    expect($venda->status)->toBe(SaleStatus::Concluida)
        ->and($venda->subtotal_centavos)->toBe(6079)
        ->and($venda->total_centavos)->toBe(6079)
        ->and($venda->items)->toHaveCount(2)
        ->and($arroz->fresh()->estoque_atual)->toBe(8)
        ->and($feijao->fresh()->estoque_atual)->toBe(4)
        ->and(StockMovement::where('sale_id', $venda->id)->where('motivo', MotivoMovimento::Venda)->count())->toBe(2);

    $arroz->update(['preco_centavos' => 9999]);
    expect($venda->items()->where('product_id', $arroz->id)->value('preco_unitario_centavos'))->toBe(2590);
});

it('soma linhas repetidas do mesmo produto num único item', function () {
    $arroz = Product::factory()->comEstoque(10)->create(['preco_centavos' => 1000]);

    $venda = $this->service->finalizar($this->operador, [
        ['product_id' => $arroz->id, 'quantidade' => 3],
        ['product_id' => $arroz->id, 'quantidade' => 2],
    ], 0, FormaPagamento::Pix);

    expect($venda->items)->toHaveCount(1)
        ->and($venda->items->first()->quantidade)->toBe(5)
        ->and($arroz->fresh()->estoque_atual)->toBe(5);
});

it('recusa linhas repetidas cuja soma passa do estoque, sem gravar nada', function () {
    $arroz = Product::factory()->comEstoque(5)->create();

    try {
        $this->service->finalizar($this->operador, [
            ['product_id' => $arroz->id, 'quantidade' => 3],
            ['product_id' => $arroz->id, 'quantidade' => 3],
        ], 0, FormaPagamento::Cartao);
        $this->fail('Deveria lançar EstoqueInsuficienteException');
    } catch (EstoqueInsuficienteException) {
    }

    expect($arroz->fresh()->estoque_atual)->toBe(5)
        ->and(Sale::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('se um item não tem estoque, nenhum produto é alterado', function () {
    $temEstoque = Product::factory()->comEstoque(10)->create();
    $semEstoque = Product::factory()->comEstoque(1)->create();

    expect(fn () => $this->service->finalizar($this->operador, [
        ['product_id' => $temEstoque->id, 'quantidade' => 2],
        ['product_id' => $semEstoque->id, 'quantidade' => 2],
    ], 0, FormaPagamento::Cartao))->toThrow(EstoqueInsuficienteException::class);

    expect($temEstoque->fresh()->estoque_atual)->toBe(10)
        ->and(Sale::count())->toBe(0);
});

it('arredonda o desconto em centavos (R$ 9,99 com 10% = R$ 8,99)', function () {
    $produto = Product::factory()->comEstoque(1)->create(['preco_centavos' => 999]);

    $venda = $this->service->finalizar($this->operador, [['product_id' => $produto->id, 'quantidade' => 1]], 10, FormaPagamento::Cartao);

    expect($venda->desconto_centavos)->toBe(100)
        ->and($venda->total_centavos)->toBe(899);
});

it('calcula troco em dinheiro', function () {
    $produto = Product::factory()->comEstoque(1)->create(['preco_centavos' => 2590]);

    $venda = $this->service->finalizar($this->operador, [['product_id' => $produto->id, 'quantidade' => 1]], 0, FormaPagamento::Dinheiro, 3000);

    expect($venda->valor_recebido_centavos)->toBe(3000)
        ->and($venda->troco_centavos)->toBe(410);
});

it('recusa dinheiro recebido menor que o total', function () {
    $produto = Product::factory()->comEstoque(1)->create(['preco_centavos' => 2590]);

    try {
        $this->service->finalizar($this->operador, [['product_id' => $produto->id, 'quantidade' => 1]], 0, FormaPagamento::Dinheiro, 2000);
        $this->fail('Deveria lançar VendaInvalidaException');
    } catch (VendaInvalidaException $e) {
        expect($e->campo)->toBe('valor_recebido_centavos');
    }

    expect($produto->fresh()->estoque_atual)->toBe(1);
});

it('ignora valor recebido em pix e cartão', function () {
    $produto = Product::factory()->comEstoque(1)->create(['preco_centavos' => 1000]);

    $venda = $this->service->finalizar($this->operador, [['product_id' => $produto->id, 'quantidade' => 1]], 0, FormaPagamento::Pix, 5000);

    expect($venda->valor_recebido_centavos)->toBeNull()
        ->and($venda->troco_centavos)->toBe(0);
});

it('recusa entradas inválidas', function (array $itens, float $desconto, string $campo) {
    $produto = Product::factory()->comEstoque(10)->create();
    $itens = array_map(fn ($i) => ['product_id' => $i['product_id'] ?? $produto->id, 'quantidade' => $i['quantidade']], $itens);

    try {
        $this->service->finalizar($this->operador, $itens, $desconto, FormaPagamento::Cartao);
        $this->fail('Deveria lançar VendaInvalidaException');
    } catch (VendaInvalidaException $e) {
        expect($e->campo)->toBe($campo);
    }

    expect(Sale::count())->toBe(0);
})->with([
    'sem itens' => [[], 0, 'itens'],
    'quantidade zero' => [[['quantidade' => 0]], 0, 'itens'],
    'quantidade negativa' => [[['quantidade' => -2]], 0, 'itens'],
    'produto inexistente' => [[['product_id' => 999999, 'quantidade' => 1]], 0, 'itens'],
    'desconto negativo' => [[['quantidade' => 1]], -1, 'desconto_percentual'],
    'desconto acima de 100' => [[['quantidade' => 1]], 100.01, 'desconto_percentual'],
]);

it('não vende sem caixa aberto', function () {
    $produto = Product::factory()->comEstoque(1)->create();

    $this->service->finalizar(User::factory()->create(), [['product_id' => $produto->id, 'quantidade' => 1]], 0, FormaPagamento::Cartao);
})->throws(CaixaNaoAbertoException::class);

it('cancela venda devolvendo o estoque com movimento de estorno', function () {
    $produto = Product::factory()->comEstoque(10)->create();
    $venda = $this->service->finalizar($this->operador, [['product_id' => $produto->id, 'quantidade' => 4]], 0, FormaPagamento::Cartao);

    $cancelada = $this->service->cancelar($venda, $this->operador);

    expect($cancelada->status)->toBe(SaleStatus::Cancelada)
        ->and($cancelada->cancelada_por)->toBe($this->operador->id)
        ->and($cancelada->cancelada_em)->not->toBeNull()
        ->and($produto->fresh()->estoque_atual)->toBe(10)
        ->and(StockMovement::where('sale_id', $venda->id)->where('motivo', MotivoMovimento::Estorno)->count())->toBe(1)
        ->and(StockMovement::where('sale_id', $venda->id)->count())->toBe(2);
});

it('não cancela duas vezes nem estorna em dobro', function () {
    $produto = Product::factory()->comEstoque(10)->create();
    $venda = $this->service->finalizar($this->operador, [['product_id' => $produto->id, 'quantidade' => 4]], 0, FormaPagamento::Cartao);
    $this->service->cancelar($venda, $this->operador);

    expect(fn () => $this->service->cancelar($venda->fresh(), $this->operador))->toThrow(VendaJaCanceladaException::class);
    expect($produto->fresh()->estoque_atual)->toBe(10);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/SaleServiceTest.php`
Expected: FAIL com `Class "App\Services\SaleService" not found`.

- [ ] **Step 3: Exceções**

`app/Exceptions/VendaInvalidaException.php`:
```php
<?php

namespace App\Exceptions;

use RuntimeException;

class VendaInvalidaException extends RuntimeException
{
    public function __construct(public readonly string $campo, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}
```

`app/Exceptions/VendaJaCanceladaException.php`:
```php
<?php

namespace App\Exceptions;

use RuntimeException;

class VendaJaCanceladaException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta venda já foi cancelada.');
    }
}
```

- [ ] **Step 4: Service**

`app/Services/SaleService.php`:
```php
<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\MotivoMovimento;
use App\Enums\SaleStatus;
use App\Exceptions\CaixaNaoAbertoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\VendaInvalidaException;
use App\Exceptions\VendaJaCanceladaException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CashRegisterService $caixa,
    ) {}

    /**
     * @param  list<array{product_id: int, quantidade: int}>  $itens
     */
    public function finalizar(User $user, array $itens, float $descontoPercentual, FormaPagamento $forma, ?int $valorRecebidoCentavos = null): Sale
    {
        $quantidades = $this->agruparItens($itens);

        if ($descontoPercentual < 0 || $descontoPercentual > 100) {
            throw new VendaInvalidaException('desconto_percentual', 'O desconto deve estar entre 0% e 100%.');
        }

        $sessao = $this->caixa->sessaoAberta($user) ?? throw new CaixaNaoAbertoException;

        return DB::transaction(function () use ($user, $quantidades, $descontoPercentual, $forma, $valorRecebidoCentavos, $sessao) {
            // Ordena por id para todas as vendas travarem as linhas na mesma ordem (evita deadlock).
            $produtos = Product::query()
                ->whereIn('id', array_keys($quantidades))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($produtos->count() !== count($quantidades)) {
                throw new VendaInvalidaException('itens', 'Um ou mais produtos não foram encontrados.');
            }

            // Valida tudo antes de gravar qualquer coisa.
            foreach ($quantidades as $productId => $quantidade) {
                $produto = $produtos[$productId];
                if ($produto->estoque_atual < $quantidade) {
                    throw new EstoqueInsuficienteException($produto, $quantidade);
                }
            }

            $subtotal = 0;
            foreach ($quantidades as $productId => $quantidade) {
                $subtotal += $produtos[$productId]->preco_centavos * $quantidade;
            }

            $desconto = $this->calcularDesconto($subtotal, $descontoPercentual);
            $total = $subtotal - $desconto;

            $recebido = null;
            $troco = 0;
            if ($forma === FormaPagamento::Dinheiro) {
                if ($valorRecebidoCentavos === null || $valorRecebidoCentavos < $total) {
                    throw new VendaInvalidaException('valor_recebido_centavos', 'O valor recebido é menor que o total da venda.');
                }
                $recebido = $valorRecebidoCentavos;
                $troco = $recebido - $total;
            }

            $venda = Sale::create([
                'cash_register_session_id' => $sessao->id,
                'user_id' => $user->id,
                'subtotal_centavos' => $subtotal,
                'desconto_percentual' => $descontoPercentual,
                'desconto_centavos' => $desconto,
                'total_centavos' => $total,
                'forma_pagamento' => $forma,
                'valor_recebido_centavos' => $recebido,
                'troco_centavos' => $troco,
                'status' => SaleStatus::Concluida,
            ]);

            foreach ($quantidades as $productId => $quantidade) {
                $produto = $produtos[$productId];
                $venda->items()->create([
                    'product_id' => $produto->id,
                    'quantidade' => $quantidade,
                    'preco_unitario_centavos' => $produto->preco_centavos,
                    'subtotal_centavos' => $produto->preco_centavos * $quantidade,
                ]);
                $this->stock->saida($produto, $quantidade, MotivoMovimento::Venda, $user, $venda->id);
            }

            return $venda->load('items');
        });
    }

    public function cancelar(Sale $sale, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $user) {
            $venda = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($venda->status === SaleStatus::Cancelada) {
                throw new VendaJaCanceladaException;
            }

            foreach ($venda->items()->with('product')->get() as $item) {
                $this->stock->entrada($item->product, $item->quantidade, MotivoMovimento::Estorno, $user, $venda->id);
            }

            $venda->update([
                'status' => SaleStatus::Cancelada,
                'cancelada_em' => now(),
                'cancelada_por' => $user->id,
            ]);

            return $venda;
        });
    }

    /**
     * @param  list<array{product_id: int, quantidade: int}>  $itens
     * @return array<int, int> product_id => quantidade total
     */
    private function agruparItens(array $itens): array
    {
        if ($itens === []) {
            throw new VendaInvalidaException('itens', 'A venda precisa de pelo menos um item.');
        }

        $quantidades = [];
        foreach ($itens as $item) {
            $quantidade = (int) $item['quantidade'];
            if ($quantidade <= 0) {
                throw new VendaInvalidaException('itens', 'A quantidade de cada item deve ser maior que zero.');
            }
            $id = (int) $item['product_id'];
            $quantidades[$id] = ($quantidades[$id] ?? 0) + $quantidade;
        }

        return $quantidades;
    }

    private function calcularDesconto(int $subtotalCentavos, float $percentual): int
    {
        // Trabalha em pontos-base inteiros (10% = 1000) para evitar erro de ponto flutuante; arredonda meio para cima.
        $pontosBase = (int) round($percentual * 100);

        return intdiv($subtotalCentavos * $pontosBase + 5000, 10000);
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `./vendor/bin/sail pest tests/Feature/SaleServiceTest.php`
Expected: `17 passed` (11 testes + 6 casos do dataset).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: SaleService com venda atômica, troco, desconto e cancelamento com estorno"
```

---

### Task 8: Telas e rotas de caixa (abrir, fechar, resumo)

**Files:**
- Create: `app/Http/Controllers/CashRegisterController.php`
- Create: `resources/js/pages/caixa/Abrir.vue`, `resources/js/pages/caixa/Fechar.vue`, `resources/js/pages/caixa/Resumo.vue`
- Modify: `routes/web.php` (bloco autenticado criado na Task 5)
- Test: `tests/Feature/CashRegisterHttpTest.php`

**Interfaces:**
- Consumes: `CashRegisterService` (`abrir`, `sessaoAberta`, `valorEsperado`, `fechar`), `CaixaJaAbertoException`, `CaixaNaoAbertoException`, `CashRegisterSessionPolicy::view`, `FormaPagamento`, `SaleStatus` (Task 6); `formatarCentavos`, `paraCentavos` (Task 5).
- Produces: rotas `GET /caixa` (`caixa.show`), `POST /caixa/abrir` (`caixa.abrir`), `GET /caixa/fechar` (`caixa.fechar.form`), `POST /caixa/fechar` (`caixa.fechar`), `GET /caixa/sessoes/{session}` (`caixa.sessoes.show`). Com caixa aberto, `GET /caixa` redireciona para `/pdv` (criado na Task 9). Erros de regra voltam como erro de sessão na chave `caixa`.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/CashRegisterHttpTest.php`:
```php
<?php

use App\Models\CashRegisterSession;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashRegisterService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->operador = User::factory()->create();
});

it('sem caixa aberto mostra a tela de abertura', function () {
    $this->actingAs($this->operador)->get('/caixa')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('caixa/Abrir'));
});

it('com caixa aberto, /caixa leva ao PDV', function () {
    app(CashRegisterService::class)->abrir($this->operador, 0);

    $this->actingAs($this->operador)->get('/caixa')->assertRedirect('/pdv');
});

it('abre o caixa e vai para o PDV', function () {
    $this->actingAs($this->operador)->post('/caixa/abrir', ['valor_inicial_centavos' => 15000])
        ->assertRedirect('/pdv');

    expect(CashRegisterSession::where('user_id', $this->operador->id)->aberta()->value('valor_inicial_centavos'))->toBe(15000);
});

it('não abre segundo caixa', function () {
    app(CashRegisterService::class)->abrir($this->operador, 0);

    $this->actingAs($this->operador)->post('/caixa/abrir', ['valor_inicial_centavos' => 100])
        ->assertSessionHasErrors('caixa');

    expect(CashRegisterSession::count())->toBe(1);
});

it('valida valor inicial', function () {
    $this->actingAs($this->operador)->post('/caixa/abrir', ['valor_inicial_centavos' => -5])
        ->assertSessionHasErrors('valor_inicial_centavos');
});

it('fechar sem caixa aberto dá erro explícito', function () {
    $this->actingAs($this->operador)->get('/caixa/fechar')
        ->assertRedirect('/caixa')
        ->assertSessionHasErrors('caixa');

    $this->actingAs($this->operador)->post('/caixa/fechar', ['valor_informado_centavos' => 0])
        ->assertSessionHasErrors('caixa');
});

it('fecha o caixa e mostra o resumo com a diferença', function () {
    $sessao = app(CashRegisterService::class)->abrir($this->operador, 10000);
    Sale::factory()->dinheiro(5000)->create(['cash_register_session_id' => $sessao->id, 'user_id' => $this->operador->id]);

    $this->actingAs($this->operador)->post('/caixa/fechar', ['valor_informado_centavos' => 15200])
        ->assertRedirect("/caixa/sessoes/{$sessao->id}");

    $this->actingAs($this->operador)->get("/caixa/sessoes/{$sessao->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('caixa/Resumo')
            ->where('sessao.valor_esperado_centavos', 15000)
            ->where('sessao.valor_informado_centavos', 15200)
            ->where('diferenca_centavos', 200));
});

it('operador não vê resumo de caixa de outro; gerente vê', function () {
    $deOutro = CashRegisterSession::factory()->fechada()->create();

    $this->actingAs($this->operador)->get("/caixa/sessoes/{$deOutro->id}")->assertForbidden();
    $this->actingAs(User::factory()->gerente()->create())->get("/caixa/sessoes/{$deOutro->id}")->assertOk();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/CashRegisterHttpTest.php`
Expected: FAIL (404 nas rotas `/caixa`).

- [ ] **Step 3: Controller**

`app/Http/Controllers/CashRegisterController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\SaleStatus;
use App\Exceptions\CaixaJaAbertoException;
use App\Exceptions\CaixaNaoAbertoException;
use App\Models\CashRegisterSession;
use App\Services\CashRegisterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller
{
    public function __construct(private readonly CashRegisterService $caixa) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->caixa->sessaoAberta($request->user()) !== null) {
            return redirect('/pdv');
        }

        return Inertia::render('caixa/Abrir');
    }

    public function abrir(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'valor_inicial_centavos' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $this->caixa->abrir($request->user(), (int) $dados['valor_inicial_centavos']);
        } catch (CaixaJaAbertoException $e) {
            throw ValidationException::withMessages(['caixa' => $e->getMessage()]);
        }

        return redirect('/pdv')->with('success', 'Caixa aberto.');
    }

    public function fecharForm(Request $request): Response|RedirectResponse
    {
        $sessao = $this->caixa->sessaoAberta($request->user());

        if ($sessao === null) {
            return redirect('/caixa')->withErrors(['caixa' => (new CaixaNaoAbertoException)->getMessage()]);
        }

        // Conferência cega: o valor esperado só aparece depois do fechamento.
        return Inertia::render('caixa/Fechar', [
            'sessao' => $sessao->only(['id', 'valor_inicial_centavos', 'aberta_em']),
            'quantidade_vendas' => $sessao->sales()->where('status', SaleStatus::Concluida)->count(),
        ]);
    }

    public function fechar(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'valor_informado_centavos' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $sessao = $this->caixa->fechar($request->user(), (int) $dados['valor_informado_centavos']);
        } catch (CaixaNaoAbertoException $e) {
            throw ValidationException::withMessages(['caixa' => $e->getMessage()]);
        }

        return redirect("/caixa/sessoes/{$sessao->id}")->with('success', 'Caixa fechado.');
    }

    public function resumo(CashRegisterSession $session): Response
    {
        Gate::authorize('view', $session);

        $porForma = $session->sales()
            ->where('status', SaleStatus::Concluida)
            ->selectRaw('forma_pagamento, count(*) as quantidade, sum(total_centavos) as total_centavos')
            ->groupBy('forma_pagamento')
            ->get()
            ->map(fn ($linha) => [
                'forma_pagamento' => $linha->forma_pagamento->label(),
                'quantidade' => (int) $linha->quantidade,
                'total_centavos' => (int) $linha->total_centavos,
            ]);

        return Inertia::render('caixa/Resumo', [
            'sessao' => $session->load('user:id,name'),
            'diferenca_centavos' => $session->diferencaCentavos(),
            'por_forma' => $porForma,
        ]);
    }
}
```

- [ ] **Step 4: Rotas**

Em `routes/web.php`, adicionar o import `use App\Http\Controllers\CashRegisterController;` e, **dentro** do grupo `Route::middleware(['auth', 'verified'])` da Task 5 (fora do prefixo `gerente`):
```php
    Route::get('caixa', [CashRegisterController::class, 'show'])->name('caixa.show');
    Route::post('caixa/abrir', [CashRegisterController::class, 'abrir'])->name('caixa.abrir');
    Route::get('caixa/fechar', [CashRegisterController::class, 'fecharForm'])->name('caixa.fechar.form');
    Route::post('caixa/fechar', [CashRegisterController::class, 'fechar'])->name('caixa.fechar');
    Route::get('caixa/sessoes/{session}', [CashRegisterController::class, 'resumo'])->name('caixa.sessoes.show');
```

- [ ] **Step 5: Páginas Vue**

`resources/js/pages/caixa/Abrir.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { paraCentavos } from '@/lib/dinheiro';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const erroCaixa = computed(() => (page.props.errors as Record<string, string>).caixa);

const form = useForm({ valor: '' });

function abrir() {
    form.transform(({ valor }) => ({ valor_inicial_centavos: paraCentavos(valor) })).post('/caixa/abrir');
}
</script>

<template>
    <Head title="Abrir caixa" />
    <AppLayout>
        <div class="mx-auto max-w-sm space-y-4 p-4">
            <h1 class="text-xl font-semibold">Abrir caixa</h1>
            <p v-if="erroCaixa" class="rounded bg-red-50 p-2 text-red-700">{{ erroCaixa }}</p>
            <form class="space-y-3" @submit.prevent="abrir">
                <label class="block">
                    <span>Valor inicial em dinheiro (R$)</span>
                    <input v-model="form.valor" inputmode="decimal" placeholder="0,00" autofocus class="w-full rounded border px-3 py-2 text-lg" />
                    <small class="text-red-600">{{ (form.errors as Record<string, string>).valor_inicial_centavos }}</small>
                </label>
                <button :disabled="form.processing" class="w-full rounded bg-primary px-4 py-2 text-primary-foreground">Abrir caixa</button>
            </form>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/caixa/Fechar.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatarCentavos, paraCentavos } from '@/lib/dinheiro';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    sessao: { id: number; valor_inicial_centavos: number; aberta_em: string };
    quantidade_vendas: number;
}>();

const page = usePage();
const erroCaixa = computed(() => (page.props.errors as Record<string, string>).caixa);
const form = useForm({ valor: '' });

function fechar() {
    form.transform(({ valor }) => ({ valor_informado_centavos: paraCentavos(valor) })).post('/caixa/fechar');
}
</script>

<template>
    <Head title="Fechar caixa" />
    <AppLayout>
        <div class="mx-auto max-w-sm space-y-4 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Fechar caixa</h1>
                <Link href="/pdv" class="underline">Voltar ao PDV</Link>
            </div>
            <p v-if="erroCaixa" class="rounded bg-red-50 p-2 text-red-700">{{ erroCaixa }}</p>
            <dl class="grid grid-cols-2 gap-1 text-sm">
                <dt>Aberto em</dt><dd>{{ new Date(sessao.aberta_em).toLocaleString('pt-BR') }}</dd>
                <dt>Valor inicial</dt><dd>{{ formatarCentavos(sessao.valor_inicial_centavos) }}</dd>
                <dt>Vendas concluídas</dt><dd>{{ quantidade_vendas }}</dd>
            </dl>
            <form class="space-y-3" @submit.prevent="fechar">
                <label class="block">
                    <span>Valor contado na gaveta (R$)</span>
                    <input v-model="form.valor" inputmode="decimal" placeholder="0,00" autofocus class="w-full rounded border px-3 py-2 text-lg" />
                    <small class="text-red-600">{{ (form.errors as Record<string, string>).valor_informado_centavos }}</small>
                </label>
                <button :disabled="form.processing" class="w-full rounded bg-primary px-4 py-2 text-primary-foreground">Fechar caixa</button>
            </form>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/caixa/Resumo.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatarCentavos } from '@/lib/dinheiro';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    sessao: {
        id: number;
        valor_inicial_centavos: number;
        valor_esperado_centavos: number | null;
        valor_informado_centavos: number | null;
        aberta_em: string;
        fechada_em: string | null;
        user: { id: number; name: string };
    };
    diferenca_centavos: number | null;
    por_forma: { forma_pagamento: string; quantidade: number; total_centavos: number }[];
}>();
</script>

<template>
    <Head title="Resumo do caixa" />
    <AppLayout>
        <div class="mx-auto max-w-md space-y-4 p-4">
            <h1 class="text-xl font-semibold">Resumo do caixa #{{ sessao.id }}</h1>
            <p class="text-sm text-muted-foreground">Operador: {{ sessao.user.name }}</p>
            <dl class="grid grid-cols-2 gap-1">
                <dt>Valor inicial</dt><dd>{{ formatarCentavos(sessao.valor_inicial_centavos) }}</dd>
                <template v-if="sessao.fechada_em">
                    <dt>Esperado</dt><dd>{{ formatarCentavos(sessao.valor_esperado_centavos ?? 0) }}</dd>
                    <dt>Contado</dt><dd>{{ formatarCentavos(sessao.valor_informado_centavos ?? 0) }}</dd>
                    <dt>Diferença</dt>
                    <dd :class="{ 'text-green-700': (diferenca_centavos ?? 0) > 0, 'text-red-700': (diferenca_centavos ?? 0) < 0 }">
                        {{ formatarCentavos(diferenca_centavos ?? 0) }}
                        <span v-if="(diferenca_centavos ?? 0) > 0">(sobra)</span>
                        <span v-else-if="(diferenca_centavos ?? 0) < 0">(falta)</span>
                    </dd>
                </template>
                <template v-else>
                    <dt>Situação</dt><dd>Aberto</dd>
                </template>
            </dl>
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b"><th class="p-2">Forma</th><th class="p-2 text-right">Vendas</th><th class="p-2 text-right">Total</th></tr></thead>
                <tbody>
                    <tr v-for="f in por_forma" :key="f.forma_pagamento" class="border-b">
                        <td class="p-2">{{ f.forma_pagamento }}</td>
                        <td class="p-2 text-right">{{ f.quantidade }}</td>
                        <td class="p-2 text-right">{{ formatarCentavos(f.total_centavos) }}</td>
                    </tr>
                </tbody>
            </table>
            <Link href="/caixa" class="inline-block rounded border px-3 py-2">Voltar</Link>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/sail pest tests/Feature/CashRegisterHttpTest.php && ./vendor/bin/sail npm run build`
Expected: `8 passed`; build sem erros.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: telas de abertura, fechamento e resumo de caixa"
```

---

### Task 9: Rotas do PDV (tela, busca de produto, venda, cancelamento)

**Files:**
- Create: `app/Policies/SalePolicy.php`
- Create: `app/Http/Controllers/PdvController.php`, `app/Http/Controllers/ProductSearchController.php`, `app/Http/Controllers/SaleController.php`, `app/Http/Controllers/SaleCancellationController.php`
- Create: `resources/js/pages/pdv/Index.vue` (versão provisória; a tela completa vem na Task 10)
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`, `routes/web.php`
- Test: `tests/Feature/PdvHttpTest.php`

**Interfaces:**
- Consumes: `SaleService::finalizar/cancelar`, `VendaInvalidaException`, `VendaJaCanceladaException` (Task 7); `EstoqueInsuficienteException` (Task 4); `CashRegisterService::sessaoAberta`, `CaixaNaoAbertoException`, `FormaPagamento` (Task 6); `Product::busca()` (Task 3).
- Produces:
  - `GET /pdv` (`pdv`) → Inertia `pdv/Index` com props `sessao` (`id`, `valor_inicial_centavos`, `aberta_em`), `formas` (`list<{value: string, label: string}>`), `ultimas_vendas` (`list<{id, total_centavos, forma_pagamento, status, created_at}>`, 10 mais recentes da sessão). Sem caixa aberto → redirect `/caixa` com erro `caixa`.
  - `GET /pdv/produtos?q=` (`pdv.produtos`) → JSON `list<{id, nome, codigo_barras, preco_centavos, estoque_atual}>` (máx. 10; `q` vazio → `[]`).
  - `POST /pdv/vendas` (`pdv.vendas.store`) com `itens[] {product_id, quantidade}`, `desconto_percentual`, `forma_pagamento`, `valor_recebido_centavos?` → redirect `/pdv` com flash `venda = {id, total_centavos, troco_centavos}`. Erros de regra viram erros de validação nas chaves `itens`, `desconto_percentual`, `valor_recebido_centavos` ou `caixa`.
  - `POST /vendas/{sale}/cancelar` (`vendas.cancelar`) → redirect back; erro `venda` se já cancelada.
  - `SalePolicy::create(User): bool` (todos) e `SalePolicy::cancel(User, Sale): bool` (gerente sempre; operador só a própria venda com a sessão ainda aberta).
  - Props compartilhadas do Inertia: `flash.success` e `flash.venda`.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/PdvHttpTest.php`:
```php
<?php

use App\Enums\FormaPagamento;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\SaleService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->operador = User::factory()->create();
    $this->caixa = app(CashRegisterService::class);
});

function vendaDe(User $user, Product $produto, int $qtd = 1): Sale
{
    return app(SaleService::class)->finalizar($user, [['product_id' => $produto->id, 'quantidade' => $qtd]], 0, FormaPagamento::Cartao);
}

it('sem caixa aberto, o PDV manda abrir o caixa', function () {
    $this->actingAs($this->operador)->get('/pdv')
        ->assertRedirect('/caixa')
        ->assertSessionHasErrors('caixa');
});

it('com caixa aberto mostra o PDV', function () {
    $this->caixa->abrir($this->operador, 0);

    $this->actingAs($this->operador)->get('/pdv')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pdv/Index')
            ->has('formas', 3)
            ->has('ultimas_vendas', 0));
});

it('busca produto por código de barras', function () {
    Product::factory()->comEstoque(3)->create(['codigo_barras' => '7891000100103', 'nome' => 'Arroz', 'preco_centavos' => 2590]);

    $this->actingAs($this->operador)->getJson('/pdv/produtos?q=7891000100103')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.nome', 'Arroz')
        ->assertJsonPath('0.preco_centavos', 2590)
        ->assertJsonPath('0.estoque_atual', 3);

    $this->actingAs($this->operador)->getJson('/pdv/produtos?q=')->assertExactJson([]);
});

it('finaliza venda pelo PDV', function () {
    $this->caixa->abrir($this->operador, 0);
    $produto = Product::factory()->comEstoque(5)->create(['preco_centavos' => 1000]);

    $this->actingAs($this->operador)->post('/pdv/vendas', [
        'itens' => [['product_id' => $produto->id, 'quantidade' => 2]],
        'desconto_percentual' => 0,
        'forma_pagamento' => 'dinheiro',
        'valor_recebido_centavos' => 5000,
    ])->assertRedirect('/pdv')
        ->assertSessionHas('venda', fn ($v) => $v['total_centavos'] === 2000 && $v['troco_centavos'] === 3000);

    expect($produto->fresh()->estoque_atual)->toBe(3);
});

it('estoque insuficiente volta como erro em itens', function () {
    $this->caixa->abrir($this->operador, 0);
    $produto = Product::factory()->comEstoque(1)->create();

    $this->actingAs($this->operador)->post('/pdv/vendas', [
        'itens' => [['product_id' => $produto->id, 'quantidade' => 2]],
        'desconto_percentual' => 0,
        'forma_pagamento' => 'cartao',
    ])->assertSessionHasErrors('itens');
});

it('valida a requisição de venda', function (array $sobrescrever, string $campo) {
    $this->caixa->abrir($this->operador, 0);
    $produto = Product::factory()->comEstoque(10)->create();

    $dados = array_replace([
        'itens' => [['product_id' => $produto->id, 'quantidade' => 1]],
        'desconto_percentual' => 0,
        'forma_pagamento' => 'cartao',
    ], $sobrescrever);

    $this->actingAs($this->operador)->post('/pdv/vendas', $dados)->assertSessionHasErrors($campo);
    expect(Sale::count())->toBe(0);
})->with([
    'sem itens' => [['itens' => []], 'itens'],
    'quantidade zero' => [['itens' => [['product_id' => 1, 'quantidade' => 0]]], 'itens.0.quantidade'],
    'desconto 150%' => [['desconto_percentual' => 150], 'desconto_percentual'],
    'forma inválida' => [['forma_pagamento' => 'cheque'], 'forma_pagamento'],
]);

it('dinheiro insuficiente volta como erro', function () {
    $this->caixa->abrir($this->operador, 0);
    $produto = Product::factory()->comEstoque(1)->create(['preco_centavos' => 1000]);

    $this->actingAs($this->operador)->post('/pdv/vendas', [
        'itens' => [['product_id' => $produto->id, 'quantidade' => 1]],
        'desconto_percentual' => 0,
        'forma_pagamento' => 'dinheiro',
        'valor_recebido_centavos' => 500,
    ])->assertSessionHasErrors('valor_recebido_centavos');
});

it('operador cancela a própria venda com o caixa aberto', function () {
    $this->caixa->abrir($this->operador, 0);
    $produto = Product::factory()->comEstoque(5)->create();
    $venda = vendaDe($this->operador, $produto, 2);

    $this->actingAs($this->operador)->post("/vendas/{$venda->id}/cancelar")->assertRedirect();

    expect($venda->fresh()->status)->toBe(SaleStatus::Cancelada)
        ->and($produto->fresh()->estoque_atual)->toBe(5);
});

it('operador não cancela venda de outro operador', function () {
    $outro = User::factory()->create();
    $this->caixa->abrir($outro, 0);
    $this->caixa->abrir($this->operador, 0);
    $venda = vendaDe($outro, Product::factory()->comEstoque(5)->create());

    $this->actingAs($this->operador)->post("/vendas/{$venda->id}/cancelar")->assertForbidden();
});

it('operador não cancela venda de caixa já fechado', function () {
    $this->caixa->abrir($this->operador, 0);
    $venda = vendaDe($this->operador, Product::factory()->comEstoque(5)->create());
    $this->caixa->fechar($this->operador, 0);

    $this->actingAs($this->operador)->post("/vendas/{$venda->id}/cancelar")->assertForbidden();
});

it('gerente cancela qualquer venda', function () {
    $this->caixa->abrir($this->operador, 0);
    $venda = vendaDe($this->operador, Product::factory()->comEstoque(5)->create());
    $this->caixa->fechar($this->operador, 0);

    $this->actingAs(User::factory()->gerente()->create())->post("/vendas/{$venda->id}/cancelar")->assertRedirect();

    expect($venda->fresh()->status)->toBe(SaleStatus::Cancelada);
});

it('cancelar duas vezes volta erro', function () {
    $this->caixa->abrir($this->operador, 0);
    $venda = vendaDe($this->operador, Product::factory()->comEstoque(5)->create());

    $this->actingAs($this->operador)->post("/vendas/{$venda->id}/cancelar");
    $this->actingAs($this->operador)->post("/vendas/{$venda->id}/cancelar")->assertSessionHasErrors('venda');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/PdvHttpTest.php`
Expected: FAIL (404 em `/pdv`).

- [ ] **Step 3: Policy**

`app/Policies/SalePolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function cancel(User $user, Sale $sale): bool
    {
        if ($user->isGerente()) {
            return true;
        }

        return $sale->user_id === $user->id && $sale->cashRegisterSession->estaAberta();
    }
}
```

- [ ] **Step 4: Controllers**

`app/Http/Controllers/PdvController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\FormaPagamento;
use App\Exceptions\CaixaNaoAbertoException;
use App\Services\CashRegisterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PdvController extends Controller
{
    public function __invoke(Request $request, CashRegisterService $caixa): Response|RedirectResponse
    {
        $sessao = $caixa->sessaoAberta($request->user());

        if ($sessao === null) {
            return redirect('/caixa')->withErrors(['caixa' => (new CaixaNaoAbertoException)->getMessage()]);
        }

        return Inertia::render('pdv/Index', [
            'sessao' => $sessao->only(['id', 'valor_inicial_centavos', 'aberta_em']),
            'formas' => array_map(
                fn (FormaPagamento $f) => ['value' => $f->value, 'label' => $f->label()],
                FormaPagamento::cases(),
            ),
            'ultimas_vendas' => $sessao->sales()
                ->latest('id')
                ->limit(10)
                ->get(['id', 'total_centavos', 'forma_pagamento', 'status', 'created_at']),
        ]);
    }
}
```

`app/Http/Controllers/ProductSearchController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $termo = trim((string) $request->query('q', ''));

        if ($termo === '') {
            return response()->json([]);
        }

        return response()->json(
            Product::busca($termo)
                ->orderBy('nome')
                ->limit(10)
                ->get(['id', 'nome', 'codigo_barras', 'preco_centavos', 'estoque_atual'])
        );
    }
}
```

`app/Http/Controllers/SaleController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Enums\FormaPagamento;
use App\Exceptions\CaixaNaoAbertoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\VendaInvalidaException;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function store(Request $request, SaleService $vendas): RedirectResponse
    {
        Gate::authorize('create', Sale::class);

        $dados = $request->validate([
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.product_id' => ['required', 'integer'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
            'desconto_percentual' => ['required', 'numeric', 'min:0', 'max:100'],
            'forma_pagamento' => ['required', Rule::enum(FormaPagamento::class)],
            'valor_recebido_centavos' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $venda = $vendas->finalizar(
                $request->user(),
                array_map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantidade' => (int) $i['quantidade']], $dados['itens']),
                (float) $dados['desconto_percentual'],
                FormaPagamento::from($dados['forma_pagamento']),
                isset($dados['valor_recebido_centavos']) ? (int) $dados['valor_recebido_centavos'] : null,
            );
        } catch (EstoqueInsuficienteException $e) {
            throw ValidationException::withMessages(['itens' => $e->getMessage()]);
        } catch (VendaInvalidaException $e) {
            throw ValidationException::withMessages([$e->campo => $e->getMessage()]);
        } catch (CaixaNaoAbertoException $e) {
            throw ValidationException::withMessages(['caixa' => $e->getMessage()]);
        }

        return redirect('/pdv')->with('venda', [
            'id' => $venda->id,
            'total_centavos' => $venda->total_centavos,
            'troco_centavos' => $venda->troco_centavos,
        ]);
    }
}
```

`app/Http/Controllers/SaleCancellationController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\VendaJaCanceladaException;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaleCancellationController extends Controller
{
    public function __invoke(Request $request, Sale $sale, SaleService $vendas): RedirectResponse
    {
        Gate::authorize('cancel', $sale);

        try {
            $vendas->cancelar($sale, $request->user());
        } catch (VendaJaCanceladaException $e) {
            throw ValidationException::withMessages(['venda' => $e->getMessage()]);
        }

        return back()->with('success', "Venda #{$sale->id} cancelada e estoque devolvido.");
    }
}
```

- [ ] **Step 5: Flash no Inertia**

Em `app/Http/Middleware/HandleInertiaRequests.php`, dentro do array retornado por `share()`, adicionar:
```php
'flash' => [
    'success' => fn () => $request->session()->get('success'),
    'venda' => fn () => $request->session()->get('venda'),
],
```

- [ ] **Step 6: Rotas**

Em `routes/web.php`, adicionar os imports:
```php
use App\Http\Controllers\PdvController;
use App\Http\Controllers\ProductSearchController;
use App\Http\Controllers\SaleCancellationController;
use App\Http\Controllers\SaleController;
```
e, **dentro** do grupo `Route::middleware(['auth', 'verified'])` (fora do prefixo `gerente`):
```php
    Route::get('pdv', PdvController::class)->name('pdv');
    Route::get('pdv/produtos', ProductSearchController::class)->name('pdv.produtos');
    Route::post('pdv/vendas', [SaleController::class, 'store'])->name('pdv.vendas.store');
    Route::post('vendas/{sale}/cancelar', SaleCancellationController::class)->name('vendas.cancelar');
```

- [ ] **Step 7: Página provisória do PDV (substituída na Task 10)**

`resources/js/pages/pdv/Index.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{ sessao: { id: number }; formas: { value: string; label: string }[]; ultimas_vendas: unknown[] }>();
</script>

<template>
    <Head title="PDV" />
    <AppLayout>
        <div class="p-4">PDV — caixa #{{ sessao.id }}</div>
    </AppLayout>
</template>
```

- [ ] **Step 8: Rodar e ver passar**

Run: `./vendor/bin/sail pest tests/Feature/PdvHttpTest.php`
Expected: `15 passed` (11 testes + 4 casos do dataset).

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: rotas do PDV com busca, venda e cancelamento autorizado por policy"
```

---

### Task 10: Tela do PDV (carrinho, leitor de código de barras, pagamento) + Vitest

**Files:**
- Create: `vitest.config.ts`
- Create: `resources/js/composables/useCart.ts`
- Create: `resources/js/components/Cart.vue`, `resources/js/components/LeitorCodigoBarras.vue`
- Modify: `resources/js/pages/pdv/Index.vue` (substitui a versão provisória da Task 9)
- Modify: `package.json` (script `test` e devDependencies)
- Test: `resources/js/composables/__tests__/useCart.test.ts`, `resources/js/lib/__tests__/dinheiro.test.ts`, `resources/js/components/__tests__/Cart.test.ts`

**Interfaces:**
- Consumes: `formatarCentavos`, `paraCentavos` (Task 5); rotas e props de `GET /pdv`, `GET /pdv/produtos`, `POST /pdv/vendas`, `POST /vendas/{sale}/cancelar`, flash `venda` (Task 9).
- Produces:
  - `useCart()` → `{ itens, descontoPercentual, erro, subtotal, desconto, total, descontoValido, adicionar(produto, quantidade = 1): boolean, alterarQuantidade(productId, quantidade): boolean, remover(productId): void, limpar(): void, troco(recebidoCentavos): number, paraRequisicao(): {product_id: number; quantidade: number}[] }`.
  - `calcularDesconto(subtotalCentavos, percentual): number`, a mesma regra do backend (pontos-base, meio para cima).
  - `Cart.vue`: props `itens: ItemCarrinho[]`, `subtotal`, `desconto`, `total` (centavos); emits `alterar(productId, quantidade)` e `remover(productId)`.
  - `LeitorCodigoBarras.vue`: emite `buscar(termo: string)` no Enter e limpa o campo.
  - `npm run test` roda o Vitest.

- [ ] **Step 1: Instalar o Vitest**

```bash
./vendor/bin/sail npm install -D vitest @vue/test-utils jsdom
./vendor/bin/sail npm pkg set scripts.test="vitest run"
```

`vitest.config.ts`:
```ts
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
    },
});
```

- [ ] **Step 2: Escrever os testes**

`resources/js/lib/__tests__/dinheiro.test.ts`:
```ts
import { formatarCentavos, paraCentavos } from '@/lib/dinheiro';
import { describe, expect, it } from 'vitest';

describe('dinheiro', () => {
    it('formata centavos em reais', () => {
        expect(formatarCentavos(1250)).toBe('R$ 12,50');
        expect(formatarCentavos(0)).toBe('R$ 0,00');
        expect(formatarCentavos(123456)).toBe('R$ 1.234,56');
    });

    it('converte texto digitado em centavos', () => {
        expect(paraCentavos('12,50')).toBe(1250);
        expect(paraCentavos('12.5')).toBe(1250);
        expect(paraCentavos('1.234,56')).toBe(123456);
        expect(paraCentavos('R$ 3,00')).toBe(300);
        expect(paraCentavos('')).toBe(0);
        expect(paraCentavos('abc')).toBe(0);
    });
});
```

`resources/js/composables/__tests__/useCart.test.ts`:
```ts
import { calcularDesconto, useCart } from '@/composables/useCart';
import { describe, expect, it } from 'vitest';

const arroz = { id: 1, nome: 'Arroz', preco_centavos: 2590, estoque_atual: 5 };
const feijao = { id: 2, nome: 'Feijão', preco_centavos: 899, estoque_atual: 10 };

describe('useCart', () => {
    it('adiciona itens e soma a quantidade do mesmo produto', () => {
        const cart = useCart();
        cart.adicionar(arroz);
        cart.adicionar(arroz, 2);
        cart.adicionar(feijao);

        expect(cart.itens.value).toHaveLength(2);
        expect(cart.itens.value[0].quantidade).toBe(3);
        expect(cart.subtotal.value).toBe(2590 * 3 + 899);
    });

    it('bloqueia quantidade acima do estoque e mostra o disponível', () => {
        const cart = useCart();
        cart.adicionar(arroz, 4);

        expect(cart.adicionar(arroz, 2)).toBe(false);
        expect(cart.erro.value).toContain('disponível 5');
        expect(cart.itens.value[0].quantidade).toBe(4);
    });

    it('altera quantidade e remove quando chega a zero', () => {
        const cart = useCart();
        cart.adicionar(arroz);
        cart.adicionar(feijao);

        expect(cart.alterarQuantidade(1, 6)).toBe(false);
        cart.alterarQuantidade(1, 2);
        expect(cart.itens.value[0].quantidade).toBe(2);

        cart.alterarQuantidade(2, 0);
        expect(cart.itens.value.map((i) => i.produto.id)).toEqual([1]);

        cart.remover(1);
        expect(cart.itens.value).toHaveLength(0);
    });

    it('aplica desconto arredondando igual ao backend (R$ 9,99 com 10% = R$ 8,99)', () => {
        const cart = useCart();
        cart.adicionar({ id: 3, nome: 'Café', preco_centavos: 999, estoque_atual: 1 });
        cart.descontoPercentual.value = 10;

        expect(cart.desconto.value).toBe(100);
        expect(cart.total.value).toBe(899);
        expect(calcularDesconto(1000, 12.5)).toBe(125);
    });

    it('sinaliza desconto fora de 0 a 100', () => {
        const cart = useCart();
        cart.descontoPercentual.value = 101;
        expect(cart.descontoValido.value).toBe(false);
        cart.descontoPercentual.value = -1;
        expect(cart.descontoValido.value).toBe(false);
        cart.descontoPercentual.value = 100;
        expect(cart.descontoValido.value).toBe(true);
    });

    it('calcula troco e monta a requisição', () => {
        const cart = useCart();
        cart.adicionar(arroz);

        expect(cart.troco(3000)).toBe(410);
        expect(cart.troco(1000)).toBe(0);
        expect(cart.paraRequisicao()).toEqual([{ product_id: 1, quantidade: 1 }]);

        cart.limpar();
        expect(cart.itens.value).toHaveLength(0);
        expect(cart.descontoPercentual.value).toBe(0);
    });
});
```

`resources/js/components/__tests__/Cart.test.ts`:
```ts
import Cart from '@/components/Cart.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const itens = [
    { produto: { id: 1, nome: 'Arroz', preco_centavos: 2590, estoque_atual: 5 }, quantidade: 2 },
    { produto: { id: 2, nome: 'Feijão', preco_centavos: 899, estoque_atual: 10 }, quantidade: 1 },
];

describe('Cart', () => {
    it('lista os itens e mostra os totais formatados', () => {
        const wrapper = mount(Cart, { props: { itens, subtotal: 6079, desconto: 608, total: 5471 } });

        expect(wrapper.findAll('[data-test="item"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Arroz');
        expect(wrapper.text()).toContain('R$ 51,80');
        expect(wrapper.get('[data-test="total"]').text()).toContain('R$ 54,71');
        expect(wrapper.get('[data-test="desconto"]').text()).toContain('R$ 6,08');
    });

    it('emite remover e alterar', async () => {
        const wrapper = mount(Cart, { props: { itens, subtotal: 6079, desconto: 0, total: 6079 } });

        await wrapper.findAll('[data-test="remover"]')[1].trigger('click');
        expect(wrapper.emitted('remover')).toEqual([[2]]);

        // O componente escuta "change" (não "input"), então disparamos o evento explicitamente.
        const campo = wrapper.findAll('[data-test="quantidade"]')[0];
        (campo.element as HTMLInputElement).value = '3';
        await campo.trigger('change');
        expect(wrapper.emitted('alterar')).toEqual([[1, 3]]);
    });

    it('mostra mensagem quando vazio', () => {
        const wrapper = mount(Cart, { props: { itens: [], subtotal: 0, desconto: 0, total: 0 } });
        expect(wrapper.text()).toContain('Carrinho vazio');
    });
});
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `./vendor/bin/sail npm run test`
Expected: os testes de `dinheiro` passam; `useCart` e `Cart` falham com `Failed to resolve import "@/composables/useCart"` / `"@/components/Cart.vue"`.

- [ ] **Step 4: Implementar `useCart`**

`resources/js/composables/useCart.ts`:
```ts
import { computed, ref } from 'vue';

export interface ProdutoCarrinho {
    id: number;
    nome: string;
    preco_centavos: number;
    estoque_atual: number;
}

export interface ItemCarrinho {
    produto: ProdutoCarrinho;
    quantidade: number;
}

/** Mesma regra do SaleService: pontos-base inteiros, arredondamento meio para cima. */
export function calcularDesconto(subtotalCentavos: number, percentual: number): number {
    const pontosBase = Math.round(percentual * 100);
    return Math.floor((subtotalCentavos * pontosBase + 5000) / 10000);
}

export function useCart() {
    const itens = ref<ItemCarrinho[]>([]);
    const descontoPercentual = ref(0);
    const erro = ref<string | null>(null);

    function semEstoque(produto: ProdutoCarrinho, quantidade: number): boolean {
        if (quantidade <= produto.estoque_atual) return false;
        erro.value = `Estoque insuficiente para ${produto.nome}: disponível ${produto.estoque_atual}.`;
        return true;
    }

    function adicionar(produto: ProdutoCarrinho, quantidade = 1): boolean {
        erro.value = null;
        const existente = itens.value.find((i) => i.produto.id === produto.id);
        const novaQuantidade = (existente?.quantidade ?? 0) + quantidade;
        if (semEstoque(produto, novaQuantidade)) return false;

        if (existente) {
            existente.quantidade = novaQuantidade;
        } else {
            itens.value.push({ produto, quantidade });
        }
        return true;
    }

    function alterarQuantidade(productId: number, quantidade: number): boolean {
        erro.value = null;
        const item = itens.value.find((i) => i.produto.id === productId);
        if (!item) return false;
        if (quantidade <= 0) {
            remover(productId);
            return true;
        }
        if (semEstoque(item.produto, quantidade)) return false;
        item.quantidade = quantidade;
        return true;
    }

    function remover(productId: number): void {
        itens.value = itens.value.filter((i) => i.produto.id !== productId);
    }

    function limpar(): void {
        itens.value = [];
        descontoPercentual.value = 0;
        erro.value = null;
    }

    const subtotal = computed(() => itens.value.reduce((soma, i) => soma + i.produto.preco_centavos * i.quantidade, 0));
    const descontoValido = computed(() => descontoPercentual.value >= 0 && descontoPercentual.value <= 100);
    const desconto = computed(() => (descontoValido.value ? calcularDesconto(subtotal.value, descontoPercentual.value) : 0));
    const total = computed(() => subtotal.value - desconto.value);

    function troco(recebidoCentavos: number): number {
        return Math.max(0, recebidoCentavos - total.value);
    }

    function paraRequisicao(): { product_id: number; quantidade: number }[] {
        return itens.value.map((i) => ({ product_id: i.produto.id, quantidade: i.quantidade }));
    }

    return { itens, descontoPercentual, erro, subtotal, desconto, total, descontoValido, adicionar, alterarQuantidade, remover, limpar, troco, paraRequisicao };
}
```

- [ ] **Step 5: Componentes**

`resources/js/components/Cart.vue`:
```vue
<script setup lang="ts">
import type { ItemCarrinho } from '@/composables/useCart';
import { formatarCentavos } from '@/lib/dinheiro';

defineProps<{ itens: ItemCarrinho[]; subtotal: number; desconto: number; total: number }>();
const emit = defineEmits<{ alterar: [productId: number, quantidade: number]; remover: [productId: number] }>();
</script>

<template>
    <div class="space-y-2">
        <p v-if="itens.length === 0" class="py-8 text-center text-muted-foreground">Carrinho vazio</p>
        <ul v-else class="divide-y rounded border">
            <li v-for="item in itens" :key="item.produto.id" data-test="item" class="flex items-center gap-2 p-2">
                <span class="flex-1">{{ item.produto.nome }}</span>
                <input
                    data-test="quantidade"
                    type="number"
                    min="0"
                    :value="item.quantidade"
                    class="w-16 rounded border px-2 py-1 text-right"
                    @change="emit('alterar', item.produto.id, Number(($event.target as HTMLInputElement).value))"
                />
                <span class="w-24 text-right">{{ formatarCentavos(item.produto.preco_centavos * item.quantidade) }}</span>
                <button data-test="remover" type="button" class="px-2 text-red-600" aria-label="Remover item" @click="emit('remover', item.produto.id)">✕</button>
            </li>
        </ul>
        <dl class="grid grid-cols-2 gap-1 text-right">
            <dt class="text-left">Subtotal</dt><dd>{{ formatarCentavos(subtotal) }}</dd>
            <dt class="text-left">Desconto</dt><dd data-test="desconto">− {{ formatarCentavos(desconto) }}</dd>
            <dt class="text-left text-lg font-semibold">Total</dt><dd data-test="total" class="text-lg font-semibold">{{ formatarCentavos(total) }}</dd>
        </dl>
    </div>
</template>
```

`resources/js/components/LeitorCodigoBarras.vue`:
```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue';

const emit = defineEmits<{ buscar: [termo: string] }>();
const termo = ref('');
const campo = ref<HTMLInputElement | null>(null);

function enviar() {
    const valor = termo.value.trim();
    if (valor === '') return;
    emit('buscar', valor);
    termo.value = '';
}

defineExpose({ focar: () => campo.value?.focus() });
onMounted(() => campo.value?.focus());
</script>

<template>
    <input
        ref="campo"
        v-model="termo"
        class="w-full rounded border px-3 py-3 text-lg"
        placeholder="Passe o código de barras ou digite o nome e tecle Enter"
        @keydown.enter.prevent="enviar"
    />
</template>
```

- [ ] **Step 6: Rodar os testes JS e ver passar**

Run: `./vendor/bin/sail npm run test`
Expected: 3 arquivos, `11 passed` (2 dinheiro + 6 useCart + 3 Cart).

- [ ] **Step 7: Página completa do PDV**

`resources/js/pages/pdv/Index.vue` (substitui a provisória):
```vue
<script setup lang="ts">
import Cart from '@/components/Cart.vue';
import LeitorCodigoBarras from '@/components/LeitorCodigoBarras.vue';
import { useCart, type ProdutoCarrinho } from '@/composables/useCart';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatarCentavos, paraCentavos } from '@/lib/dinheiro';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface ProdutoBusca extends ProdutoCarrinho {
    codigo_barras: string;
}
interface VendaResumo {
    id: number;
    total_centavos: number;
    forma_pagamento: string;
    status: 'concluida' | 'cancelada';
    created_at: string;
}

const props = defineProps<{
    sessao: { id: number; valor_inicial_centavos: number; aberta_em: string };
    formas: { value: string; label: string }[];
    ultimas_vendas: VendaResumo[];
}>();

const page = usePage();
const erros = computed(() => page.props.errors as Record<string, string>);
const vendaFinalizada = computed(() => (page.props.flash as { venda?: { id: number; total_centavos: number; troco_centavos: number } } | undefined)?.venda);
const sucesso = computed(() => (page.props.flash as { success?: string } | undefined)?.success);

const cart = useCart();
const resultados = ref<ProdutoBusca[]>([]);
const buscando = ref(false);
const forma = ref(props.formas[0]?.value ?? 'dinheiro');
const recebido = ref('');
const enviando = ref(false);
const confirmandoCancelamento = ref<number | null>(null);

const recebidoCentavos = computed(() => paraCentavos(recebido.value));
const podeFinalizar = computed(
    () =>
        cart.itens.value.length > 0 &&
        cart.descontoValido.value &&
        !enviando.value &&
        (forma.value !== 'dinheiro' || recebidoCentavos.value >= cart.total.value),
);

async function buscar(termo: string) {
    buscando.value = true;
    try {
        const resposta = await fetch(`/pdv/produtos?q=${encodeURIComponent(termo)}`, {
            headers: { Accept: 'application/json' },
        });
        const lista: ProdutoBusca[] = await resposta.json();
        const exato = lista.find((p) => p.codigo_barras === termo);
        if (exato) {
            cart.adicionar(exato);
            resultados.value = [];
        } else if (lista.length === 1) {
            cart.adicionar(lista[0]);
            resultados.value = [];
        } else {
            resultados.value = lista;
            cart.erro.value = lista.length === 0 ? 'Nenhum produto encontrado.' : null;
        }
    } finally {
        buscando.value = false;
    }
}

function escolher(produto: ProdutoBusca) {
    cart.adicionar(produto);
    resultados.value = [];
}

function finalizar() {
    if (!podeFinalizar.value) return;
    enviando.value = true;
    router.post(
        '/pdv/vendas',
        {
            itens: cart.paraRequisicao(),
            desconto_percentual: cart.descontoPercentual.value,
            forma_pagamento: forma.value,
            valor_recebido_centavos: forma.value === 'dinheiro' ? recebidoCentavos.value : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                cart.limpar();
                recebido.value = '';
            },
            onFinish: () => (enviando.value = false),
        },
    );
}

function cancelarVenda(id: number) {
    if (confirmandoCancelamento.value !== id) {
        confirmandoCancelamento.value = id;
        return;
    }
    router.post(`/vendas/${id}/cancelar`, {}, { preserveScroll: true, onFinish: () => (confirmandoCancelamento.value = null) });
}
</script>

<template>
    <Head title="PDV" />
    <AppLayout>
        <div class="grid gap-4 p-4 lg:grid-cols-3">
            <section class="space-y-3 lg:col-span-2">
                <div class="flex items-center justify-between">
                    <h1 class="text-xl font-semibold">Caixa #{{ sessao.id }}</h1>
                    <Link href="/caixa/fechar" class="rounded border px-3 py-2">Fechar caixa</Link>
                </div>

                <p v-if="vendaFinalizada" class="rounded bg-green-50 p-3 text-green-800">
                    Venda #{{ vendaFinalizada.id }} finalizada — total {{ formatarCentavos(vendaFinalizada.total_centavos) }}
                    <strong v-if="vendaFinalizada.troco_centavos > 0">· Troco: {{ formatarCentavos(vendaFinalizada.troco_centavos) }}</strong>
                </p>
                <p v-if="sucesso" class="rounded bg-green-50 p-3 text-green-800">{{ sucesso }}</p>
                <p v-for="(mensagem, campo) in erros" :key="campo" class="rounded bg-red-50 p-3 text-red-700">{{ mensagem }}</p>

                <LeitorCodigoBarras @buscar="buscar" />
                <p v-if="buscando" class="text-sm text-muted-foreground">Buscando…</p>
                <p v-if="cart.erro.value" class="text-sm text-red-600">{{ cart.erro.value }}</p>
                <ul v-if="resultados.length" class="divide-y rounded border">
                    <li v-for="p in resultados" :key="p.id">
                        <button type="button" class="flex w-full justify-between p-2 text-left hover:bg-muted" @click="escolher(p)">
                            <span>{{ p.nome }} <small class="text-muted-foreground">({{ p.estoque_atual }} em estoque)</small></span>
                            <span>{{ formatarCentavos(p.preco_centavos) }}</span>
                        </button>
                    </li>
                </ul>

                <Cart
                    :itens="cart.itens.value"
                    :subtotal="cart.subtotal.value"
                    :desconto="cart.desconto.value"
                    :total="cart.total.value"
                    @alterar="cart.alterarQuantidade"
                    @remover="cart.remover"
                />
            </section>

            <aside class="space-y-3">
                <h2 class="font-semibold">Pagamento</h2>
                <label class="block">
                    <span>Desconto (%)</span>
                    <input v-model.number="cart.descontoPercentual.value" type="number" min="0" max="100" step="0.01" class="w-full rounded border px-3 py-2" />
                    <small v-if="!cart.descontoValido.value" class="text-red-600">O desconto deve estar entre 0% e 100%.</small>
                </label>
                <fieldset class="space-y-1">
                    <legend>Forma de pagamento</legend>
                    <label v-for="f in formas" :key="f.value" class="flex items-center gap-2">
                        <input v-model="forma" type="radio" :value="f.value" /> {{ f.label }}
                    </label>
                </fieldset>
                <label v-if="forma === 'dinheiro'" class="block">
                    <span>Valor recebido (R$)</span>
                    <input v-model="recebido" inputmode="decimal" placeholder="0,00" class="w-full rounded border px-3 py-2" />
                    <small class="block">Troco: {{ formatarCentavos(cart.troco(recebidoCentavos)) }}</small>
                </label>
                <button
                    type="button"
                    :disabled="!podeFinalizar"
                    class="w-full rounded bg-primary px-4 py-3 text-lg text-primary-foreground disabled:opacity-50"
                    @click="finalizar"
                >
                    Finalizar venda · {{ formatarCentavos(cart.total.value) }}
                </button>

                <h2 class="pt-4 font-semibold">Últimas vendas</h2>
                <ul class="divide-y rounded border text-sm">
                    <li v-for="v in ultimas_vendas" :key="v.id" class="flex items-center justify-between gap-2 p-2">
                        <span :class="{ 'line-through opacity-60': v.status === 'cancelada' }">
                            #{{ v.id }} · {{ formatarCentavos(v.total_centavos) }} · {{ v.forma_pagamento }}
                        </span>
                        <button v-if="v.status === 'concluida'" type="button" class="text-red-600" @click="cancelarVenda(v.id)">
                            {{ confirmandoCancelamento === v.id ? 'Confirmar?' : 'Cancelar' }}
                        </button>
                        <span v-else class="text-muted-foreground">cancelada</span>
                    </li>
                    <li v-if="ultimas_vendas.length === 0" class="p-2 text-muted-foreground">Nenhuma venda ainda.</li>
                </ul>
            </aside>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 8: Build e suíte PHP**

Run: `./vendor/bin/sail npm run build && ./vendor/bin/sail pest tests/Feature/PdvHttpTest.php`
Expected: build sem erros de TypeScript; `15 passed`.

- [ ] **Step 9: Verificação manual no navegador**

1. `./vendor/bin/sail artisan tinker --execute="App\Models\User::factory()->gerente()->create(['email'=>'gerente@vendix.test'])"` (senha `password`).
2. Entrar em `http://localhost/login`, cadastrar uma categoria e um produto, dar entrada de 10 unidades.
3. Ir em `/caixa`, abrir com R$ 100,00, passar o código de barras no PDV, finalizar em dinheiro com R$ 50,00 e conferir o troco.
4. Cancelar a venda (dois cliques) e conferir que o estoque voltou em `/gerente/produtos`.

Expected: todos os passos funcionam sem erro no console do navegador.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: tela do PDV com carrinho, leitor de código de barras e testes Vitest"
```

---

### Task 11: Painel do gerente (estoque mínimo) e relatórios

**Files:**
- Create: `app/Services/ReportService.php`
- Create: `app/Http/Controllers/Gerente/DashboardController.php`, `app/Http/Controllers/Gerente/ReportController.php`
- Create: `resources/js/pages/gerente/Dashboard.vue`, `resources/js/pages/gerente/Relatorios.vue`
- Modify: `routes/web.php` (prefixo `gerente`)
- Test: `tests/Feature/ReportsTest.php`

**Interfaces:**
- Consumes: `Product::abaixoDoMinimo()` (Task 3); `Sale`, `SaleItem`, `SaleStatus`, `SaleFactory` (Task 6); Gate `gerenciar` (Task 2); `formatarCentavos` (Task 5).
- Produces:
  - `ReportService::vendasPorDiaEOperador(CarbonInterface $de, CarbonInterface $ate): Collection<int, array{dia: string, operador: string, quantidade: int, total_centavos: int}>` (só concluídas, datas inclusivas, ordenado por dia e operador).
  - `ReportService::maisVendidos(CarbonInterface $de, CarbonInterface $ate, int $limite = 10): Collection<int, array{product_id: int, nome: string, quantidade: int, total_centavos: int}>` (só concluídas, ordem decrescente de quantidade).
  - Rotas `GET /gerente` (`gerente.dashboard`) e `GET /gerente/relatorios?de=YYYY-MM-DD&ate=YYYY-MM-DD` (`gerente.relatorios`; padrão: últimos 7 dias).

- [ ] **Step 1: Escrever os testes**

`tests/Feature/ReportsTest.php`:
```php
<?php

use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gerente = User::factory()->gerente()->create();
});

it('bloqueia operador no painel e nos relatórios', function () {
    $operador = User::factory()->create();

    $this->actingAs($operador)->get('/gerente')->assertForbidden();
    $this->actingAs($operador)->get('/gerente/relatorios')->assertForbidden();
});

it('painel lista produtos abaixo do estoque mínimo', function () {
    Product::factory()->create(['nome' => 'Leite', 'estoque_atual' => 1, 'estoque_minimo' => 10]);
    Product::factory()->create(['nome' => 'Arroz', 'estoque_atual' => 50, 'estoque_minimo' => 10]);

    $this->actingAs($this->gerente)->get('/gerente')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gerente/Dashboard')
            ->has('abaixo_do_minimo', 1)
            ->where('abaixo_do_minimo.0.nome', 'Leite'));
});

it('agrupa vendas concluídas por dia e operador dentro do período', function () {
    $ana = User::factory()->create(['name' => 'Ana']);
    $bia = User::factory()->create(['name' => 'Bia']);
    $sessaoAna = CashRegisterSession::factory()->for($ana)->create();
    $sessaoBia = CashRegisterSession::factory()->for($bia)->create();

    $dia1 = Carbon::parse('2026-10-10 10:00');
    $dia2 = Carbon::parse('2026-10-11 15:00');

    Sale::factory()->cartao(1000)->create(['cash_register_session_id' => $sessaoAna->id, 'user_id' => $ana->id, 'created_at' => $dia1]);
    Sale::factory()->cartao(2000)->create(['cash_register_session_id' => $sessaoAna->id, 'user_id' => $ana->id, 'created_at' => $dia1]);
    Sale::factory()->cartao(500)->create(['cash_register_session_id' => $sessaoBia->id, 'user_id' => $bia->id, 'created_at' => $dia2]);
    Sale::factory()->cartao(9999)->cancelada()->create(['cash_register_session_id' => $sessaoBia->id, 'user_id' => $bia->id, 'created_at' => $dia2]);
    Sale::factory()->cartao(7777)->create(['cash_register_session_id' => $sessaoBia->id, 'user_id' => $bia->id, 'created_at' => Carbon::parse('2026-10-20')]);

    $linhas = app(ReportService::class)->vendasPorDiaEOperador(Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11'));

    expect($linhas->all())->toBe([
        ['dia' => '2026-10-10', 'operador' => 'Ana', 'quantidade' => 2, 'total_centavos' => 3000],
        ['dia' => '2026-10-11', 'operador' => 'Bia', 'quantidade' => 1, 'total_centavos' => 500],
    ]);
});

it('ranqueia produtos mais vendidos ignorando vendas canceladas', function () {
    $arroz = Product::factory()->create(['nome' => 'Arroz']);
    $feijao = Product::factory()->create(['nome' => 'Feijão']);
    $hoje = Carbon::parse('2026-10-15 12:00');

    $venda1 = Sale::factory()->cartao(0)->create(['created_at' => $hoje]);
    $venda1->items()->create(['product_id' => $arroz->id, 'quantidade' => 2, 'preco_unitario_centavos' => 1000, 'subtotal_centavos' => 2000]);
    $venda1->items()->create(['product_id' => $feijao->id, 'quantidade' => 5, 'preco_unitario_centavos' => 800, 'subtotal_centavos' => 4000]);

    $cancelada = Sale::factory()->cartao(0)->cancelada()->create(['created_at' => $hoje]);
    $cancelada->items()->create(['product_id' => $arroz->id, 'quantidade' => 50, 'preco_unitario_centavos' => 1000, 'subtotal_centavos' => 50000]);

    $ranking = app(ReportService::class)->maisVendidos(Carbon::parse('2026-10-15'), Carbon::parse('2026-10-15'));

    expect($ranking->all())->toBe([
        ['product_id' => $feijao->id, 'nome' => 'Feijão', 'quantidade' => 5, 'total_centavos' => 4000],
        ['product_id' => $arroz->id, 'nome' => 'Arroz', 'quantidade' => 2, 'total_centavos' => 2000],
    ]);
});

it('tela de relatórios aceita período e valida datas', function () {
    $this->actingAs($this->gerente)->get('/gerente/relatorios?de=2026-10-01&ate=2026-10-15')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gerente/Relatorios')
            ->where('de', '2026-10-01')
            ->where('ate', '2026-10-15')
            ->has('vendas_por_dia')
            ->has('mais_vendidos'));

    $this->actingAs($this->gerente)->get('/gerente/relatorios?de=2026-10-15&ate=2026-10-01')
        ->assertSessionHasErrors('ate');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/ReportsTest.php`
Expected: FAIL (`Class "App\Services\ReportService" not found` / 404).

- [ ] **Step 3: Service**

`app/Services/ReportService.php`:
```php
<?php

namespace App\Services;

use App\Enums\SaleStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /** @return Collection<int, array{dia: string, operador: string, quantidade: int, total_centavos: int}> */
    public function vendasPorDiaEOperador(CarbonInterface $de, CarbonInterface $ate): Collection
    {
        return DB::table('sales')
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->where('sales.status', SaleStatus::Concluida->value)
            ->whereBetween('sales.created_at', [$de->copy()->startOfDay(), $ate->copy()->endOfDay()])
            ->selectRaw('DATE(sales.created_at) as dia, users.name as operador, COUNT(*) as quantidade, SUM(sales.total_centavos) as total_centavos')
            ->groupByRaw('DATE(sales.created_at), users.name')
            ->orderBy('dia')
            ->orderBy('operador')
            ->get()
            ->map(fn ($l) => [
                'dia' => (string) $l->dia,
                'operador' => (string) $l->operador,
                'quantidade' => (int) $l->quantidade,
                'total_centavos' => (int) $l->total_centavos,
            ]);
    }

    /** @return Collection<int, array{product_id: int, nome: string, quantidade: int, total_centavos: int}> */
    public function maisVendidos(CarbonInterface $de, CarbonInterface $ate, int $limite = 10): Collection
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', SaleStatus::Concluida->value)
            ->whereBetween('sales.created_at', [$de->copy()->startOfDay(), $ate->copy()->endOfDay()])
            ->selectRaw('products.id as product_id, products.nome as nome, SUM(sale_items.quantidade) as quantidade, SUM(sale_items.subtotal_centavos) as total_centavos')
            ->groupBy('products.id', 'products.nome')
            ->orderByDesc('quantidade')
            ->limit($limite)
            ->get()
            ->map(fn ($l) => [
                'product_id' => (int) $l->product_id,
                'nome' => (string) $l->nome,
                'quantidade' => (int) $l->quantidade,
                'total_centavos' => (int) $l->total_centavos,
            ]);
    }
}
```

- [ ] **Step 4: Controllers e rotas**

`app/Http/Controllers/Gerente/DashboardController.php`:
```php
<?php

namespace App\Http\Controllers\Gerente;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ReportService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(ReportService $relatorios): Response
    {
        Gate::authorize('gerenciar');

        return Inertia::render('gerente/Dashboard', [
            'abaixo_do_minimo' => Product::abaixoDoMinimo()
                ->with('category:id,nome')
                ->orderBy('nome')
                ->get(['id', 'nome', 'codigo_barras', 'category_id', 'estoque_atual', 'estoque_minimo']),
            'vendas_hoje' => $relatorios->vendasPorDiaEOperador(today(), today()),
        ]);
    }
}
```

`app/Http/Controllers/Gerente/ReportController.php`:
```php
<?php

namespace App\Http\Controllers\Gerente;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, ReportService $relatorios): Response
    {
        Gate::authorize('gerenciar');

        $dados = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:de'],
        ]);

        $de = isset($dados['de']) ? Carbon::parse($dados['de']) : today()->subDays(6);
        $ate = isset($dados['ate']) ? Carbon::parse($dados['ate']) : today();

        return Inertia::render('gerente/Relatorios', [
            'de' => $de->toDateString(),
            'ate' => $ate->toDateString(),
            'vendas_por_dia' => $relatorios->vendasPorDiaEOperador($de, $ate),
            'mais_vendidos' => $relatorios->maisVendidos($de, $ate),
        ]);
    }
}
```

Em `routes/web.php`, adicionar os imports
```php
use App\Http\Controllers\Gerente\DashboardController;
use App\Http\Controllers\Gerente\ReportController;
```
e, **dentro** de `Route::prefix('gerente')->name('gerente.')->group(...)`:
```php
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('relatorios', ReportController::class)->name('relatorios');
```

- [ ] **Step 5: Páginas Vue**

`resources/js/pages/gerente/Dashboard.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatarCentavos } from '@/lib/dinheiro';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    abaixo_do_minimo: { id: number; nome: string; codigo_barras: string; estoque_atual: number; estoque_minimo: number; category: { nome: string } }[];
    vendas_hoje: { dia: string; operador: string; quantidade: number; total_centavos: number }[];
}>();

const totalHoje = computed(() => props.vendas_hoje.reduce((s, l) => s + l.total_centavos, 0));
</script>

<template>
    <Head title="Painel do gerente" />
    <AppLayout>
        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-xl font-semibold">Painel do gerente</h1>
                <nav class="flex gap-2">
                    <Link href="/gerente/produtos" class="rounded border px-3 py-2">Produtos</Link>
                    <Link href="/gerente/relatorios" class="rounded border px-3 py-2">Relatórios</Link>
                </nav>
            </div>

            <section class="rounded border p-4">
                <h2 class="font-semibold">Vendas de hoje: {{ formatarCentavos(totalHoje) }}</h2>
                <ul class="text-sm">
                    <li v-for="l in vendas_hoje" :key="l.operador">{{ l.operador }}: {{ l.quantidade }} venda(s) · {{ formatarCentavos(l.total_centavos) }}</li>
                </ul>
            </section>

            <section class="space-y-2">
                <h2 class="font-semibold">Estoque abaixo do mínimo ({{ abaixo_do_minimo.length }})</h2>
                <p v-if="abaixo_do_minimo.length === 0" class="text-muted-foreground">Nenhum produto abaixo do mínimo.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b"><th class="p-2">Produto</th><th class="p-2">Categoria</th><th class="p-2 text-right">Atual</th><th class="p-2 text-right">Mínimo</th><th class="p-2"></th></tr></thead>
                        <tbody>
                            <tr v-for="p in abaixo_do_minimo" :key="p.id" class="border-b">
                                <td class="p-2">{{ p.nome }}</td>
                                <td class="p-2">{{ p.category.nome }}</td>
                                <td class="p-2 text-right font-semibold text-red-600">{{ p.estoque_atual }}</td>
                                <td class="p-2 text-right">{{ p.estoque_minimo }}</td>
                                <td class="p-2 text-right"><Link :href="`/gerente/produtos/${p.id}/edit`" class="underline">Repor</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
```

`resources/js/pages/gerente/Relatorios.vue`:
```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { formatarCentavos } from '@/lib/dinheiro';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    de: string;
    ate: string;
    vendas_por_dia: { dia: string; operador: string; quantidade: number; total_centavos: number }[];
    mais_vendidos: { product_id: number; nome: string; quantidade: number; total_centavos: number }[];
}>();

const page = usePage();
const erros = computed(() => page.props.errors as Record<string, string>);
const de = ref(props.de);
const ate = ref(props.ate);

function filtrar() {
    router.get('/gerente/relatorios', { de: de.value, ate: ate.value }, { preserveState: true });
}
</script>

<template>
    <Head title="Relatórios" />
    <AppLayout>
        <div class="space-y-6 p-4">
            <h1 class="text-xl font-semibold">Relatórios</h1>
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="filtrar">
                <label>De <input v-model="de" type="date" class="rounded border px-2 py-1" /></label>
                <label>Até <input v-model="ate" type="date" class="rounded border px-2 py-1" /></label>
                <button class="rounded border px-3 py-1">Filtrar</button>
            </form>
            <p v-for="(m, campo) in erros" :key="campo" class="text-sm text-red-600">{{ m }}</p>

            <section class="overflow-x-auto">
                <h2 class="font-semibold">Vendas por dia e operador</h2>
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b"><th class="p-2">Dia</th><th class="p-2">Operador</th><th class="p-2 text-right">Vendas</th><th class="p-2 text-right">Total</th></tr></thead>
                    <tbody>
                        <tr v-for="l in vendas_por_dia" :key="l.dia + l.operador" class="border-b">
                            <td class="p-2">{{ new Date(l.dia + 'T12:00:00').toLocaleDateString('pt-BR') }}</td>
                            <td class="p-2">{{ l.operador }}</td>
                            <td class="p-2 text-right">{{ l.quantidade }}</td>
                            <td class="p-2 text-right">{{ formatarCentavos(l.total_centavos) }}</td>
                        </tr>
                        <tr v-if="vendas_por_dia.length === 0"><td colspan="4" class="p-2 text-muted-foreground">Sem vendas no período.</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="overflow-x-auto">
                <h2 class="font-semibold">Produtos mais vendidos</h2>
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b"><th class="p-2">#</th><th class="p-2">Produto</th><th class="p-2 text-right">Quantidade</th><th class="p-2 text-right">Faturamento</th></tr></thead>
                    <tbody>
                        <tr v-for="(p, i) in mais_vendidos" :key="p.product_id" class="border-b">
                            <td class="p-2">{{ i + 1 }}</td>
                            <td class="p-2">{{ p.nome }}</td>
                            <td class="p-2 text-right">{{ p.quantidade }}</td>
                            <td class="p-2 text-right">{{ formatarCentavos(p.total_centavos) }}</td>
                        </tr>
                        <tr v-if="mais_vendidos.length === 0"><td colspan="4" class="p-2 text-muted-foreground">Sem vendas no período.</td></tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/sail pest tests/Feature/ReportsTest.php && ./vendor/bin/sail npm run build`
Expected: `5 passed`; build sem erros.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: painel do gerente com alerta de estoque mínimo e relatórios de vendas"
```

---

### Task 12: Seeder de demonstração

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/SeederTest.php`

**Interfaces:**
- Consumes: `StockService` (Task 4), `CashRegisterService` (Task 6), `SaleService` (Task 7), factories de `User`, `Category`, `Product`.
- Produces: `./vendor/bin/sail artisan migrate:fresh --seed` cria `gerente@vendix.test` e `operador@vendix.test` (senha `password`), 5 categorias, 20 produtos (3 abaixo do mínimo), estoque abastecido por movimentos de fornecedor e 7 dias de vendas com caixas fechados.

- [ ] **Step 1: Escrever o teste**

`tests/Feature/SeederTest.php`:
```php
<?php

use App\Enums\TipoMovimento;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

it('popula dados de demonstração consistentes', function () {
    $this->seed();

    expect(User::where('email', 'gerente@vendix.test')->first()?->isGerente())->toBeTrue()
        ->and(User::where('email', 'operador@vendix.test')->exists())->toBeTrue()
        ->and(Product::count())->toBe(20)
        ->and(Product::abaixoDoMinimo()->count())->toBeGreaterThanOrEqual(3)
        ->and(Sale::count())->toBeGreaterThan(0)
        ->and(CashRegisterSession::aberta()->count())->toBe(0);

    // Auditoria: o estoque de cada produto bate com a soma dos movimentos.
    Product::with('stockMovements')->get()->each(function (Product $p) {
        $saldo = $p->stockMovements->sum(fn ($m) => $m->tipo === TipoMovimento::Entrada ? $m->quantidade : -$m->quantidade);
        expect($p->estoque_atual)->toBe($saldo);
    });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/sail pest tests/Feature/SeederTest.php`
Expected: FAIL (o seeder padrão do kit cria só um usuário `test@example.com`).

- [ ] **Step 3: Implementar o seeder**

`database/seeders/DatabaseSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Enums\FormaPagamento;
use App\Enums\MotivoMovimento;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\SaleService;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(StockService $estoque, CashRegisterService $caixa, SaleService $vendas): void
    {
        $gerente = User::factory()->gerente()->create(['name' => 'Gerente', 'email' => 'gerente@vendix.test']);
        $operador = User::factory()->create(['name' => 'Operador', 'email' => 'operador@vendix.test']);

        $catalogo = [
            'Mercearia' => [['Arroz Branco 5kg', 2590], ['Feijão Carioca 1kg', 899], ['Açúcar Refinado 1kg', 549], ['Café Torrado 500g', 1890]],
            'Laticínios' => [['Leite Integral 1L', 589], ['Queijo Mussarela 500g', 2490], ['Iogurte Natural 170g', 349], ['Manteiga 200g', 1190]],
            'Bebidas' => [['Refrigerante Cola 2L', 999], ['Suco de Laranja 1L', 799], ['Água Mineral 1,5L', 299], ['Cerveja Lata 350ml', 459]],
            'Limpeza' => [['Detergente 500ml', 279], ['Sabão em Pó 1kg', 1490], ['Água Sanitária 2L', 699], ['Esponja Multiuso', 199]],
            'Hortifruti' => [['Banana Prata (dúzia)', 699], ['Tomate (bandeja)', 549], ['Batata (pacote 2kg)', 899], ['Cebola (pacote 1kg)', 499]],
        ];

        $codigo = 7891000100000;
        $produtos = collect();
        foreach ($catalogo as $nomeCategoria => $itens) {
            $categoria = Category::create(['nome' => $nomeCategoria]);
            foreach ($itens as [$nome, $preco]) {
                $produtos->push(Product::create([
                    'category_id' => $categoria->id,
                    'nome' => $nome,
                    'codigo_barras' => (string) $codigo++,
                    'preco_centavos' => $preco,
                    'estoque_minimo' => 10,
                ]));
            }
        }

        // Os três primeiros produtos ficam com pouco estoque para aparecer no alerta.
        $produtos->each(function (Product $p, int $i) use ($estoque, $gerente) {
            $estoque->entrada($p, $i < 3 ? 10 : 120, MotivoMovimento::Fornecedor, $gerente, observacao: 'Carga inicial');
        });

        $formas = FormaPagamento::cases();
        // Captura "hoje" antes do laço: depois do primeiro setTestNow, today() passaria a devolver o dia simulado.
        $hoje = today();
        for ($dias = 6; $dias >= 0; $dias--) {
            Carbon::setTestNow($hoje->copy()->subDays($dias)->setTime(8, 0));
            $caixa->abrir($operador, 20000);

            for ($n = 0; $n < 5; $n++) {
                Carbon::setTestNow(now()->addMinutes(37));
                $produto = $produtos[($dias * 5 + $n) % $produtos->count()];
                if ($produto->fresh()->estoque_atual < 1) {
                    continue;
                }
                $forma = $formas[$n % count($formas)];
                $total = $produto->preco_centavos;
                $vendas->finalizar(
                    $operador,
                    [['product_id' => $produto->id, 'quantidade' => 1]],
                    0,
                    $forma,
                    $forma === FormaPagamento::Dinheiro ? (int) ceil($total / 1000) * 1000 : null,
                );
            }

            Carbon::setTestNow(now()->setTime(18, 0));
            $sessao = $caixa->sessaoAberta($operador);
            $caixa->fechar($operador, $sessao ? $caixa->valorEsperado($sessao) : 0);
        }
        Carbon::setTestNow();
    }
}
```

> Os três primeiros produtos (Arroz, Feijão, Açúcar) recebem 10 unidades com mínimo 10. O laço de vendas usa os índices `(dias * 5 + n) % 20`, então os produtos 0–4 são vendidos duas vezes na semana (dias −4 e hoje): os três primeiros terminam com 8 unidades, abaixo do mínimo.

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/sail pest tests/Feature/SeederTest.php && ./vendor/bin/sail artisan migrate:fresh --seed`
Expected: `1 passed`; o seed roda sem erro no MySQL.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: seeder de demonstração com produtos, estoque e uma semana de vendas"
```

---

### Task 13: Qualidade (Pint, Larastan, ESLint), CI e documentação

**Files:**
- Create: `phpstan.neon`, `.github/workflows/ci.yml`
- Create: `docs/regras-de-negocio.md`, `docs/arquitetura.md`
- Modify: `AGENTS.md`, `README.md`, `composer.json` (dev dependency)

**Interfaces:**
- Consumes: todo o código das Tasks 1–12.
- Produces: `./vendor/bin/sail pint --test`, `./vendor/bin/sail php vendor/bin/phpstan analyse`, `./vendor/bin/sail npx eslint .`, `./vendor/bin/sail npm run test`, `./vendor/bin/sail pest` e o workflow `CI` do GitHub rodando os mesmos passos.

- [ ] **Step 1: Larastan nível 5**

```bash
./vendor/bin/sail composer require --dev larastan/larastan
```

`phpstan.neon`:
```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 5
    paths:
        - app
        - database/seeders
```

Run: `./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G`
Expected: `[OK] No errors`. Se houver erros, corrija o código apontado (tipos de retorno, generics de relação). **Não** crie baseline nem diminua o nível.

- [ ] **Step 2: Pint**

Run: `./vendor/bin/sail pint && ./vendor/bin/sail pint --test`
Expected: o segundo comando passa sem arquivos para corrigir.

- [ ] **Step 3: ESLint e Vitest**

Run: `./vendor/bin/sail npx eslint . && ./vendor/bin/sail npm run test`
Expected: ESLint sem erros (corrija o que aparecer, ex.: `vue/no-v-html` na paginação de produtos: troque `v-html="link.label"` por `{{ link.label.replace('&laquo;', '«').replace('&raquo;', '»') }}` como conteúdo do `<Link>`); Vitest `11 passed`.

- [ ] **Step 4: Workflow de CI**

`.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  qualidade:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, pdo_sqlite, bcmath
          coverage: none

      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm

      - name: Dependências PHP
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Preparar .env
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Dependências JS
        run: npm ci

      - name: Pint (formatação)
        run: vendor/bin/pint --test

      - name: PHPStan (nível 5)
        run: vendor/bin/phpstan analyse --memory-limit=1G --no-progress

      - name: Pest
        run: vendor/bin/pest

      - name: ESLint
        run: npx eslint .

      - name: Vitest
        run: npm run test

      - name: Build do frontend
        run: npm run build
```

> PHP e Node ficam no mesmo job porque o build do starter kit pode chamar `php artisan` (geração de rotas do Wayfinder) durante o `vite build`.

- [ ] **Step 5: Documentação de regras de negócio**

`docs/regras-de-negocio.md`:
```markdown
# Regras de negócio do Vendix

## Perfis
- **Operador**: abre/fecha o próprio caixa, registra vendas e cancela as próprias vendas enquanto o caixa está aberto.
- **Gerente**: tudo do operador, mais produtos, categorias, movimentação manual de estoque, painel, relatórios e cancelamento de qualquer venda.
- Cadastro público sempre cria operador. Promover a gerente é feito no banco/seeder.

## Dinheiro
- Todos os valores são guardados em centavos inteiros.
- Desconto da venda é percentual (0 a 100). `desconto = round(subtotal × % / 100)`, meio para cima. Ex.: R$ 9,99 com 10% → desconto R$ 1,00, total R$ 8,99.
- Pagamento em dinheiro exige valor recebido ≥ total; troco = recebido − total. Em cartão/pix o valor recebido é ignorado.

## Caixa
- Um caixa aberto por operador por vez.
- Fechamento: o operador informa o valor contado sem ver o esperado (conferência cega).
- Esperado = valor inicial + total das vendas **concluídas** em **dinheiro** da sessão.
- Diferença = contado − esperado (positivo = sobra, negativo = falta).

## Vendas
- Finalizar é atômico: valida estoque de todos os itens (somando linhas repetidas do mesmo produto), grava venda e itens, gera um movimento de saída por produto e baixa o estoque. Se algo falha, nada é gravado.
- O item guarda o preço do momento da venda; mudar o preço do produto depois não altera vendas antigas.
- Cancelar gera um movimento de **entrada** com motivo `estorno` para cada item. A venda fica com status `cancelada`; nada é apagado. Não é possível cancelar duas vezes.

## Estoque
- O estoque só muda via `StockService`, que sempre grava um `StockMovement` com o saldo após o movimento.
- Movimentos nunca são editados nem apagados. Correção = novo movimento de ajuste.
- Motivos: `venda` e `estorno` (só o sistema), `fornecedor` (só entrada) e `ajuste` (entrada ou saída, pelo gerente).
- Alerta de mínimo: produtos com `estoque_atual < estoque_minimo`.
```

- [ ] **Step 6: Documentação de arquitetura**

`docs/arquitetura.md`:
```markdown
# Arquitetura do Vendix

Laravel + Vue 3 (Inertia, Vue Starter Kit) + MySQL, rodando no Laravel Sail.

## Camadas
- **Controllers** (`app/Http/Controllers`): validam a requisição, autorizam (Policies / Gate `gerenciar`), chamam um service e devolvem uma página Inertia ou um redirect. Nenhuma regra de negócio aqui.
- **Services** (`app/Services`):
  - `StockService`: única porta de alteração de estoque; trava a linha do produto e grava o movimento.
  - `CashRegisterService`: abrir, fechar e calcular o esperado do caixa.
  - `SaleService`: finalizar venda (transação única) e cancelar com estorno.
  - `ReportService`: consultas agregadas de vendas.
- **Policies** (`app/Policies`): `ProductPolicy`, `CategoryPolicy`, `CashRegisterSessionPolicy`, `SalePolicy`.
- **Exceções de domínio** (`app/Exceptions`): os controllers convertem em erros de validação com mensagens em português.

## Front-end
- Páginas em `resources/js/pages` (caixa, pdv, gerente).
- `resources/js/composables/useCart.ts`: lógica do carrinho, com o mesmo cálculo de desconto do backend.
- `resources/js/lib/dinheiro.ts`: formatação e conversão de centavos.

## Concorrência
- Venda e movimentos usam `lockForUpdate` nas linhas de produto, sempre na ordem de `id`, para duas vendas simultâneas não deixarem o estoque negativo.
- A abertura de caixa trava a linha do usuário para impedir duas sessões abertas.

## Testes
- Pest (`tests/Feature`) com SQLite em memória.
- Vitest (`resources/js/**/__tests__`).
```

- [ ] **Step 7: Atualizar AGENTS.md e README.md**

Em `AGENTS.md`, substituir a seção `## Comandos` por:
```markdown
## Comandos
- Subir ambiente: `./vendor/bin/sail up -d`
- Popular dados de demo: `./vendor/bin/sail artisan migrate:fresh --seed` (gerente@vendix.test / operador@vendix.test, senha `password`)
- Testes PHP: `./vendor/bin/sail pest`
- Testes JS: `./vendor/bin/sail npm run test`
- Formatação: `./vendor/bin/sail pint`
- Análise estática: `./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G`
- Lint JS: `./vendor/bin/sail npx eslint .`
```
e, na seção `## Padrões`, trocar a linha `- Componentes Vue em PascalCase; páginas em \`resources/js/Pages\`.` por:
```markdown
- Componentes Vue em PascalCase; páginas em `resources/js/pages` (convenção do Vue Starter Kit).
- Dinheiro sempre em centavos inteiros (`*_centavos`); nunca float.
- Regras detalhadas em `docs/regras-de-negocio.md`; visão técnica em `docs/arquitetura.md`.
```

Em `README.md`, substituir a seção `## Status` por:
```markdown
## Como rodar

Pré-requisito: Docker Desktop ligado.

    ./vendor/bin/sail up -d
    ./vendor/bin/sail artisan migrate:fresh --seed
    ./vendor/bin/sail npm run dev

Acesse http://localhost e entre com `gerente@vendix.test` ou `operador@vendix.test` (senha `password`).

## Status

MVP implementado conforme a spec: perfis, produtos e estoque auditável, caixa, PDV, cancelamento com estorno, alerta de estoque mínimo e relatórios.
```

- [ ] **Step 8: Verificação final completa**

```bash
./vendor/bin/sail pint --test
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/sail pest
./vendor/bin/sail npx eslint .
./vendor/bin/sail npm run test
./vendor/bin/sail npm run build
```
Expected: todos passam. Cole a saída de cada um no relatório da task.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore: Larastan, CI no GitHub Actions e documentação de regras e arquitetura"
```

---

## Cobertura da spec

| Requisito da spec | Task |
|---|---|
| Stack (Laravel, Vue/Inertia, Vite, MySQL, Sail, Pest) | 1 |
| Perfis operador/gerente | 2 |
| Product, Category, alerta de mínimo | 3, 11 |
| StockMovement imutável/auditoria | 4 |
| CRUD de produtos e categorias (backlog 2) | 5 |
| Abertura de caixa, uma sessão por operador | 6, 8 |
| Fechamento com esperado × informado | 6, 8 |
| Venda atômica com baixa de estoque, desconto %, dinheiro/cartão/pix | 7, 9, 10 |
| Estoque insuficiente bloqueia e mostra disponível | 7, 9, 10 |
| Cancelamento com estorno via novo movimento | 7, 9 |
| Policy: operador não acessa caixa de outro | 6, 8, 9 |
| Relatórios por dia/operador e mais vendidos | 11 |
| Teste Vitest do carrinho | 10 |
| Seeder de demonstração (backlog 17) | 12 |
| Pint, PHPStan nível 5, ESLint, CI, docs | 13 |
