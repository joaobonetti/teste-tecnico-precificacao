<?php

namespace App\Engine;

/**
 * Avalia UMA condição de regra ("campo operador valor") contra os dados
 * do cálculo, e sabe descrevê-la em português para a memória de cálculo.
 *
 * Também é a fonte única dos CAMPOS que uma condição pode usar.
 * Campo novo (ex.: "DIA_DA_SEMANA") = uma entrada em CAMPOS + o valor
 * correspondente no contexto montado pelo MotorCalculo.
 */
final class AvaliadorCondicao
{
    /**
     * código do campo => rótulo, chave no contexto do cálculo e tipo:
     *   cadastro → o valor da regra é o ID de um registro (Serviço 3, Região 2...)
     *   numero   → o valor da regra é um número (Quantidade 50)
     */
    public const CAMPOS = [
        'SERVICO'    => ['rotulo' => 'Serviço',    'chave' => 'servico_id',   'tipo' => 'cadastro'],
        'CATEGORIA'  => ['rotulo' => 'Categoria',  'chave' => 'categoria_id', 'tipo' => 'cadastro'],
        'REGIAO'     => ['rotulo' => 'Região',     'chave' => 'regiao_id',    'tipo' => 'cadastro'],
        'FAIXA'      => ['rotulo' => 'Faixa',      'chave' => 'faixa_id',     'tipo' => 'cadastro'],
        'QUANTIDADE' => ['rotulo' => 'Quantidade', 'chave' => 'quantidade',   'tipo' => 'numero'],
    ];

    /**
     * @param array $condicao ['campo', 'operador', 'valor', 'valor_final']
     * @param array $contexto dados do cálculo + ['nomes' => ['CATEGORIA' => [3 => 'Estratégico'], ...]]
     * @return array ['descricao', 'atendida', 'valor_informado', 'erro'?]
     */
    public static function avaliar(array $condicao, array $contexto): array
    {
        $campo    = (string) ($condicao['campo'] ?? '');
        $operador = (string) ($condicao['operador'] ?? '');

        // Defesa: uma condição inválida no banco NUNCA é aplicada nem derruba o cálculo
        $erros = self::validar($condicao);
        if ($erros !== []) {
            return [
                'descricao'       => "{$campo} {$operador} " . ($condicao['valor'] ?? ''),
                'atendida'        => false,
                'valor_informado' => null,
                'erro'            => implode(' ', $erros),
            ];
        }

        $definicao = self::CAMPOS[$campo];
        $informado = $contexto[$definicao['chave']] ?? null;

        return [
            'descricao'       => self::descrever($condicao, $contexto['nomes'] ?? []),
            'atendida'        => Operadores::avaliar($operador, $informado, (string) $condicao['valor'], $condicao['valor_final'] ?? null),
            'valor_informado' => self::nomeDoValor($campo, $informado, $contexto['nomes'] ?? []),
        ];
    }

    /**
     * Valida a estrutura de uma condição. Usado pelo motor (defesa) e pelo
     * cadastro de regras (para recusar condições inválidas antes de salvar).
     * @return string[] lista de erros (vazia = válida)
     */
    public static function validar(array $condicao): array
    {
        $campo      = (string) ($condicao['campo'] ?? '');
        $operador   = (string) ($condicao['operador'] ?? '');
        $valor      = trim((string) ($condicao['valor'] ?? ''));
        $valorFinal = $condicao['valor_final'] ?? null;
        $erros      = [];

        if (!isset(self::CAMPOS[$campo])) {
            return ["Campo '{$campo}' não é suportado."];
        }
        if (!Operadores::existe($operador)) {
            return ["Operador '{$operador}' não é suportado."];
        }

        $tipo = self::CAMPOS[$campo]['tipo'];

        if ($tipo === 'cadastro' && in_array($operador, Operadores::SOMENTE_NUMERICOS, true)) {
            $erros[] = "O operador '{$operador}' não se aplica ao campo " . self::CAMPOS[$campo]['rotulo'] . '.';
        }

        if ($valor === '') {
            $erros[] = 'Informe o valor da condição.';
        } elseif (Operadores::recebeLista($operador)) {
            foreach (Operadores::lista($valor) as $item) {
                if (!is_numeric($item)) {
                    $erros[] = "Valor '{$item}' da lista não é numérico.";
                }
            }
        } elseif (!is_numeric($valor)) {
            $erros[] = 'O valor da condição deve ser numérico.';
        }

        if (Operadores::recebeIntervalo($operador)) {
            if ($valorFinal === null || trim((string) $valorFinal) === '' || !is_numeric($valorFinal)) {
                $erros[] = "O operador {$operador} exige um valor final numérico.";
            } elseif (is_numeric($valor) && bccomp((string) $valorFinal, $valor, 4) < 0) {
                $erros[] = "No {$operador}, o valor final deve ser maior ou igual ao inicial.";
            }
        }

        return $erros;
    }

    /**
     * Ex.: "Categoria = Estratégico", "Quantidade entre 51 e 100", "Região em Regional, Nacional".
     * Decide pelo TIPO do operador (intervalo, lista ou valor único), não pelo nome:
     * um operador novo é descrito corretamente sem alterar este método.
     */
    public static function descrever(array $condicao, array $nomes = []): string
    {
        $campo    = $condicao['campo'];
        $operador = $condicao['operador'];
        $rotulo   = self::CAMPOS[$campo]['rotulo'] ?? $campo;
        $simbolo  = Operadores::simbolo($operador);
        $nome     = fn ($v) => self::nomeDoValor($campo, $v, $nomes);

        if (Operadores::recebeIntervalo($operador)) {
            return "{$rotulo} {$simbolo} {$nome($condicao['valor'])} e {$nome($condicao['valor_final'])}";
        }
        if (Operadores::recebeLista($operador)) {
            return "{$rotulo} {$simbolo} " . implode(', ', array_map($nome, Operadores::lista((string) $condicao['valor'])));
        }

        return "{$rotulo} {$simbolo} {$nome($condicao['valor'])}";
    }

    /** Troca o ID pelo nome do cadastro (3 → "Estratégico"); números ficam como estão. */
    private static function nomeDoValor(string $campo, mixed $valor, array $nomes): ?string
    {
        if ($valor === null) {
            return null;
        }

        return $nomes[$campo][(int) $valor] ?? (string) $valor;
    }
}