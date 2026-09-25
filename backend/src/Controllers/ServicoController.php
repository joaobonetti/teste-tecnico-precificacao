<?php

namespace App\Controllers;

use App\Core\Validador;
use App\Repositories\CrudRepository;
use App\Repositories\ServicoRepository;

final class ServicoController extends CadastroController
{
    protected function repositorio(): CrudRepository
    {
        return new ServicoRepository();
    }

    protected function preparar(array $dados): array
    {
        return [
            'nome'       => self::texto($dados['nome'] ?? null),
            'descricao'  => self::texto($dados['descricao'] ?? null),
            'valor_base' => $dados['valor_base'] ?? null,
        ];
    }

    protected function validar(array $dados, ?int $id): void
    {
        (new Validador($dados))
            ->obrigatorio('nome', 'Nome')->texto('nome', 'Nome', 100)
            ->texto('descricao', 'Descrição', 255)
            ->obrigatorio('valor_base', 'Valor base')->numero('valor_base', 'Valor base', 0, 9999999999)
            ->validar();
    }
}