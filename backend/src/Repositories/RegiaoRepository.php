<?php

namespace App\Repositories;

final class RegiaoRepository extends CrudRepository
{
    protected const TABELA  = 'regioes';
    protected const COLUNAS = ['nome', 'fator_preco'];
    protected const ORDEM   = 'fator_preco, nome';
    protected const ROTULO  = 'Região';
}