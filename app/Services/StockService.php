<?php

namespace App\Services;

use App\Enums\MotivoMovimento;
use App\Enums\TipoMovimento;
use App\Exceptions\EstoqueInsuficienteException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    public function entrada(Product $product, int $quantidade, MotivoMovimento $motivo, User $user, ?int $saleId = null, ?string $observacao = null): StockMovement
    {
        return $this->movimentar($product, TipoMovimento::Entrada, $quantidade, $motivo, $user, $saleId, $observacao);
    }

    public function saida(Product $product, int $quantidade, MotivoMovimento $motivo, User $user, ?int $saleId = null, ?string $observacao = null): StockMovement
    {
        return $this->movimentar($product, TipoMovimento::Saida, $quantidade, $motivo, $user, $saleId, $observacao);
    }

    private function movimentar(Product $product, TipoMovimento $tipo, int $quantidade, MotivoMovimento $motivo, User $user, ?int $saleId, ?string $observacao): StockMovement
    {
        if ($quantidade <= 0) {
            throw new InvalidArgumentException('A quantidade deve ser maior que zero.');
        }

        return DB::transaction(function () use ($product, $tipo, $quantidade, $motivo, $user, $saleId, $observacao) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($tipo === TipoMovimento::Saida && $locked->estoque_atual < $quantidade) {
                throw new EstoqueInsuficienteException($locked, $quantidade);
            }

            $locked->estoque_atual += $tipo === TipoMovimento::Entrada ? $quantidade : -$quantidade;
            $locked->save();
            $product->estoque_atual = $locked->estoque_atual;
            $product->syncOriginalAttribute('estoque_atual');

            return StockMovement::create([
                'product_id' => $locked->id,
                'user_id' => $user->id,
                'sale_id' => $saleId,
                'tipo' => $tipo,
                'motivo' => $motivo,
                'quantidade' => $quantidade,
                'saldo_apos' => $locked->estoque_atual,
                'observacao' => $observacao,
            ]);
        });
    }
}
