<?php

namespace App\Engine;

/**
 * CATÁLOGO DE OPERADORES — lista FECHADA (whitelist) de comparações que
 * uma condição de regra pode usar.
 *
 * Segurança: a regra vem do banco, mas nunca é executada como código
 * (sem eval). O motor só aceita os operadores declarados aqui; qualquer
 * outro valor é rejeitado.
 *
 * Extensibilidade: um operador novo (ex.: "NAO_EM") = uma entrada nova
 * neste arquivo. Nenhuma alteração no banco nem no restante do motor.
 */
final class Operadores
{
    /** código => [símbolo para exibição, quantidade de valores que exige] */
    public const LISTA = [
        'IGUAL'       => ['simbolo' => '=',      'valores' => 1],
        'DIFERENTE'   => ['simbolo' => '≠',      'valores' => 1],
        'MAIOR'       => ['simbolo' => '>',      'valores' => 1],
        'MAIOR_IGUAL' => ['simbolo' => '≥',      'valores' => 1],
        'MENOR'       => ['simbolo' => '<',      'valores' => 1],
        'MENOR_IGUAL' => ['simbolo' => '≤',      'valores' => 1],
        'ENTRE'       => ['simbolo' => 'entre',  'valores' => 2],   // inclusivo nas duas pontas
        'EM'          => ['simbolo' => 'em',     'valores' => 'lista'], // "1,2,3"
    ];

    /** Operadores que só fazem sentido para números (não para cadastros como Categoria). */
    public const SOMENTE_NUMERICOS = ['MAIOR', 'MAIOR_IGUAL', 'MENOR', 'MENOR_IGUAL', 'ENTRE'];

    public static function existe(string $operador): bool
    {
        return isset(self::LISTA[$operador]);
    }

    public static function simbolo(string $operador): string
    {
        return self::LISTA[$operador]['simbolo'] ?? $operador;
    }

    /** O operador recebe uma lista de valores ("1,2,3")? Ex.: EM */
    public static function recebeLista(string $operador): bool
    {
        return (self::LISTA[$operador]['valores'] ?? null) === 'lista';
    }

    /** O operador recebe um intervalo (valor inicial e final)? Ex.: ENTRE */
    public static function recebeIntervalo(string $operador): bool
    {
        return (self::LISTA[$operador]['valores'] ?? null) === 2;
    }

    /**
     * Compara o valor informado no cálculo com o valor configurado na regra.
     *
     * @param string|int|null $informado  valor do cálculo (ex.: quantidade 60)
     * @param string          $valor      valor da regra (ex.: "50")
     * @param string|null     $valorFinal limite superior (só no ENTRE)
     */
    public static function avaliar(string $operador, string|int|null $informado, string $valor, ?string $valorFinal = null): bool
    {
        if ($informado === null || !self::existe($operador)) {
            return false;
        }

        $informado = (string) $informado;

        return match ($operador) {
            'IGUAL'       => self::comparar($informado, $valor) === 0,
            'DIFERENTE'   => self::comparar($informado, $valor) !== 0,
            'MAIOR'       => self::comparar($informado, $valor) > 0,
            'MAIOR_IGUAL' => self::comparar($informado, $valor) >= 0,
            'MENOR'       => self::comparar($informado, $valor) < 0,
            'MENOR_IGUAL' => self::comparar($informado, $valor) <= 0,
            'ENTRE'       => $valorFinal !== null
                             && self::comparar($informado, $valor) >= 0
                             && self::comparar($informado, $valorFinal) <= 0,
            'EM'          => in_array(
                                 true,
                                 array_map(fn ($item) => self::comparar($informado, $item) === 0, self::lista($valor)),
                                 true
                             ),
        };
    }

    /** "1, 2,3" → ['1', '2', '3'] */
    public static function lista(string $valor): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $valor)), fn ($v) => $v !== ''));
    }

    /**
     * Comparação numérica EXATA com bcmath (sem imprecisão de float).
     * Se algum lado não for número, compara como texto.
     * Retorna -1, 0 ou 1.
     */
    private static function comparar(string $a, string $b): int
    {
        $a = trim($a);
        $b = trim($b);

        if (is_numeric($a) && is_numeric($b)) {
            return bccomp($a, $b, 4);
        }

        return strcmp(strtoupper($a), strtoupper($b)) <=> 0;
    }
}