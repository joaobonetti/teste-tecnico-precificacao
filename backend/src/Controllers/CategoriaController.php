<?php

namespace App\Controllers;

use App\Core\Validador;
use App\Repositories\CategoriaRepository;
use App\Repositories\CrudRepository;

final class CategoriaController extends CadastroController
{
    protected function repositorio(): CrudRepository
    {
        return new CategoriaRepository();
    }

    protected function preparar(array $dados): array
    {
        return [
            'nome'      => self::texto($dados['nome'] ?? null),
            'descricao' => self::texto($dados['descricao'] ?? null),
        ];
    }

    protected function validar(array $dados, ?int $id): void
    {
        (new Validador($dados))
            ->obrigatorio('nome', 'Nome')->texto('nome', 'Nome', 100)
            ->texto('descricao', 'Descrição', 255)
            ->validar();
    }
}