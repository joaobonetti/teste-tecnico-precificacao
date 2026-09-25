<?php

namespace App\Controllers;

use App\Core\Validador;
use App\Repositories\CrudRepository;
use App\Repositories\FaixaRepository;

final class FaixaController extends CadastroController
{
    protected function repositorio(): CrudRepository
    {
        return new FaixaRepository();
    }

    protected function preparar(array $dados): array
    {
        $final = $dados['quantidade_final'] ?? null;

        return [
            'descricao'            => self::texto($dados['descricao'] ?? null),
            'quantidade_inicial'   => $dados['quantidade_inicial'] ?? null,
            'quantidade_final'     => ($final === '' || $final === null) ? null : $final,  // vazio = "ou superior"
            'percentual_acrescimo' => $dados['percentual_acrescimo'] ?? 0,
        ];
    }

    protected function validar(array $dados, ?int $id): void
    {
        $v = (new Validador($dados))
            ->obrigatorio('descricao', 'Descrição')->texto('descricao', 'Descrição', 100)
            ->obrigatorio('quantidade_inicial', 'Quantidade inicial')->inteiro('quantidade_inicial', 'Quantidade inicial', 1)
            ->inteiro('quantidade_final', 'Quantidade final', 1)
            ->numero('percentual_acrescimo', 'Percentual de referência', 0, 1000);

        $inicial = $dados['quantidade_inicial'];
        $final   = $dados['quantidade_final'];

        if (!$v->temErro('quantidade_inicial') && !$v->temErro('quantidade_final')) {
            if ($final !== null && (int) $final < (int) $inicial) {
                $v->erro('quantidade_final', 'A quantidade final deve ser maior ou igual à inicial.');
            } elseif ($inicial !== null) {
                // Uma quantidade não pode cair em duas faixas ao mesmo tempo
                $conflito = (new FaixaRepository())->sobreposta((int) $inicial, $final === null ? null : (int) $final, $id);
                if ($conflito) {
                    $v->erro('quantidade_inicial', "O intervalo se sobrepõe à faixa \"{$conflito['descricao']}\".");
                }
            }
        }

        $v->validar();
    }

    /** Reativar uma faixa também não pode gerar sobreposição. */
    public function status(\App\Core\Request $request): void
    {
        if ($request->input('ativo') === true) {
            $repo  = new FaixaRepository();
            $faixa = $repo->buscarOuFalhar($request->idRota());
            $final = $faixa['quantidade_final'] === null ? null : (int) $faixa['quantidade_final'];

            if ($conflito = $repo->sobreposta((int) $faixa['quantidade_inicial'], $final, (int) $faixa['id'])) {
                throw new \App\Core\HttpException(
                    409,
                    "Não é possível ativar: o intervalo se sobrepõe à faixa \"{$conflito['descricao']}\"."
                );
            }
        }

        parent::status($request);
    }
}