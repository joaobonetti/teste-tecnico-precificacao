<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;
use PDO;

/**
 * Regras de cálculo + suas condições + histórico de alterações (auditoria).
 */
final class RegraRepository
{
    /** Colunas da regra que o usuário pode gravar */
    private const COLUNAS = [
        'codigo', 'nome', 'descricao', 'prioridade', 'tipo_acao', 'tipo_valor',
        'valor', 'interromper', 'ativa', 'vigencia_inicio', 'vigencia_fim',
    ];

    private function pdo(): PDO
    {
        return Database::conexao();
    }

    /** Todas as regras (com condições), em ordem de execução. */
    public function listar(?bool $ativa = null): array
    {
        $sql = 'SELECT * FROM regras'
             . ($ativa === null ? '' : ' WHERE ativa = ' . (int) $ativa)
             . ' ORDER BY prioridade, id';

        return $this->comCondicoes($this->pdo()->query($sql)->fetchAll());
    }

    /** Usado pelo motor: só regras ativas, já ordenadas (usa o índice ativa+prioridade). */
    public function ativasParaCalculo(): array
    {
        return $this->listar(true);
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM regras WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();

        return $linha ? $this->comCondicoes([$linha])[0] : null;
    }

    public function buscarOuFalhar(int $id): array
    {
        return $this->buscar($id) ?? throw new HttpException(404, 'Regra não encontrada.');
    }

    public function criar(array $dados, array $condicoes, ?int $usuarioId): array
    {
        return Database::transacao(function (PDO $pdo) use ($dados, $condicoes, $usuarioId) {
            $dados = $this->filtrar($dados);
            $dados['codigo'] = ($dados['codigo'] ?? '') !== '' ? $dados['codigo'] : $this->proximoCodigo();
            $dados['criado_por'] = $usuarioId;
            $dados['atualizado_por'] = $usuarioId;

            $colunas = array_keys($dados);
            $pdo->prepare(sprintf(
                'INSERT INTO regras (%s) VALUES (%s)',
                implode(', ', $colunas),
                implode(', ', array_map(fn ($c) => ":{$c}", $colunas))
            ))->execute($dados);

            $id = (int) $pdo->lastInsertId();
            $this->gravarCondicoes($id, $condicoes);

            $nova = $this->buscar($id);
            $this->registrarHistorico($id, $usuarioId, 'CRIACAO', null, $nova);

            return $nova;
        });
    }

