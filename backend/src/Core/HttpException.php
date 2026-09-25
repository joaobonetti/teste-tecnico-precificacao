<?php

namespace App\Core;

/**
 * Erro com código HTTP.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param int   $status   Código HTTP
     * @param array $detalhes Erros
     */
    public function __construct(
        private readonly int $status,
        string $mensagem,
        private readonly array $detalhes = []
    ) {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function detalhes(): array
    {
        return $this->detalhes;
    }
}