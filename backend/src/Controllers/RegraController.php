<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validador;
use App\Engine\AplicadorAcao;
use App\Engine\AvaliadorCondicao;
use App\Engine\Operadores;
use App\Repositories\CategoriaRepository;
use App\Repositories\FaixaRepository;
use App\Repositories\RegiaoRepository;
use App\Repositories\RegraRepository;
use App\Repositories\ServicoRepository;

/**
 * Cadastro, alteração, ativação/desativação e auditoria das REGRAS.
 *
 *   GET    /api/regras/metadados       campos, operadores e tipos disponíveis
 *   GET    /api/regras                 listar (?ativa=1|0)
 *   GET    /api/regras/{id}            consultar
 *   GET    /api/regras/{id}/historico  auditoria de alterações
 *   POST   /api/regras                 cadastrar          [ADMIN]
 *   PUT    /api/regras/{id}            alterar            [ADMIN]
 *   PATCH  /api/regras/{id}/status     ativar/desativar   [ADMIN]
 */
final class RegraController
{
    private RegraRepository $regras;

    public function __construct()
    {
        $this->regras = new RegraRepository();
    }

    /**
     * Tudo o que a tela precisa para montar o editor de regras.
     * O frontend NÃO tem listas fixas: campo ou operador novo no motor
     * aparece na tela automaticamente.
     */
    public function metadados(Request $request): void
    {
        $opcoes = [
            'SERVICO'   => (new ServicoRepository())->listar(),
            'CATEGORIA' => (new CategoriaRepository())->listar(),
            'REGIAO'    => (new RegiaoRepository())->listar(),
            'FAIXA'     => (new FaixaRepository())->listar(),
        ];

        $campos = [];
        foreach (AvaliadorCondicao::CAMPOS as $codigo => $def) {
            $campos[] = [
                'codigo' => $codigo,
                'rotulo' => $def['rotulo'],
                'tipo'   => $def['tipo'],
                'opcoes' => array_map(fn ($o) => [
                    'id'    => (int) $o['id'],
                    'nome'  => $o['nome'] ?? $o['descricao'],
                    'ativo' => $o['ativo'],
                ], $opcoes[$codigo] ?? []),
            ];
        }

        $operadores = [];
        foreach (Operadores::LISTA as $codigo => $def) {
            $operadores[] = [
                'codigo'            => $codigo,
                'simbolo'           => $def['simbolo'],
                'valores'           => $def['valores'],
                'somente_numericos' => in_array($codigo, Operadores::SOMENTE_NUMERICOS, true),
            ];
        }

        Response::sucesso([
            'campos'         => $campos,
            'operadores'     => $operadores,
            'tipos_acao'     => AplicadorAcao::TIPOS_ACAO,
            'tipos_valor'    => AplicadorAcao::TIPOS_VALOR,
            'proximo_codigo' => $this->regras->proximoCodigo(),
        ]);
    }

    public function listar(Request $request): void
    {
        $filtro = $request->query('ativa');
        $ativa  = $filtro === null || $filtro === '' ? null : in_array($filtro, ['1', 'true'], true);

        Response::sucesso($this->regras->listar($ativa));
    }

    public function buscar(Request $request): void
    {
        Response::sucesso($this->regras->buscarOuFalhar($request->idRota()));
    }

    public function historico(Request $request): void
    {
        $id = $request->idRota();
        $this->regras->buscarOuFalhar($id);

        Response::sucesso($this->regras->historico($id));
    }

    public function criar(Request $request): void
    {
        [$dados, $condicoes] = $this->preparar($request->corpo());
        $this->validar($dados, $condicoes, null);

        Response::criado($this->regras->criar($dados, $condicoes, Auth::idUsuario()));
    }

    public function atualizar(Request $request): void
    {
        $id = $request->idRota();
        $this->regras->buscarOuFalhar($id);

        [$dados, $condicoes] = $this->preparar($request->corpo());
        $this->validar($dados, $condicoes, $id);

        Response::sucesso($this->regras->atualizar($id, $dados, $condicoes, Auth::idUsuario()));
    }

    /** PATCH { "ativa": true|false } */
    public function status(Request $request): void
    {
        $ativa = $request->input('ativa');

        (new Validador(['ativa' => $ativa]))
            ->obrigatorio('ativa', 'O campo ativa')
            ->emLista('ativa', 'O campo ativa', [true, false])
            ->validar();

        Response::sucesso($this->regras->definirAtiva($request->idRota(), $ativa, Auth::idUsuario()));
    }

    // -----------------------------------------------------------------

