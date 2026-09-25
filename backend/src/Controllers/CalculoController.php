<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validador;
use App\Engine\MotorCalculo;
use App\Repositories\CalculoRepository;
use App\Repositories\CategoriaRepository;
use App\Repositories\FaixaRepository;
use App\Repositories\RegiaoRepository;
use App\Repositories\RegraRepository;
use App\Repositories\ServicoRepository;

/**
 * Execução do cálculo e histórico.
 *
 *   POST /api/calculos        executa, grava no histórico e devolve a memória
 *   GET  /api/calculos        últimos cálculos (resumo)
 *   GET  /api/calculos/{id}   um cálculo com a memória completa
 */
final class CalculoController
{
    /** POST { servico_id, categoria_id, regiao_id, quantidade } */
    public function calcular(Request $request): void
    {
        $entrada = $request->corpo();

        (new Validador($entrada))
            ->obrigatorio('servico_id', 'Serviço')->inteiro('servico_id', 'Serviço', 1)
            ->obrigatorio('categoria_id', 'Categoria')->inteiro('categoria_id', 'Categoria', 1)
            ->obrigatorio('regiao_id', 'Região')->inteiro('regiao_id', 'Região', 1)
            ->obrigatorio('quantidade', 'Quantidade')->inteiro('quantidade', 'Quantidade', 1)
            ->validar();

        $servicos   = new ServicoRepository();
        $categorias = new CategoriaRepository();
        $regioes    = new RegiaoRepository();
        $faixas     = new FaixaRepository();

        $servico   = $this->ativoOuFalhar($servicos->buscar((int) $entrada['servico_id']), 'Serviço');
        $categoria = $this->ativoOuFalhar($categorias->buscar((int) $entrada['categoria_id']), 'Categoria');
        $regiao    = $this->ativoOuFalhar($regioes->buscar((int) $entrada['regiao_id']), 'Região');
        $quantidade = (int) $entrada['quantidade'];

        $resultado = (new MotorCalculo())->calcular([
            'servico'    => $servico,
            'categoria'  => $categoria,
            'regiao'     => $regiao,
            'quantidade' => $quantidade,
            'faixa'      => MotorCalculo::identificarFaixa($quantidade, $faixas->listar(true)),
            'regras'     => (new RegraRepository())->ativasParaCalculo(),
            'nomes'      => [
                'SERVICO'   => $servicos->mapaDeNomes(),
                'CATEGORIA' => $categorias->mapaDeNomes(),
                'REGIAO'    => $regioes->mapaDeNomes(),
                'FAIXA'     => $faixas->mapaDeNomes(),
            ],
        ]);

        $resultado = ['id' => (new CalculoRepository())->salvar($resultado, Auth::idUsuario())] + $resultado;

        Response::criado($resultado);
    }

    public function listar(Request $request): void
    {
        $limite = (int) $request->query('limite', 50);
        Response::sucesso((new CalculoRepository())->listar(max(1, min($limite, 200))));
    }

    public function buscar(Request $request): void
    {
        Response::sucesso((new CalculoRepository())->buscarOuFalhar($request->idRota()));
    }

    /** Cadastro inexistente → 422; inativo → 422 (não pode ser usado em novos cálculos). */
    private function ativoOuFalhar(?array $registro, string $rotulo): array
    {
        if ($registro === null) {
            throw new HttpException(422, "{$rotulo} não encontrado(a).");
        }
        if (!$registro['ativo']) {
            throw new HttpException(422, "{$rotulo} \"" . ($registro['nome'] ?? '') . '" está inativo(a) e não pode ser usado(a) em cálculos.');
        }

        return $registro;
    }
}