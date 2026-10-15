# Vendix MVP — Fase 3 — Caixa e vendas (Tasks 6–8)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Abertura/fechamento de caixa com conferência, SaleService (venda atômica, troco, desconto, cancelamento com estorno) e as telas de caixa.

**Architecture:** App Laravel monolítico com front em Vue 3 + Inertia (Laravel Vue Starter Kit). Regra de negócio em `app/Services`; controllers só validam, autorizam via Policies e chamam o service. Estoque só muda pelo `StockService`.

**Tech Stack:** Laravel (PHP 8.4 no container), Vue 3 + Inertia + TypeScript, Vite, MySQL (Sail), Pest, Vitest, Pint, Larastan, ESLint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-15-vendix-pdv-design.md`
**Visão geral do plano (fases, estrutura de arquivos, cobertura):** `docs/superpowers/plans/vendix-mvp/00-visao-geral.md`

**Pré-requisito:** Fase 2 concluída e com testes passando (`./vendor/bin/sail pest`).

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

1. **Mesmo produto adicionado duas vezes no carrinho** — a validação de estoque precisa somar as quantidades; com estoque 5 e duas linhas de 3, a venda é recusada. (Teste na Task 7.)
2. **Cancelar uma venda já cancelada** — não pode estornar o estoque duas vezes; deve dar erro e não alterar nada. (Teste na Task 7.)
3. **Vender/cancelar em sessão fechada ou de outro operador** — finalizar venda com sessão fechada falha; operador não cancela venda de outro operador nem de sessão já fechada. (Testes nas Tasks 7 e 9.)
4. **Quantidade, desconto ou valor recebido inválidos** — quantidade ≤ 0, desconto < 0 ou > 100, ou dinheiro recebido menor que o total são recusados com mensagem de validação, sem gravar nada. (Testes nas Tasks 7 e 9.)
5. **Arredondamento de centavos com desconto** — subtotal R$ 9,99 com 10% de desconto dá desconto de R$ 1,00 (99,9 → 100 centavos) e total R$ 8,99, igual no backend e no carrinho do front. (Testes nas Tasks 7 e 10.)

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
