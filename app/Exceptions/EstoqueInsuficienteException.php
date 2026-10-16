<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

class EstoqueInsuficienteException extends RuntimeException
{
    public function __construct(public readonly Product $product, public readonly int $solicitado)
    {
        parent::__construct(sprintf(
            'Estoque insuficiente para %s: disponível %d, solicitado %d.',
            $product->nome,
            $product->estoque_atual,
            $solicitado,
        ));
    }
}
