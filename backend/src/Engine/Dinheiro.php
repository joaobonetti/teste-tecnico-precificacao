<?php

namespace App\Engine;

/**
 * Operações com dinheiro usando bcmath (números como TEXTO, precisão exata).
 */
final class Dinheiro
{
    /** Casas decimais nas contas intermediárias (antes de arredondar). */
    private const ESCALA = 10;

    public static function somar(string $a, string $b): string
    {
        return bcadd($a, $b, self::ESCALA);
    }

    public static function subtrair(string $a, string $b): string
    {
        return bcsub($a, $b, self::ESCALA);
    }

    public static function multiplicar(string $a, string $b): string
    {
        return bcmul($a, $b, self::ESCALA);
    }

    /** percentual(200, 5) = 10  →  200 × 5 / 100 */
    public static function percentual(string $valor, string $percentual): string
    {
        return bcdiv(bcmul($valor, $percentual, self::ESCALA), '100', self::ESCALA);
    }

    /** Arredonda para 2 casas, meio para cima: 10,005 → 10,01 ; 10,004 → 10,00 */
    public static function arredondar(string $valor, int $casas = 2): string
    {
        $meio = '0.' . str_repeat('0', $casas) . '5';

        return bccomp($valor, '0', self::ESCALA) >= 0
            ? bcadd($valor, $meio, $casas)
            : bcsub($valor, $meio, $casas);
    }

    public static function maior(string $a, string $b): bool
    {
        return bccomp($a, $b, self::ESCALA) > 0;
    }

    /** Garante número válido em texto (aceita int/float/string vindos do banco). */
    public static function normalizar(string|int|float $valor): string
    {
        $texto = is_float($valor) ? sprintf('%.10F', $valor) : trim((string) $valor);

        if (!is_numeric($texto)) {
            throw new \InvalidArgumentException("Valor monetário inválido: {$texto}");
        }

        return $texto;
    }
}