<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // FK para sales é criada na migration de sales (Task 6), que roda depois desta.
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->string('tipo', 10);
            $table->string('motivo', 20);
            $table->unsignedInteger('quantidade');
            $table->unsignedInteger('saldo_apos');
            $table->string('observacao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
