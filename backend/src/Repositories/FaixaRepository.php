<?php

namespace App\Repositories;

/**
 * Faixas de utilização. quantidade_final NULL = faixa aberta ("101 ou superior").
 */
final class FaixaRepository extends CrudRepository
{
    protected const TABELA  = 'faixas_utilizacao';
    protected const COLUNAS = ['descricao', 'quantidade_inicial', 'quantidade_final', 'percentual_acrescimo'];
    protected const ORDEM   = 'quantidade_inicial';
    protected const ROTULO  = 'Faixa';

    /**
     * Existe outra faixa ATIVA cujo intervalo cruza [inicial, final]?
     * Duas faixas se sobrepõem quando: inicioA <= fimB  E  inicioB <= fimA
     * (fim NULL = infinito). Retorna a faixa conflitante ou null.
     */
    public function sobreposta(int $inicial, ?int $final, ?int $ignorarId = null): ?array
    {
        $sql = 'SELECT * FROM faixas_utilizacao
                WHERE ativo = 1
                  AND (:ignorar IS NULL OR id <> :ignorar2)
                  AND quantidade_inicial <= COALESCE(:final, 2147483647)
                  AND COALESCE(quantidade_final, 2147483647) >= :inicial
                LIMIT 1';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'ignorar'  => $ignorarId,
            'ignorar2' => $ignorarId,
            'final'    => $final,
            'inicial'  => $inicial,
        ]);
        $linha = $stmt->fetch();

        return $linha ? $this->formatar($linha) : null;
    }

    public function mapaDeNomes(string $colunaNome = 'descricao'): array
    {
        return parent::mapaDeNomes($colunaNome);
    }
}