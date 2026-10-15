# Vendix MVP — Visão geral do plano

O plano de implementação do MVP está dividido em 5 fases. Cada fase é um plano independente que termina com software funcionando e testado; execute-as em ordem. A numeração das tasks é global (1–13), então referências como "Task 6" valem entre os arquivos.

**Spec:** `docs/superpowers/specs/2026-10-15-vendix-pdv-design.md`

| Fase | Tasks | Arquivo | Entrega |
|---|---|---|---|
| Fase 1 — Fundação | Tasks 1–4 | `fase-1-fundacao.md` | Projeto Laravel + Vue rodando no Sail, perfis operador/gerente, produtos/categorias e o StockService com movimentos de estoque imutáveis. |
| Fase 2 — Gestão de produtos | Task 5 | `fase-2-gestao-de-produtos.md` | Telas do gerente para cadastrar produtos e categorias e registrar entradas/ajustes de estoque. |
| Fase 3 — Caixa e vendas | Tasks 6–8 | `fase-3-caixa-e-vendas.md` | Abertura/fechamento de caixa com conferência, SaleService (venda atômica, troco, desconto, cancelamento com estorno) e as telas de caixa. |
| Fase 4 — PDV | Tasks 9–10 | `fase-4-pdv.md` | Tela de venda completa: busca por código de barras, carrinho, pagamento, cancelamento autorizado por policy e testes Vitest. |
| Fase 5 — Painel, relatórios e entrega | Tasks 11–13 | `fase-5-gerente-e-entrega.md` | Painel do gerente com alerta de estoque mínimo, relatórios, seeder de demonstração, Larastan, CI e documentação. |

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
