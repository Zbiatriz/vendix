# Instruções para agentes de IA

## Sobre o projeto
Vendix é um sistema de Frente de Caixa (PDV) para supermercados: cadastro de
produtos, controle de estoque, abertura/fechamento de caixa e registro de
vendas. Stack: Laravel + Vue 3 (Inertia) + MySQL. Ambiente via Laravel Sail.

Projeto de preparação para a Imersão Exponencial 2026. O design completo
está em `docs/superpowers/specs/2026-10-15-vendix-pdv-design.md` — leia
antes de implementar qualquer coisa.

## Comandos
- Subir ambiente: `./vendor/bin/sail up -d`
- Testes PHP: `./vendor/bin/sail pest`
- Testes JS: `npm run test`
- Formatação: `./vendor/bin/sail pint`
- Análise estática: `./vendor/bin/sail phpstan analyse`

## Padrões
- Regras de negócio ficam em `app/Services`, nunca no controller.
- Permissões sempre via Policies.
- Toda feature nova precisa de teste em `tests/Feature`.
- Componentes Vue em PascalCase; páginas em `resources/js/Pages`.
- Mensagens da interface em português.
- `StockMovement` nunca é editado ou apagado diretamente — correções sempre
  geram um novo movimento (auditoria).

## Não fazer
- Não alterar migrations já executadas; crie uma nova.
- Não commitar `.env` nem credenciais.
- Não rodar comandos contra o banco de produção.
- Não expandir escopo para compras/fornecedores, emissão fiscal, promoções
  complexas ou múltiplas lojas — isso está fora do MVP (ver spec).

## Definição de pronto
Pint, PHPStan, Pest, ESLint e Vitest passando no CI.
