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
