<?php

spl_autoload_register(function (string $classe): void {
    $prefixo = 'App\\';

    if (!str_starts_with($classe, $prefixo)) {
        return; // não é uma classe do projeto
    }

    $relativo = substr($classe, strlen($prefixo));
    $arquivo  = __DIR__ . '/' . str_replace('\\', '/', $relativo) . '.php';

    if (is_file($arquivo)) {
        require $arquivo;
    }
});