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
        ->and($produto->isDirty('estoque_atual'))->toBeFalse()
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
