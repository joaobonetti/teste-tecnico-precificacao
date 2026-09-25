<?php

return [
    'db' => [
        'host'     => getenv('DB_HOST')     ?: 'localhost',
        'port'     => getenv('DB_PORT')     ?: '3306',
        'name'     => getenv('DB_NAME')     ?: 'precificacao',
        'user'     => getenv('DB_USER')     ?: 'app',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],

    'sessao' => [
        'nome'     => 'PRECIFICACAO_SESSID',
        'duracao'  => 60 * 60 * 8,   // 8 horas
    ],

    'fuso_horario' => 'America/Sao_Paulo',

    // Modo debug: mostra o erro técnico na resposta da API.
    // Ligar só em desenvolvimento (APP_DEBUG=1 no docker-compose.yml).
    'debug' => getenv('APP_DEBUG') === '1',
];