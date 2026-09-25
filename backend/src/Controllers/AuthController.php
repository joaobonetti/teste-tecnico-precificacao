<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * Login, logout e autenticação.
 */
final class AuthController
{
    /** POST /api/auth/login   { "email": "...", "senha": "..." } */
    public function login(Request $request): void
    {
        $email = trim((string) $request->input('email', ''));
        $senha = (string) $request->input('senha', '');

        if ($email === '' || $senha === '') {
            throw new HttpException(422, 'Informe e-mail e senha.');
        }

        Response::sucesso(Auth::login($email, $senha));
    }

    /** POST /api/auth/logout */
    public function logout(Request $request): void
    {
        Auth::logout();
        Response::sucesso(['mensagem' => 'Sessão encerrada.']);
    }

    /** GET /api/auth/me — o frontend usa para saber se há alguém logado e qual o perfil. */
    public function me(Request $request): void
    {
        Response::sucesso(Auth::usuario());
    }
}