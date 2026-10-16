<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    // estoque_atual fica fora do fillable: só o StockService altera o estoque.
    protected $fillable = ['category_id', 'nome', 'codigo_barras', 'preco_centavos', 'estoque_minimo'];

    protected function casts(): array
    {
        return [
            'preco_centavos' => 'integer',
            'estoque_atual' => 'integer',
            'estoque_minimo' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @param Builder<Product> $query */
    public function scopeAbaixoDoMinimo(Builder $query): void
    {
        $query->whereColumn('estoque_atual', '<', 'estoque_minimo');
    }

    /** @param Builder<Product> $query */
    public function scopeBusca(Builder $query, string $termo): void
    {
        $termo = trim($termo);

        $query->where(function (Builder $q) use ($termo) {
            $q->where('codigo_barras', $termo)
                ->orWhere('nome', 'like', '%'.$termo.'%');
        });
    }
}
