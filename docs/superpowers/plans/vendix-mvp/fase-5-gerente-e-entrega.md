# Vendix MVP — Fase 5 — Painel, relatórios e entrega (Tasks 11–13)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Painel do gerente com alerta de estoque mínimo, relatórios, seeder de demonstração, Larastan, CI e documentação.

**Architecture:** App Laravel monolítico com front em Vue 3 + Inertia (Laravel Vue Starter Kit). Regra de negócio em `app/Services`; controllers só validam, autorizam via Policies e chamam o service. Estoque só muda pelo `StockService`.

**Tech Stack:** Laravel (PHP 8.4 no container), Vue 3 + Inertia + TypeScript, Vite, MySQL (Sail), Pest, Vitest, Pint, Larastan, ESLint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-15-vendix-pdv-design.md`
**Visão geral do plano (fases, estrutura de arquivos, cobertura):** `docs/superpowers/plans/vendix-mvp/00-visao-geral.md`

**Pré-requisito:** Fase 4 concluída e com testes passando (`./vendor/bin/sail pest`).

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
