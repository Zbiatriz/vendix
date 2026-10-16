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
