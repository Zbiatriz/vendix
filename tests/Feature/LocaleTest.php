<?php

it('usa pt_BR como idioma da aplicação', function () {
    expect(app()->getLocale())->toBe('pt_BR');
});

it('retorna mensagens de validação em português', function () {
    $this->post('/login', ['email' => '', 'password' => ''])
        ->assertSessionHasErrors(['email' => 'O campo email é obrigatório.']);
});
