<?php

namespace App\Core;

/**
 * Respostas JSON com formato ÚNICO para toda a API:
 */
final class Response
{
    public static function sucesso(mixed $dados = null, int $status = 200): never
    {
        self::enviar(['sucesso' => true, 'dados' => $dados], $status);
    }

    /** 201 Created — usado após cadastrar um registro. */
    public static function criado(mixed $dados): never
    {
        self::sucesso($dados, 201);
    }

    public static function erro(string $mensagem, int $status = 400, array $detalhes = []): never
    {
        $erro = ['mensagem' => $mensagem];

        if ($detalhes !== []) {
            $erro['detalhes'] = $detalhes;
        }

        self::enviar(['sucesso' => false, 'erro' => $erro], $status);
    }

    private static function enviar(array $corpo, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');   // respostas da API nunca vão para cache

        echo json_encode(
            $corpo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        exit;
    }
}