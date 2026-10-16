<?php

namespace App\Enums;

enum UserRole: string
{
    case Operador = 'operador';
    case Gerente = 'gerente';

    public function label(): string
    {
        return match ($this) {
            self::Operador => 'Operador de caixa',
            self::Gerente => 'Gerente',
        };
    }
}
