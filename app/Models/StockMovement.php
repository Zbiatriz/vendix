<?php

namespace App\Models;

use App\Enums\MotivoMovimento;
use App\Enums\TipoMovimento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    protected $fillable = ['product_id', 'user_id', 'sale_id', 'tipo', 'motivo', 'quantidade', 'saldo_apos', 'observacao'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimento::class,
            'motivo' => MotivoMovimento::class,
            'quantidade' => 'integer',
            'saldo_apos' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Movimentos de estoque são imutáveis. Registre um novo movimento.');
        });

        static::deleting(function () {
            throw new LogicException('Movimentos de estoque não podem ser apagados.');
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
