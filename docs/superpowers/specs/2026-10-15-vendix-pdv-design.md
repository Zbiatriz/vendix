# Vendix — Design do MVP (PDV de Supermercado)

> Spec gerada a partir de brainstorming em 2026-10-15, como preparação para a
> Imersão Exponencial 2026.

## Contexto e objetivo

Vendix é um sistema de Frente de Caixa (PDV) para supermercados. Não é um ERP
completo: o recorte do MVP é o fluxo de venda (caixa), apoiado por cadastro de
produtos e controle de estoque. O objetivo não é ter um sistema enorme, e sim
um projeto **organizado, versionado, testável e documentado**, pronto para
receber um squad de agentes de IA (Dev, QA, Code Review, Doc, DBA, UX/UI)
durante os 2 dias de imersão.

## Stack

| Camada | Ferramenta |
|---|---|
| Backend | Laravel (PHP 8.3+) |
| Frontend | Vue 3 + Inertia.js |
| Build JS | Vite |
| Banco | MySQL |
| Testes PHP | Pest |
| Testes JS | Vitest |
| Formatação PHP | Laravel Pint |
| Análise estática | PHPStan / Larastan (nível 5) |
| Lint JS | ESLint |
| Ambiente | Docker (Laravel Sail) |
| CI/CD | GitHub Actions |

Regra de negócio sempre em `app/Services`, nunca no controller. Permissões
sempre via `Policies`.

## Entidades principais

- **User** — perfis: `operador` (caixa) e `gerente`.
- **Product** — nome, código de barras (EAN), preço, categoria, estoque
  atual, estoque mínimo.
- **Category** — categoria do produto.
- **CashRegisterSession** — sessão de caixa: operador, valor inicial, valor
  final informado, valor final calculado, horário de abertura/fechamento.
- **Sale** — venda: sessão de caixa, operador, total, desconto, forma de
  pagamento, status (`concluida` / `cancelada`).
- **SaleItem** — item da venda: produto, quantidade, preço unitário no
  momento da venda (snapshot — não referencia o preço atual do produto).
- **StockMovement** — histórico de entrada/saída de estoque (venda, ajuste
  manual, entrada de fornecedor, estorno), sempre gerado automaticamente —
  nunca editado diretamente. É o registro de auditoria do estoque.

## Fluxos centrais

1. **Abrir caixa** — operador informa valor inicial em dinheiro. Só pode
   haver uma `CashRegisterSession` aberta por operador por vez.
2. **Registrar venda** — operador busca produto (código de barras ou nome),
   adiciona ao carrinho, aplica desconto opcional (%), escolhe forma de
   pagamento (dinheiro/cartão/pix) e finaliza. A finalização roda em uma
   única transação de banco:
   1. valida estoque suficiente para cada item;
   2. cria `Sale` + `SaleItems`;
   3. gera `StockMovement` de saída para cada item;
   4. decrementa `Product.estoque_atual`.
3. **Fechar caixa** — operador informa valor contado; o sistema calcula o
   valor esperado (inicial + vendas em dinheiro da sessão) e mostra a
   diferença (sobra/falta).
4. **Alerta de estoque mínimo** — painel do gerente lista produtos com
   `estoque_atual < estoque_minimo`.
5. **Relatórios** — vendas por dia/operador, produtos mais vendidos.

## Tratamento de erros

- Venda com estoque insuficiente: bloqueia a adição do item, mostra o
  estoque disponível ao operador.
- Fechar caixa sem sessão aberta: erro explícito, não deixa fechar.
- Cancelamento de venda: reverte o estoque através de um **novo**
  `StockMovement` de entrada — nunca apaga ou edita o movimento original
  (auditoria completa).

## Estrutura de pastas

```
vendix/
├── app/
│   ├── Http/Controllers/
│   ├── Models/
│   ├── Services/          # SaleService, CashRegisterService, StockService
│   └── Policies/
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/           # produtos e usuários fictícios para staging
├── resources/js/
│   ├── Pages/             # telas Vue (Inertia): PDV, Caixa, Produtos, Relatórios
│   └── Components/        # Carrinho, LeitorCodigoBarras, etc.
├── tests/
│   ├── Feature/           # Pest
│   └── Unit/
├── docs/
│   ├── arquitetura.md
│   └── regras-de-negocio.md
├── .github/workflows/ci.yml
├── AGENTS.md
├── README.md
└── phpstan.neon
```

## Plano de testes (definição de pronto)

**Pest (PHP)**
- Abertura de caixa (sucesso e tentativa de abrir 2 sessões simultâneas).
- Venda completa: decrementa estoque corretamente, gera `StockMovement`.
- Venda com estoque insuficiente: bloqueia e não altera nada.
- Cancelamento de venda: reverte estoque via novo movimento.
- Fechamento de caixa: cálculo correto de esperado vs. informado.
- Policy: operador não acessa sessão de caixa de outro operador.

**Vitest (JS)**
- Componente de carrinho: adicionar/remover item, cálculo de total com
  desconto.

**CI (GitHub Actions)**
- Pint (formatação) → PHPStan nível 5 (análise estática) → Pest (testes) →
  ESLint + Vitest + build (frontend).

## Backlog inicial (para criar como issues)

**Features**
1. Cadastro e login com perfis (operador/gerente)
2. CRUD de produtos e categorias (gerente)
3. Abertura de caixa
4. Tela de venda (busca produto, carrinho, desconto, pagamento)
5. Finalização de venda com baixa de estoque
6. Fechamento de caixa com conferência
7. Cancelamento de venda com estorno de estoque
8. Alerta de estoque mínimo (painel do gerente)
9. Relatório de vendas por dia/operador
10. Relatório de produtos mais vendidos

**Qualidade**
11. Testes de feature para abertura/fechamento de caixa
12. Testes de venda com estoque insuficiente
13. Testes de policy (isolamento entre operadores)
14. Testes Vitest do componente de carrinho

**Documentação e infraestrutura**
15. `docs/regras-de-negocio.md` com as regras acima
16. Pipeline de CI completo
17. Seeder com produtos e vendas fictícias para demonstração

## Fora de escopo (explicitamente, para não crescer demais)

- Módulo de compras/fornecedores
- Emissão fiscal (NFC-e)
- Promoções complexas (combos, leve 3 pague 2)
- Múltiplas lojas/filiais
