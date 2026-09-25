<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validador;
use App\Repositories\CrudRepository;

/**
 * Base dos controllers de cadastro (serviços, categorias, regiões, faixas).
 * O fluxo é igual para todos — cada subclasse só define o repositório,
 * a validação e a limpeza dos dados.
 *
 * Rotas geradas para cada cadastro (ver config/routes.php):
 *   GET    /api/{recurso}               listar  (?ativos=1 → só ativos)
 *   GET    /api/{recurso}/{id}          consultar
 *   POST   /api/{recurso}               cadastrar        [ADMIN]
 *   PUT    /api/{recurso}/{id}          alterar          [ADMIN]
 *   PATCH  /api/{recurso}/{id}/status   ativar/desativar [ADMIN]
 */
abstract class CadastroController
{
    abstract protected function repositorio(): CrudRepository;

    /** Valida os dados; $id preenchido = alteração. */
    abstract protected function validar(array $dados, ?int $id): void;

    /** Normaliza os dados recebidos (trim, vazios → null, etc.). */
    abstract protected function preparar(array $dados): array;

    public function listar(Request $request): void
    {
        $apenasAtivos = in_array($request->query('ativos'), ['1', 'true'], true);
        Response::sucesso($this->repositorio()->listar($apenasAtivos));
    }

    public function buscar(Request $request): void
    {
        Response::sucesso($this->repositorio()->buscarOuFalhar($request->idRota()));
    }

    public function criar(Request $request): void
    {
        $dados = $this->preparar($request->corpo());
        $this->validar($dados, null);

        Response::criado($this->repositorio()->criar($dados));
    }

    public function atualizar(Request $request): void
    {
        $id = $request->idRota();
        $this->repositorio()->buscarOuFalhar($id);

        $dados = $this->preparar($request->corpo());
        $this->validar($dados, $id);

        Response::sucesso($this->repositorio()->atualizar($id, $dados));
    }

    /** PATCH { "ativo": true|false } */
    public function status(Request $request): void
    {
        $ativo = $request->input('ativo');

        (new Validador(['ativo' => $ativo]))
            ->obrigatorio('ativo', 'O campo ativo')
            ->emLista('ativo', 'O campo ativo', [true, false])
            ->validar();

        Response::sucesso($this->repositorio()->definirAtivo($request->idRota(), $ativo));
    }

    /** Texto com trim; vazio vira null. */
    protected static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}