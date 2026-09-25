<?php

namespace App\Engine;

/**
 * Aplica a AÇÃO de uma regra sobre o subtotal.
 */
final class AplicadorAcao
{
    public const TIPOS_ACAO  = ['DESCONTO', 'ACRESCIMO'];
    public const TIPOS_VALOR = ['PERCENTUAL', 'VALOR_FIXO'];

    /**
     * @return array ['valor_antes', 'ajuste', 'valor_depois', 'descricao']
     *               ajuste é sempre positivo; o sinal vem do tipo_acao
     */
    public static function aplicar(string $subtotal, string $tipoAcao, string $tipoValor, string $valor): array
    {
        if (!in_array($tipoAcao, self::TIPOS_ACAO, true) || !in_array($tipoValor, self::TIPOS_VALOR, true)) {
            throw new \InvalidArgumentException("Ação inválida: {$tipoAcao}/{$tipoValor}");
        }

        $ajuste = $tipoValor === 'PERCENTUAL'
            ? Dinheiro::arredondar(Dinheiro::percentual($subtotal, $valor))
            : Dinheiro::arredondar($valor);

        // Um desconto nunca deixa o preço negativo (ex.: R$ 50 de desconto em R$ 30 → R$ 0,00)
        if ($tipoAcao === 'DESCONTO' && Dinheiro::maior($ajuste, $subtotal)) {
            $ajuste = Dinheiro::arredondar($subtotal);
        }

        $depois = $tipoAcao === 'DESCONTO'
            ? Dinheiro::subtrair($subtotal, $ajuste)
            : Dinheiro::somar($subtotal, $ajuste);

        return [
            'valor_antes'  => Dinheiro::arredondar($subtotal),
            'ajuste'       => $ajuste,
            'valor_depois' => Dinheiro::arredondar($depois),
            'descricao'    => self::descrever($tipoAcao, $tipoValor, $valor),
        ];
    }

    /** Ex.: "Desconto de 5%", "Acréscimo de R$ 10,00" */
    public static function descrever(string $tipoAcao, string $tipoValor, string $valor): string
    {
        $nome = $tipoAcao === 'DESCONTO' ? 'Desconto' : 'Acréscimo';

        return $tipoValor === 'PERCENTUAL'
            ? "{$nome} de " . self::formatarPercentual($valor) . '%'
            : "{$nome} de R$ " . number_format((float) $valor, 2, ',', '.');
    }

    /** "5.0000" → "5" ; "7.5000" → "7,5" */
    private static function formatarPercentual(string $valor): string
    {
        $texto = rtrim(rtrim(bcadd($valor, '0', 4), '0'), '.');

        return str_replace('.', ',', $texto);
    }
}