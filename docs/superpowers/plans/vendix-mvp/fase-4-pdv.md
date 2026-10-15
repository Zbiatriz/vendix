# Vendix MVP — Fase 4 — PDV (Tasks 9–10)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tela de venda completa: busca por código de barras, carrinho, pagamento, cancelamento autorizado por policy e testes Vitest.

**Architecture:** App Laravel monolítico com front em Vue 3 + Inertia (Laravel Vue Starter Kit). Regra de negócio em `app/Services`; controllers só validam, autorizam via Policies e chamam o service. Estoque só muda pelo `StockService`.

**Tech Stack:** Laravel (PHP 8.4 no container), Vue 3 + Inertia + TypeScript, Vite, MySQL (Sail), Pest, Vitest, Pint, Larastan, ESLint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-15-vendix-pdv-design.md`
**Visão geral do plano (fases, estrutura de arquivos, cobertura):** `docs/superpowers/plans/vendix-mvp/00-visao-geral.md`

**Pré-requisito:** Fase 3 concluída e com testes passando (`./vendor/bin/sail pest`).

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

3. **Vender/cancelar em sessão fechada ou de outro operador** — finalizar venda com sessão fechada falha; operador não cancela venda de outro operador nem de sessão já fechada. (Testes nas Tasks 7 e 9.)
4. **Quantidade, desconto ou valor recebido inválidos** — quantidade ≤ 0, desconto < 0 ou > 100, ou dinheiro recebido menor que o total são recusados com mensagem de validação, sem gravar nada. (Testes nas Tasks 7 e 9.)
5. **Arredondamento de centavos com desconto** — subtotal R$ 9,99 com 10% de desconto dá desconto de R$ 1,00 (99,9 → 100 centavos) e total R$ 8,99, igual no backend e no carrinho do front. (Testes nas Tasks 7 e 10.)

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
