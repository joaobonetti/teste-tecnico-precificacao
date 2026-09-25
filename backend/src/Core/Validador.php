<?php

namespace App\Core;

/**
 * Validação de dados de entrada.
 *
 * Acumula TODOS os erros e só então lança uma única exceção 422 com a
 * lista por campo — o usuário vê tudo o que precisa corrigir de uma vez:
 *   { "mensagem": "Dados inválidos.", "detalhes": { "nome": "...", "valor_base": "..." } }
 */
final class Validador
{
    private array $erros = [];

    public function __construct(private readonly array $dados)
    {
    }

    public function obrigatorio(string $campo, string $rotulo): self
    {
        $valor = $this->dados[$campo] ?? null;

        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            $this->erro($campo, "{$rotulo} é obrigatório.");
        }

        return $this;
    }

    public function texto(string $campo, string $rotulo, int $max): self
    {
        $valor = $this->dados[$campo] ?? null;

        if ($valor !== null && (!is_string($valor) || strlen(trim($valor)) > $max)) {
            $this->erro($campo, "{$rotulo} deve ser um texto de até {$max} caracteres.");
        }

        return $this;
    }

    /** Número (aceita "10", "10.5", 10, 10.5) dentro de um intervalo opcional. */
    public function numero(string $campo, string $rotulo, ?float $min = null, ?float $max = null): self
    {
        $valor = $this->dados[$campo] ?? null;

        if ($valor === null || $valor === '') {
            return $this;
        }

        if (!is_numeric($valor)) {
            $this->erro($campo, "{$rotulo} deve ser numérico.");
        } elseif ($min !== null && (float) $valor < $min) {
            $this->erro($campo, "{$rotulo} deve ser maior ou igual a {$min}.");
        } elseif ($max !== null && (float) $valor > $max) {
            $this->erro($campo, "{$rotulo} deve ser menor ou igual a {$max}.");
        }

        return $this;
    }

    public function inteiro(string $campo, string $rotulo, ?int $min = null): self
    {
        $valor = $this->dados[$campo] ?? null;

        if ($valor === null || $valor === '') {
            return $this;
        }

        if (filter_var($valor, FILTER_VALIDATE_INT) === false) {
            $this->erro($campo, "{$rotulo} deve ser um número inteiro.");
        } elseif ($min !== null && (int) $valor < $min) {
            $this->erro($campo, "{$rotulo} deve ser maior ou igual a {$min}.");
        }

        return $this;
    }

    public function emLista(string $campo, string $rotulo, array $permitidos): self
    {
        $valor = $this->dados[$campo] ?? null;

        if ($valor !== null && !in_array($valor, $permitidos, true)) {
            $this->erro($campo, "{$rotulo} deve ser um destes: " . implode(', ', $permitidos) . '.');
        }

        return $this;
    }

    public function data(string $campo, string $rotulo): self
    {
        $valor = $this->dados[$campo] ?? null;

        if ($valor === null || $valor === '') {
            return $this;
        }

        $data = \DateTime::createFromFormat('Y-m-d', (string) $valor);
        if (!$data || $data->format('Y-m-d') !== $valor) {
            $this->erro($campo, "{$rotulo} deve estar no formato AAAA-MM-DD.");
        }

        return $this;
    }

    /** Para regras específicas que não cabem nos métodos acima. */
    public function erro(string $campo, string $mensagem): self
    {
        $this->erros[$campo] ??= $mensagem;   // mantém a primeira mensagem de cada campo
        return $this;
    }

    public function temErro(string $campo): bool
    {
        return isset($this->erros[$campo]);
    }

    /** Lança 422 se houver qualquer erro acumulado. */
    public function validar(): void
    {
        if ($this->erros !== []) {
            throw new HttpException(422, 'Dados inválidos. Verifique os campos destacados.', $this->erros);
        }
    }
}