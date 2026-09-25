<?php

namespace App\Controllers;

use App\Core\Validador;
use App\Repositories\CrudRepository;
use App\Repositories\RegiaoRepository;

final class RegiaoController extends CadastroController
{
    protected function repositorio(): CrudRepository
    {
        return new RegiaoRepository();
    }

    protected function preparar(array $dados): array
    {
        return [
            'nome'        => self::texto($dados['nome'] ?? null),
            'fator_preco' => $dados['fator_preco'] ?? null,
        ];
    }

    protected function validar(array $dados, ?int $id): void
    {
        $v = (new Validador($dados))
            ->obrigatorio('nome', 'Nome')->texto('nome', 'Nome', 100)
            ->obrigatorio('fator_preco', 'Fator de preço')->numero('fator_preco', 'Fator de preço', 0.0001, 99);

        // 1,00 = sem alteração; 1,10 = +10%; 0,90 = −10%
        $v->validar();
    }
}