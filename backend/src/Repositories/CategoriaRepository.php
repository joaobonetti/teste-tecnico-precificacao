<?php

namespace App\Repositories;

final class CategoriaRepository extends CrudRepository
{
    protected const TABELA  = 'categorias_cliente';
    protected const COLUNAS = ['nome', 'descricao'];
    protected const ORDEM   = 'nome';
    protected const ROTULO  = 'Categoria';
}