    public function atualizar(int $id, array $dados, array $condicoes, ?int $usuarioId): array
    {
        return Database::transacao(function (PDO $pdo) use ($id, $dados, $condicoes, $usuarioId) {
            $anterior = $this->buscarOuFalhar($id);

            $dados = $this->filtrar($dados);
            unset($dados['ativa']);                 // ativação tem endpoint próprio (auditoria separada)
            if (($dados['codigo'] ?? null) === null) {
                unset($dados['codigo']);            // código em branco na alteração = mantém o atual
            }
            $dados['atualizado_por'] = $usuarioId;

            $pdo->prepare(sprintf(
                'UPDATE regras SET %s WHERE id = :id',
                implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($dados)))
            ))->execute($dados + ['id' => $id]);

            // Condições: substitui o conjunto inteiro (mais simples e sem inconsistência)
            $pdo->prepare('DELETE FROM regra_condicoes WHERE regra_id = :id')->execute(['id' => $id]);
            $this->gravarCondicoes($id, $condicoes);

            $nova = $this->buscar($id);
            $this->registrarHistorico($id, $usuarioId, 'ALTERACAO', $anterior, $nova);

            return $nova;
        });
    }

    public function definirAtiva(int $id, bool $ativa, ?int $usuarioId): array
    {
        return Database::transacao(function (PDO $pdo) use ($id, $ativa, $usuarioId) {
            $anterior = $this->buscarOuFalhar($id);

            if ($anterior['ativa'] === $ativa) {
                return $anterior;                  // nada mudou: não gera auditoria
            }

            $pdo->prepare('UPDATE regras SET ativa = :ativa, atualizado_por = :usuario WHERE id = :id')
                ->execute(['ativa' => (int) $ativa, 'usuario' => $usuarioId, 'id' => $id]);

            $nova = $this->buscar($id);
            $this->registrarHistorico($id, $usuarioId, $ativa ? 'ATIVACAO' : 'DESATIVACAO', $anterior, $nova);

            return $nova;
        });
    }

    /** Histórico de alterações de uma regra, do mais recente para o mais antigo. */
    public function historico(int $id): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT h.id, h.acao, h.dados_anteriores, h.dados_novos, h.criado_em,
                    u.nome AS usuario
               FROM regras_historico h
               LEFT JOIN usuarios u ON u.id = h.usuario_id
              WHERE h.regra_id = :id
              ORDER BY h.criado_em DESC, h.id DESC'
        );
        $stmt->execute(['id' => $id]);

        return array_map(function ($h) {
            $h['dados_anteriores'] = $h['dados_anteriores'] ? json_decode($h['dados_anteriores'], true) : null;
            $h['dados_novos']      = $h['dados_novos'] ? json_decode($h['dados_novos'], true) : null;
            return $h;
        }, $stmt->fetchAll());
    }

    public function codigoEmUso(string $codigo, ?int $ignorarId = null): bool
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM regras WHERE codigo = :codigo AND id <> :id');
        $stmt->execute(['codigo' => $codigo, 'id' => $ignorarId ?? 0]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** R001, R002... → próximo número livre. */
    public function proximoCodigo(): string
    {
        $maior = (int) $this->pdo()->query(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 2) AS UNSIGNED)), 0)
               FROM regras WHERE codigo REGEXP '^R[0-9]+$'"
        )->fetchColumn();

        return sprintf('R%03d', $maior + 1);
    }

    // -----------------------------------------------------------------
    // Auxiliares
    // -----------------------------------------------------------------

    private function gravarCondicoes(int $regraId, array $condicoes): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO regra_condicoes (regra_id, campo, operador, valor, valor_final)
             VALUES (:regra_id, :campo, :operador, :valor, :valor_final)'
        );

        foreach ($condicoes as $c) {
            $stmt->execute([
                'regra_id'    => $regraId,
                'campo'       => $c['campo'],
                'operador'    => $c['operador'],
                'valor'       => trim((string) $c['valor']),
                'valor_final' => ($c['valor_final'] ?? '') === '' ? null : trim((string) $c['valor_final']),
            ]);
        }
    }

    private function registrarHistorico(int $regraId, ?int $usuarioId, string $acao, ?array $antes, ?array $depois): void
    {
        $json = fn (?array $d) => $d === null ? null : json_encode($d, JSON_UNESCAPED_UNICODE);

        $this->pdo()->prepare(
            'INSERT INTO regras_historico (regra_id, usuario_id, acao, dados_anteriores, dados_novos)
             VALUES (:regra, :usuario, :acao, :antes, :depois)'
        )->execute([
            'regra'   => $regraId,
            'usuario' => $usuarioId,
            'acao'    => $acao,
            'antes'   => $json($antes),
            'depois'  => $json($depois),
        ]);
    }

    /**
     * Anexa as condições às regras com UMA consulta só (evita o problema
     * "N+1": uma consulta extra por regra).
     */
    private function comCondicoes(array $regras): array
    {
        if ($regras === []) {
            return [];
        }

        $ids = array_map(fn ($r) => (int) $r['id'], $regras);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo()->prepare(
            "SELECT id, regra_id, campo, operador, valor, valor_final
               FROM regra_condicoes WHERE regra_id IN ({$marcadores}) ORDER BY id"
        );
        $stmt->execute($ids);

        $porRegra = [];
        foreach ($stmt->fetchAll() as $c) {
            $porRegra[(int) $c['regra_id']][] = [
                'id'          => (int) $c['id'],
                'campo'       => $c['campo'],
                'operador'    => $c['operador'],
                'valor'       => $c['valor'],
                'valor_final' => $c['valor_final'],
            ];
        }

        return array_map(function ($r) use ($porRegra) {
            $r['id']          = (int) $r['id'];
            $r['prioridade']  = (int) $r['prioridade'];
            $r['interromper'] = (bool) $r['interromper'];
            $r['ativa']       = (bool) $r['ativa'];
            $r['condicoes']   = $porRegra[$r['id']] ?? [];
            return $r;
        }, $regras);
    }

    private function filtrar(array $dados): array
    {
        return array_intersect_key($dados, array_flip(self::COLUNAS));
    }
}