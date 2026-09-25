<?php
// =====================================================================
// MAPA DE ROTAS DA API
// Um lugar só para ver todas as URLs do sistema e quem pode acessá-las.
//   Router::PUBLICO → sem login
//   Router::LOGADO  → ADMIN ou OPERADOR (consultar e calcular)
//   Router::ADMIN   → somente ADMIN (cadastrar, alterar, ativar/desativar)
// ATENÇÃO: rotas fixas (ex.: /metadados) vêm ANTES das rotas com {id}.
// =====================================================================

use App\Controllers\AuthController;
use App\Controllers\CalculoController;
use App\Controllers\CategoriaController;
use App\Controllers\FaixaController;
use App\Controllers\RegiaoController;
use App\Controllers\RegraController;
use App\Controllers\ServicoController;
use App\Core\Database;
use App\Core\Response;
use App\Core\Router;

/** @var Router $router */

// --- Saúde da aplicação (confere se a API e o banco estão no ar)
$router->get('/api/status', function () {
    Database::conexao()->query('SELECT 1');
    Response::sucesso([
        'api'   => 'ok',
        'banco' => 'ok',
        'php'   => PHP_VERSION,
        'hora'  => date('Y-m-d H:i:s'),
    ]);
}, Router::PUBLICO);

// --- Autenticação
$router->post('/api/auth/login',  [AuthController::class, 'login'],  Router::PUBLICO);
$router->post('/api/auth/logout', [AuthController::class, 'logout'], Router::LOGADO);
$router->get('/api/auth/me',      [AuthController::class, 'me'],     Router::LOGADO);

// --- Cadastros básicos: mesmas 5 rotas para cada um
$cadastros = [
    'servicos'   => ServicoController::class,
    'categorias' => CategoriaController::class,
    'regioes'    => RegiaoController::class,
    'faixas'     => FaixaController::class,
];

foreach ($cadastros as $recurso => $controller) {
    $router->get("/api/{$recurso}",                [$controller, 'listar'],    Router::LOGADO);
    $router->get("/api/{$recurso}/{id}",           [$controller, 'buscar'],    Router::LOGADO);
    $router->post("/api/{$recurso}",               [$controller, 'criar'],     Router::ADMIN);
    $router->put("/api/{$recurso}/{id}",           [$controller, 'atualizar'], Router::ADMIN);
    $router->patch("/api/{$recurso}/{id}/status",  [$controller, 'status'],    Router::ADMIN);
}

// --- Regras de cálculo
$router->get('/api/regras/metadados',        [RegraController::class, 'metadados'], Router::LOGADO);
$router->get('/api/regras',                  [RegraController::class, 'listar'],    Router::LOGADO);
$router->get('/api/regras/{id}',             [RegraController::class, 'buscar'],    Router::LOGADO);
$router->get('/api/regras/{id}/historico',   [RegraController::class, 'historico'], Router::LOGADO);
$router->post('/api/regras',                 [RegraController::class, 'criar'],     Router::ADMIN);
$router->put('/api/regras/{id}',             [RegraController::class, 'atualizar'], Router::ADMIN);
$router->patch('/api/regras/{id}/status',    [RegraController::class, 'status'],    Router::ADMIN);

// --- Cálculo
$router->post('/api/calculos',      [CalculoController::class, 'calcular'], Router::LOGADO);
$router->get('/api/calculos',       [CalculoController::class, 'listar'],   Router::LOGADO);
$router->get('/api/calculos/{id}',  [CalculoController::class, 'buscar'],   Router::LOGADO);