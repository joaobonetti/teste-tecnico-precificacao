<?php

namespace App\Repositories;

final class ServicoRepository extends CrudRepository
{
    protected const TABELA  = 'servicos';
    protected const COLUNAS = ['nome', 'descricao', 'valor_base'];
    protected const ORDEM   = 'nome';
    protected const ROTULO  = 'Serviço';
}