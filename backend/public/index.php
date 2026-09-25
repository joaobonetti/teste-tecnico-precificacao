<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require __DIR__ . '/../src/autoload.php';
$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set($config['fuso_horario']);

// Cabeçalhos de segurança da API
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

try {
    Database::configurar($config['db']);
    Auth::configurar($config['sessao']);
    Auth::iniciarSessao();

    $router = new Router();
    require __DIR__ . '/../config/routes.php';

    $router->despachar(Request::capturar());

} catch (HttpException $e) {
    // Erros previstos (validação, não encontrado, sem permissão...)
    Response::erro($e->getMessage(), $e->status(), $e->detalhes());

} catch (PDOException $e) {
    // Violação de UNIQUE (ex.: nome de serviço repetido) → mensagem amigável
    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate')) {
        Response::erro('Já existe um registro com esses dados.', 409);
    }
    // Violação de CHECK constraint no banco (2ª camada de validação)
    if ($e->getCode() === 'HY000' && str_contains($e->getMessage(), 'Check constraint')) {
        Response::erro('Os dados informados violam uma regra de integridade.', 422);
    }

    // Demais erros de banco: detalhe só no log (e na resposta apenas em modo debug)
    error_log('[DB] ' . $e->getMessage());
    Response::erro('Erro interno ao acessar o banco de dados.', 500, detalhesDebug($e, $config));

} catch (Throwable $e) {
    error_log('[APP] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    Response::erro('Erro interno no servidor.', 500, detalhesDebug($e, $config));
}

/**
 * MODO DEBUG (APP_DEBUG=1 no docker-compose): inclui o erro técnico na resposta
 * para facilitar o desenvolvimento. Em produção fica DESLIGADO (APP_DEBUG=0),
 * pois expor mensagens, arquivos e linhas ajuda um atacante a mapear o sistema.
 */
function detalhesDebug(Throwable $e, array $config): array
{
    if (empty($config['debug'])) {
        return [];
    }

    return [
        'debug' => [
            'tipo'     => get_class($e),
            'mensagem' => $e->getMessage(),
            'arquivo'  => str_replace(dirname(__DIR__), '', $e->getFile()),
            'linha'    => $e->getLine(),
            'pilha'    => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ],
    ];
}