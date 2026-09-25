<?php

namespace App\Engine;

/**
 * MEMÓRIA DE CÁLCULO — o "extrato" de como o preço foi formado.
 */
final class MemoriaCalculo
{
    private array $entrada = [];
    private array $inicio = [];
    private array $regras = [];
    private array $linhas = [];
    private ?string $interrompidoPor = null;
    private string $totalDescontos = '0';
    private string $totalAcrescimos = '0';

    public function registrarEntrada(array $entrada): void
    {
        $this->entrada = $entrada;
    }

    /** Valor base do serviço e ajuste do fator de região. */
    public function registrarInicio(string $valorBase, string $fator, string $valorInicial, string $regiao): void
    {
        $this->inicio = [
            'valor_base'    => $valorBase,
            'fator_regiao'  => $fator,
            'valor_inicial' => $valorInicial,
        ];

        $this->linhas[] = 'Valor base: ' . self::moeda($valorBase);

        // Fator 1,00 não altera o valor; só aparece no extrato quando muda algo
        if (bccomp($fator, '1', 4) !== 0) {
            $this->linhas[] = "Fator da região {$regiao}: × " . self::numero($fator)
                . ' = ' . self::moeda($valorInicial);
        }
    }

    /** Registra uma regra avaliada (aplicada ou não) e o motivo. */
    public function registrarRegra(array $regra, array $condicoes, string $situacao, string $motivo, ?array $aplicacao = null): void
    {
        $item = [
            'id'         => (int) $regra['id'],
            'codigo'     => $regra['codigo'],
            'nome'       => $regra['nome'],
            'prioridade' => (int) $regra['prioridade'],
            'situacao'   => $situacao,        // APLICADA | NAO_ATENDIDA | FORA_DE_VIGENCIA | INVALIDA
            'aplicada'   => $situacao === 'APLICADA',
            'motivo'     => $motivo,
            'condicoes'  => $condicoes,
            'acao'       => AplicadorAcao::descrever($regra['tipo_acao'], $regra['tipo_valor'], (string) $regra['valor']),
        ];

        if ($aplicacao !== null) {
            $item['tipo_acao']    = $regra['tipo_acao'];
            $item['valor_antes']  = $aplicacao['valor_antes'];
            $item['ajuste']       = $aplicacao['ajuste'];
            $item['valor_depois'] = $aplicacao['valor_depois'];

            if ($regra['tipo_acao'] === 'DESCONTO') {
                $this->totalDescontos = bcadd($this->totalDescontos, $aplicacao['ajuste'], 2);
            } else {
                $this->totalAcrescimos = bcadd($this->totalAcrescimos, $aplicacao['ajuste'], 2);
            }

            // Bloco de texto no formato do enunciado
            $this->linhas[] = '';
            $this->linhas[] = "Regra {$regra['codigo']} — {$regra['nome']}:";
            foreach ($condicoes as $c) {
                $this->linhas[] = '  ' . $c['descricao'];
            }
            $sinal = $regra['tipo_acao'] === 'DESCONTO' ? '−' : '+';
            $this->linhas[] = "  {$item['acao']} ({$sinal} " . self::moeda($aplicacao['ajuste']) . ')';
            $this->linhas[] = 'Subtotal: ' . self::moeda($aplicacao['valor_depois']);
        }

        $this->regras[] = $item;
    }

    public function registrarInterrupcao(string $codigoRegra): void
    {
        $this->interrompidoPor = $codigoRegra;
        $this->linhas[] = "  (regra {$codigoRegra} interrompe a avaliação das regras seguintes)";
    }

    /** Monta o resultado final completo. */
    public function finalizar(string $valorUnitario, int $quantidade, string $valorTotal): array
    {
        $this->linhas[] = '';
        $this->linhas[] = 'Valor unitário final: ' . self::moeda($valorUnitario);
        $this->linhas[] = "Quantidade: {$quantidade}";
        $this->linhas[] = 'Valor total: ' . self::moeda($valorTotal);

        $aplicadas = array_values(array_filter($this->regras, fn ($r) => $r['aplicada']));

        return [
            'entrada'              => $this->entrada,
            'valor_base'           => $this->inicio['valor_base'],
            'fator_regiao'         => $this->inicio['fator_regiao'],
            'valor_inicial'        => $this->inicio['valor_inicial'],
            'regras_avaliadas'     => $this->regras,
            'regras_aplicadas'     => array_column($aplicadas, 'codigo'),
            'total_descontos'      => $this->totalDescontos,   // por unidade
            'total_acrescimos'     => $this->totalAcrescimos,  // por unidade
            'interrompido_por'     => $this->interrompidoPor,
            'valor_unitario_final' => $valorUnitario,
            'quantidade'           => $quantidade,
            'valor_total'          => $valorTotal,
            'memoria_texto'        => $this->linhas,
        ];
    }

    /** 1234.5 → "R$ 1.234,50" */
    public static function moeda(string $valor): string
    {
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    /** 1.25 → "1,25" */
    private static function numero(string $valor): string
    {
        return number_format((float) $valor, 2, ',', '.');
    }
}