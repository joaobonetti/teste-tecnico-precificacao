<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Histórico de cálculos executados. A memória completa é gravada em JSON
 * (fotografia do momento): alterar regras ou preços depois não muda
 * cálculos antigos.
 */
final class CalculoRepository
{
    public function salvar(array $resultado, ?int $usuarioId): int
    {
        $e = $resultado['entrada'];

        Database::conexao()->prepare(
            'INSERT INTO calculos
                (usuario_id, servico_id, categoria_id, regiao_id, quantidade,
                 valor_base, valor_unitario_final, valor_total, memoria)
             VALUES
                (:usuario, :servico, :categoria, :regiao, :quantidade,
                 :base, :unitario, :total, :memoria)'
        )->execute([
            'usuario'    => $usuarioId,
            'servico'    => $e['servico']['id'],
            'categoria'  => $e['categoria']['id'],
            'regiao'     => $e['regiao']['id'],
            'quantidade' => $e['quantidade'],
            'base'       => $resultado['valor_base'],
            'unitario'   => $resultado['valor_unitario_final'],
            'total'      => $resultado['valor_total'],
            'memoria'    => json_encode($resultado, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) Database::conexao()->lastInsertId();
    }

    /** Lista resumida (sem a memória completa, para ficar leve). */
    public function listar(int $limite = 50): array
    {
        $stmt = Database::conexao()->prepare(
            'SELECT c.id, c.criado_em, c.quantidade, c.valor_base, c.valor_unitario_final, c.valor_total,
                    s.nome AS servico, cat.nome AS categoria, r.nome AS regiao, u.nome AS usuario,
                    JSON_EXTRACT(c.memoria, "$.regras_aplicadas") AS regras_aplicadas
               FROM calculos c
               JOIN servicos s             ON s.id = c.servico_id
               JOIN categorias_cliente cat ON cat.id = c.categoria_id
               JOIN regioes r              ON r.id = c.regiao_id
               LEFT JOIN usuarios u        ON u.id = c.usuario_id
              ORDER BY c.id DESC
              LIMIT :limite'
        );
        $stmt->bindValue('limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(function ($c) {
            $c['regras_aplicadas'] = json_decode($c['regras_aplicadas'] ?? '[]', true) ?? [];
            return $c;
        }, $stmt->fetchAll());
    }

    /** Um cálculo com a memória completa. */
    public function buscarOuFalhar(int $id): array
    {
        $stmt = Database::conexao()->prepare(
            'SELECT c.id, c.criado_em, u.nome AS usuario, c.memoria
               FROM calculos c LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE c.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch() ?: throw new HttpException(404, 'Cálculo não encontrado.');

        return ['id' => (int) $linha['id'], 'criado_em' => $linha['criado_em'], 'usuario' => $linha['usuario']]
             + json_decode($linha['memoria'], true);
    }
}