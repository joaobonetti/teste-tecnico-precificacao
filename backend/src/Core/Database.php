<?php

namespace App\Core;

use PDO;

/**
 * Conexão com o banco (PDO).
 */
final class Database
{
    private static ?PDO $conexao = null;
    private static array $config = [];

    /** Recebe as configurações do banco (chamado uma vez no index.php). */
    public static function configurar(array $config): void
    {
        self::$config = $config;
    }

    public static function conexao(): PDO
    {
        if (self::$conexao === null) {
            $c = self::$config;

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $c['host'], $c['port'], $c['name']
            );

            self::$conexao = new PDO($dsn, $c['user'], $c['password'], [
                // Erros de SQL viram exceções (nunca falham em silêncio)
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Resultados como array associativo: ['nome' => 'Consultoria']
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Prepared statements REAIS no servidor MySQL (proteção contra SQL injection)
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Números inteiros voltam como int, não como string
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);

            // Datas gravadas no fuso de Brasília
            self::$conexao->exec("SET time_zone = '-03:00'");
        }

        return self::$conexao;
    }

    public static function transacao(callable $bloco): mixed
    {
        $pdo = self::conexao();
        $pdo->beginTransaction();

        try {
            $resultado = $bloco($pdo);
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}