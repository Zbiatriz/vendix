<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('nome');
            $table->string('codigo_barras', 14)->unique();
            $table->unsignedBigInteger('preco_centavos');
            $table->unsignedInteger('estoque_atual')->default(0);
            $table->unsignedInteger('estoque_minimo')->default(0);
            $table->timestamps();

            $table->index('nome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
