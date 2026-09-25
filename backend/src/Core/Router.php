<?php

namespace App\Core;

/**
 * Roteador: liga "MÉTODO + URL" ao código que deve responder.
 */
final class Router
{
    public const PUBLICO = 'PUBLICO';   // qualquer pessoa
    public const LOGADO  = 'LOGADO';    // qualquer usuário autenticado (ADMIN ou OPERADOR)
    public const ADMIN   = 'ADMIN';     // somente administradores

    private array $rotas = [];

    public function get(string $padrao, callable|array $acao, string $acesso = self::LOGADO): void
    {
        $this->adicionar('GET', $padrao, $acao, $acesso);
    }

    public function post(string $padrao, callable|array $acao, string $acesso = self::LOGADO): void
    {
        $this->adicionar('POST', $padrao, $acao, $acesso);
    }

    public function put(string $padrao, callable|array $acao, string $acesso = self::LOGADO): void
    {
        $this->adicionar('PUT', $padrao, $acao, $acesso);
    }

    public function patch(string $padrao, callable|array $acao, string $acesso = self::LOGADO): void
    {
        $this->adicionar('PATCH', $padrao, $acao, $acesso);
    }

    public function delete(string $padrao, callable|array $acao, string $acesso = self::LOGADO): void
    {
        $this->adicionar('DELETE', $padrao, $acao, $acesso);
    }

    private function adicionar(string $metodo, string $padrao, callable|array $acao, string $acesso): void
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($padrao, '/'));

        $this->rotas[] = [
            'metodo' => $metodo,
            'regex'  => '#^' . $regex . '$#',
            'acao'   => $acao,
            'acesso' => $acesso,
        ];
    }

    /** Encontra a rota da requisição, confere o acesso e executa. */
    public function despachar(Request $request): void
    {
        $caminhoExiste = false;

        foreach ($this->rotas as $rota) {
            if (!preg_match($rota['regex'], $request->caminho(), $encontrados)) {
                continue;
            }

            $caminhoExiste = true;

            if ($rota['metodo'] !== $request->metodo()) {
                continue;
            }

            // Guarda os parâmetros da URL (apenas as chaves nomeadas)
            $request->definirParametrosRota(
                array_filter($encontrados, 'is_string', ARRAY_FILTER_USE_KEY)
            );

            $this->verificarAcesso($rota['acesso']);
            $this->executar($rota['acao'], $request);
            return;
        }

        // A URL existe, mas não com esse método (ex.: DELETE em rota só de GET)
        if ($caminhoExiste) {
            throw new HttpException(405, 'Método não permitido para este recurso.');
        }

        throw new HttpException(404, 'Recurso não encontrado.');
    }

    private function verificarAcesso(string $acesso): void
    {
        match ($acesso) {
            self::PUBLICO => null,
            self::LOGADO  => Auth::exigirLogin(),
            self::ADMIN   => Auth::exigirAdmin(),
        };
    }

    private function executar(callable|array $acao, Request $request): void
    {
        // [NomeDoController::class, 'metodo'] → instancia o controller e chama o método
        if (is_array($acao)) {
            [$classe, $metodo] = $acao;
            (new $classe())->$metodo($request);
            return;
        }

        // Função anônima (usada em rotas simples, como /api/status)
        $acao($request);
    }
}