<?php

namespace App\Enums;

enum MotivoMovimento: string
{
    case Venda = 'venda';
    case Estorno = 'estorno';
    case Ajuste = 'ajuste';
    case Fornecedor = 'fornecedor';
}
