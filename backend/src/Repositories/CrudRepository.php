<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\HttpException;
use PDO;

/**
 * Base dos repositórios de CADASTROS simples (serviços, categorias,
 * regiões, faixas). Todo o SQL fica na camada de repositório; controllers
 * e motor de cálculo nunca escrevem SQL.
 *
 * Segurança: nomes de tabela e colunas vêm de constantes das subclasses
 * (nunca do usuário); valores sempre vão por parâmetros (prepared statements).
 *
 * Exclusão é LÓGICA (ativo = 0): registros usados em cálculos antigos
 * continuam existindo e o histórico permanece íntegro.
 */
abstract class CrudRepository
{
    /** Nome da tabela */
    protected const TABELA = '';

    /** Colunas que podem ser gravadas pelo usuário */
    protected const COLUNAS = [];

    /** Ordenação padrão da listagem */
    protected const ORDEM = 'id';

    /** Nome no singular para mensagens ("Serviço não encontrado.") */
    protected const ROTULO = 'Registro';

    protected function pdo(): PDO
    {
        return Database::conexao();
    }

    public function listar(bool $apenasAtivos = false): array
    {
        $sql = 'SELECT * FROM ' . static::TABELA
             . ($apenasAtivos ? ' WHERE ativo = 1' : '')
             . ' ORDER BY ' . static::ORDEM;

        return array_map([$this, 'formatar'], $this->pdo()->query($sql)->fetchAll());
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM ' . static::TABELA . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();

        return $linha ? $this->formatar($linha) : null;
    }

    /** Busca ou lança 404. */
    public function buscarOuFalhar(int $id): array
    {
        return $this->buscar($id) ?? throw new HttpException(404, static::ROTULO . ' não encontrado(a).');
    }

    public function criar(array $dados): array
    {
        $dados = $this->filtrar($dados);
        $colunas = array_keys($dados);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            static::TABELA,
            implode(', ', $colunas),
            implode(', ', array_map(fn ($c) => ":{$c}", $colunas))
        );

        $this->pdo()->prepare($sql)->execute($dados);

        return $this->buscar((int) $this->pdo()->lastInsertId());
    }

    public function atualizar(int $id, array $dados): array
    {
        $this->buscarOuFalhar($id);
        $dados = $this->filtrar($dados);

        if ($dados !== []) {
            $sql = sprintf(
                'UPDATE %s SET %s WHERE id = :id',
                static::TABELA,
                implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($dados)))
            );
            $this->pdo()->prepare($sql)->execute($dados + ['id' => $id]);
        }

        return $this->buscar($id);
    }

    public function definirAtivo(int $id, bool $ativo): array
    {
        $this->buscarOuFalhar($id);

        $this->pdo()
            ->prepare('UPDATE ' . static::TABELA . ' SET ativo = :ativo WHERE id = :id')
            ->execute(['ativo' => (int) $ativo, 'id' => $id]);

        return $this->buscar($id);
    }

    /** Mapa id => nome, usado para escrever a memória de cálculo com nomes. */
    public function mapaDeNomes(string $colunaNome = 'nome'): array
    {
        $stmt = $this->pdo()->query("SELECT id, {$colunaNome} FROM " . static::TABELA);

        return array_column($stmt->fetchAll(), $colunaNome, 'id');
    }

    /** Mantém apenas colunas permitidas (o usuário não consegue gravar id, datas, etc.). */
    protected function filtrar(array $dados): array
    {
        return array_intersect_key($dados, array_flip(static::COLUNAS));
    }

    /** Ajusta tipos para a resposta JSON (ativo como booleano, etc.). */
    protected function formatar(array $linha): array
    {
        if (array_key_exists('ativo', $linha)) {
            $linha['ativo'] = (bool) $linha['ativo'];
        }

        return $linha;
    }
}