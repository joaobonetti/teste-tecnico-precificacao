<?php

namespace App\Core;

/**
 * Autenticação por SESSÃO do PHP.
 */
final class Auth
{
    private static array $config = [];

    public static function configurar(array $config): void
    {
        self::$config = $config;
    }

    public static function iniciarSessao(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::$config['nome'] ?? 'PRECIFICACAO_SESSID');
        session_set_cookie_params([
            'lifetime' => self::$config['duracao'] ?? 28800,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        ]);
        session_start();
    }

    /**
     * Confere e-mail e senha. Retorna os dados públicos do usuário.
     * A mensagem de erro é sempre a mesma, para não revelar se o e-mail existe.
     */
    public static function login(string $email, string $senha): array
    {
        $stmt = Database::conexao()->prepare(
            'SELECT id, nome, email, senha_hash, perfil, ativo FROM usuarios WHERE email = :email'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $usuario = $stmt->fetch();

        // Mesmo sem usuário, roda o password_verify num hash fictício:
        // o tempo de resposta fica igual e não denuncia e-mails cadastrados.
        $hash = $usuario['senha_hash'] ?? '$2y$12$eDC1K58Xf.Q7/fE..y9ISeDlb7NIKFcyZ8kEI3ragShuXKGh3CS9e';
        $senhaOk = password_verify($senha, $hash);

        if (!$usuario || !$senhaOk || !(int) $usuario['ativo']) {
            throw new HttpException(401, 'E-mail ou senha inválidos.');
        }

        // Novo ID de sessão após o login (evita "session fixation")
        session_regenerate_id(true);

        $_SESSION['usuario'] = [
            'id'     => (int) $usuario['id'],
            'nome'   => $usuario['nome'],
            'email'  => $usuario['email'],
            'perfil' => $usuario['perfil'],
        ];

        return $_SESSION['usuario'];
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }

    /** Usuário logado, ou null. */
    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    /** Id do usuário logado (usado na auditoria das regras e no histórico de cálculos). */
    public static function idUsuario(): ?int
    {
        return self::usuario()['id'] ?? null;
    }

    public static function exigirLogin(): void
    {
        if (self::usuario() === null) {
            throw new HttpException(401, 'Faça login para continuar.');
        }
    }

    public static function exigirAdmin(): void
    {
        self::exigirLogin();

        if (self::usuario()['perfil'] !== 'ADMIN') {
            throw new HttpException(403, 'Apenas administradores podem realizar esta operação.');
        }
    }
}