<?php

namespace App\Core;

/**
 * Representa a requisição HTTP recebida.
 */
final class Request
{
    private array $corpo = [];
    private array $parametrosRota = [];

    public function __construct(
        private readonly string $metodo,
        private readonly string $caminho,
        private readonly array $query
    ) {
    }

    /** Monta a Request a partir das variáveis globais do PHP. */
    public static function capturar(): self
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $caminho = rtrim($caminho, '/') ?: '/';

        $request = new self($metodo, $caminho, $_GET);

        if (in_array($metodo, ['POST', 'PUT', 'PATCH'], true)) {
            $request->corpo = self::lerJson();
        }

        return $request;
    }

    private static function lerJson(): array
    {
        $bruto = file_get_contents('php://input');

        if ($bruto === '' || $bruto === false) {
            return [];
        }

        $dados = json_decode($bruto, true);

        if (!is_array($dados)) {
            throw new HttpException(400, 'Corpo da requisição não é um JSON válido.');
        }

        return $dados;
    }

    public function metodo(): string
    {
        return $this->metodo;
    }

    public function caminho(): string
    {
        return $this->caminho;
    }

    /** Todo o corpo JSON. */
    public function corpo(): array
    {
        return $this->corpo;
    }

    /** Um campo do corpo JSON, com valor padrão. */
    public function input(string $campo, mixed $padrao = null): mixed
    {
        return $this->corpo[$campo] ?? $padrao;
    }

    /** Parâmetro da URL após o "?" (ex.: ?ativa=1). */
    public function query(string $campo, mixed $padrao = null): mixed
    {
        return $this->query[$campo] ?? $padrao;
    }

    /** Preenchido pelo Router: /api/regras/{id} → ['id' => '3'] */
    public function definirParametrosRota(array $parametros): void
    {
        $this->parametrosRota = $parametros;
    }

    /** Parâmetro da rota convertido para inteiro (ex.: o {id}). */
    public function idRota(string $nome = 'id'): int
    {
        $valor = $this->parametrosRota[$nome] ?? null;

        if ($valor === null || !ctype_digit((string) $valor) || (int) $valor <= 0) {
            throw new HttpException(400, "Parâmetro '{$nome}' inválido.");
        }

        return (int) $valor;
    }
}