    /** Separa e normaliza os dados da regra e a lista de condições. */
    private function preparar(array $corpo): array
    {
        $texto = function ($v) {
            $v = $v === null ? '' : trim((string) $v);
            return $v === '' ? null : $v;
        };

        $dados = [
            'codigo'          => $texto($corpo['codigo'] ?? null) !== null ? strtoupper($texto($corpo['codigo'])) : null,
            'nome'            => $texto($corpo['nome'] ?? null),
            'descricao'       => $texto($corpo['descricao'] ?? null),
            'prioridade'      => $corpo['prioridade'] ?? 100,
            'tipo_acao'       => $corpo['tipo_acao'] ?? null,
            'tipo_valor'      => $corpo['tipo_valor'] ?? 'PERCENTUAL',
            'valor'           => $corpo['valor'] ?? null,
            'interromper'     => (int) filter_var($corpo['interromper'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'ativa'           => (int) filter_var($corpo['ativa'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'vigencia_inicio' => $texto($corpo['vigencia_inicio'] ?? null),
            'vigencia_fim'    => $texto($corpo['vigencia_fim'] ?? null),
        ];

        $condicoes = $corpo['condicoes'] ?? [];
        if (!is_array($condicoes)) {
            $condicoes = null;   // sinaliza formato inválido para a validação
        } else {
            $condicoes = array_map(fn ($c) => [
                'campo'       => is_array($c) ? strtoupper(trim((string) ($c['campo'] ?? ''))) : '',
                'operador'    => is_array($c) ? strtoupper(trim((string) ($c['operador'] ?? ''))) : '',
                'valor'       => is_array($c) ? trim((string) ($c['valor'] ?? '')) : '',
                'valor_final' => is_array($c) ? $texto($c['valor_final'] ?? null) : null,
            ], array_values($condicoes));
        }

        return [$dados, $condicoes];
    }

    private function validar(array $dados, ?array $condicoes, ?int $id): void
    {
        $v = (new Validador($dados))
            ->texto('codigo', 'Código', 20)
            ->obrigatorio('nome', 'Nome')->texto('nome', 'Nome', 150)
            ->texto('descricao', 'Descrição', 255)
            ->obrigatorio('prioridade', 'Prioridade')->inteiro('prioridade', 'Prioridade', 0)
            ->obrigatorio('tipo_acao', 'Tipo de ação')->emLista('tipo_acao', 'Tipo de ação', AplicadorAcao::TIPOS_ACAO)
            ->obrigatorio('tipo_valor', 'Forma do valor')->emLista('tipo_valor', 'Forma do valor', AplicadorAcao::TIPOS_VALOR)
            ->obrigatorio('valor', 'Valor')->numero('valor', 'Valor', 0, 99999999)
            ->data('vigencia_inicio', 'Início da vigência')
            ->data('vigencia_fim', 'Fim da vigência');

        if ($dados['codigo'] !== null) {
            if (!preg_match('/^[A-Z0-9_-]+$/', $dados['codigo'])) {
                $v->erro('codigo', 'Código aceita apenas letras, números, "-" e "_".');
            } elseif ($this->regras->codigoEmUso($dados['codigo'], $id)) {
                $v->erro('codigo', "O código {$dados['codigo']} já está em uso.");
            }
        }

        // Desconto percentual acima de 100% deixaria o preço negativo
        if ($dados['tipo_acao'] === 'DESCONTO' && $dados['tipo_valor'] === 'PERCENTUAL'
            && is_numeric($dados['valor']) && (float) $dados['valor'] > 100) {
            $v->erro('valor', 'Desconto percentual não pode passar de 100%.');
        }

        if ($dados['vigencia_inicio'] && $dados['vigencia_fim']
            && !$v->temErro('vigencia_inicio') && !$v->temErro('vigencia_fim')
            && $dados['vigencia_fim'] < $dados['vigencia_inicio']) {
            $v->erro('vigencia_fim', 'O fim da vigência deve ser igual ou posterior ao início.');
        }

        $this->validarCondicoes($v, $condicoes);
        $v->validar();
    }

    /**
     * Cada condição: estrutura válida (campo/operador suportados, valores
     * numéricos...) E, para campos de cadastro, ids que realmente existem.
     */
    private function validarCondicoes(Validador $v, ?array $condicoes): void
    {
        if ($condicoes === null) {
            $v->erro('condicoes', 'As condições devem ser uma lista.');
            return;
        }

        if (count($condicoes) > 20) {
            $v->erro('condicoes', 'Uma regra pode ter no máximo 20 condições.');
            return;
        }

        $existentes = [
            'SERVICO'   => (new ServicoRepository())->mapaDeNomes(),
            'CATEGORIA' => (new CategoriaRepository())->mapaDeNomes(),
            'REGIAO'    => (new RegiaoRepository())->mapaDeNomes(),
            'FAIXA'     => (new FaixaRepository())->mapaDeNomes(),
        ];

        foreach ($condicoes as $i => $condicao) {
            $erros = AvaliadorCondicao::validar($condicao);

            $tipo = AvaliadorCondicao::CAMPOS[$condicao['campo']]['tipo'] ?? null;
            if ($erros === [] && $tipo === 'cadastro') {
                $ids = Operadores::recebeLista($condicao['operador']) ? Operadores::lista($condicao['valor']) : [$condicao['valor']];
                foreach ($ids as $idCadastro) {
                    if (!isset($existentes[$condicao['campo']][(int) $idCadastro])) {
                        $rotulo  = AvaliadorCondicao::CAMPOS[$condicao['campo']]['rotulo'];
                        $erros[] = "{$rotulo} de código {$idCadastro} não existe.";
                    }
                }
            }

            if ($erros !== []) {
                $v->erro("condicoes.{$i}", 'Condição ' . ($i + 1) . ': ' . implode(' ', $erros));
            }
        }
    }
}