<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['nome' => fake()->unique()->word()];
    }
}
