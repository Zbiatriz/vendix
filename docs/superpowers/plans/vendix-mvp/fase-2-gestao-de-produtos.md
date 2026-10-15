# Vendix MVP — Fase 2 — Gestão de produtos (Task 5)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Telas do gerente para cadastrar produtos e categorias e registrar entradas/ajustes de estoque.

**Architecture:** App Laravel monolítico com front em Vue 3 + Inertia (Laravel Vue Starter Kit). Regra de negócio em `app/Services`; controllers só validam, autorizam via Policies e chamam o service. Estoque só muda pelo `StockService`.

**Tech Stack:** Laravel (PHP 8.4 no container), Vue 3 + Inertia + TypeScript, Vite, MySQL (Sail), Pest, Vitest, Pint, Larastan, ESLint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-15-vendix-pdv-design.md`
**Visão geral do plano (fases, estrutura de arquivos, cobertura):** `docs/superpowers/plans/vendix-mvp/00-visao-geral.md`

**Pré-requisito:** Fase 1 concluída e com testes passando (`./vendor/bin/sail pest`).

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
