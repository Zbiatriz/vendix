<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('cria usuários como operador por padrão', function () {
    expect(User::factory()->create()->role)->toBe(UserRole::Operador);
});

it('cria gerente pela factory', function () {
    expect(User::factory()->gerente()->create()->isGerente())->toBeTrue();
});

it('cadastro público sempre gera operador, mesmo enviando role', function () {
    $this->post('/register', [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'gerente',
    ]);

    expect(User::where('email', 'ana@example.com')->firstOrFail()->role)->toBe(UserRole::Operador);
});

it('só gerente passa no gate gerenciar', function () {
    $operador = User::factory()->create();
    $gerente = User::factory()->gerente()->create();

    expect(Gate::forUser($operador)->allows('gerenciar'))->toBeFalse()
        ->and(Gate::forUser($gerente)->allows('gerenciar'))->toBeTrue();
});
