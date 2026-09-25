<?php

namespace App\Engine;

/**
 * MOTOR DE CÁLCULO — orquestra o cálculo do preço.
 *
 * É GENÉRICO: não conhece nenhuma regra de negócio específica
 * ("Estratégico", "Nacional", "5%"...). Ele apenas:
 *   1. parte do valor base do serviço
 *   2. aplica o fator da região
 *   3. percorre as regras ATIVAS em ordem de prioridade
 *   4. para cada uma, testa TODAS as condições (lógica E)
 *   5. se atendidas, aplica a ação sobre o subtotal
 *   6. se a regra tiver "interromper", para a avaliação ali
 *   7. calcula o total (unitário × quantidade) e devolve a memória
 *
 * É uma função "pura": recebe todos os dados prontos e não acessa o banco.
 * Por isso pode ser testada isoladamente (ver tests/MotorCalculoTest.php).
 */
final class MotorCalculo
{
    /**
     * @param array $dados [
     *   'servico'    => ['id', 'nome', 'valor_base'],
     *   'categoria'  => ['id', 'nome'],
     *   'regiao'     => ['id', 'nome', 'fator_preco'],
     *   'quantidade' => int,
     *   'faixa'      => ['id', 'descricao'] | null,
     *   'regras'     => [ [...regra, 'condicoes' => [...]], ... ],
     *   'nomes'      => ['SERVICO' => [id => nome], 'CATEGORIA' => [...], ...],
     *   'data'       => 'Y-m-d' (opcional; padrão hoje — usada na vigência)
     * ]
     */
    public function calcular(array $dados): array
    {
        $memoria    = new MemoriaCalculo();
        $quantidade = (int) $dados['quantidade'];
        $data       = $dados['data'] ?? date('Y-m-d');

        if ($quantidade <= 0) {
            throw new \InvalidArgumentException('A quantidade deve ser maior que zero.');
        }

        $memoria->registrarEntrada([
            'servico'    => ['id' => (int) $dados['servico']['id'],   'nome' => $dados['servico']['nome']],
            'categoria'  => ['id' => (int) $dados['categoria']['id'], 'nome' => $dados['categoria']['nome']],
            'regiao'     => ['id' => (int) $dados['regiao']['id'],    'nome' => $dados['regiao']['nome']],
            'quantidade' => $quantidade,
            'faixa'      => $dados['faixa']
                ? ['id' => (int) $dados['faixa']['id'], 'descricao' => $dados['faixa']['descricao']]
                : null,
            'data'       => $data,
        ]);

        // 1–2. Valor base × fator da região
        $valorBase = Dinheiro::arredondar(Dinheiro::normalizar($dados['servico']['valor_base']));
        $fator     = Dinheiro::normalizar($dados['regiao']['fator_preco']);
        $subtotal  = Dinheiro::arredondar(Dinheiro::multiplicar($valorBase, $fator));

        $memoria->registrarInicio($valorBase, $fator, $subtotal, $dados['regiao']['nome']);

        // Dados que as condições podem consultar (ver AvaliadorCondicao::CAMPOS)
        $contexto = [
            'servico_id'   => (int) $dados['servico']['id'],
            'categoria_id' => (int) $dados['categoria']['id'],
            'regiao_id'    => (int) $dados['regiao']['id'],
            'faixa_id'     => $dados['faixa'] ? (int) $dados['faixa']['id'] : null,
            'quantidade'   => $quantidade,
            'nomes'        => $dados['nomes'] ?? [],
        ];

        // 3–6. Regras em ordem de prioridade
        foreach (self::ordenar($dados['regras'] ?? []) as $regra) {
            if (!(int) ($regra['ativa'] ?? 1)) {
                continue; // defesa: regras inativas não participam
            }

            if (!self::vigente($regra, $data)) {
                $memoria->registrarRegra($regra, [], 'FORA_DE_VIGENCIA', 'Fora do período de vigência.');
                continue;
            }

            $condicoes = array_map(
                fn ($c) => AvaliadorCondicao::avaliar($c, $contexto),
                $regra['condicoes'] ?? []
            );

            $comErro = array_filter($condicoes, fn ($c) => isset($c['erro']));
            if ($comErro !== []) {
                $memoria->registrarRegra($regra, $condicoes, 'INVALIDA', 'Regra com condição inválida — ignorada.');
                continue;
            }

            $naoAtendidas = array_filter($condicoes, fn ($c) => !$c['atendida']);
            if ($naoAtendidas !== []) {
                $motivo = 'Condição não atendida: ' . implode('; ', array_column($naoAtendidas, 'descricao')) . '.';
                $memoria->registrarRegra($regra, $condicoes, 'NAO_ATENDIDA', $motivo);
                continue;
            }

            // Todas as condições atendidas (regra sem condições vale para todos os cálculos)
            $aplicacao = AplicadorAcao::aplicar(
                $subtotal,
                $regra['tipo_acao'],
                $regra['tipo_valor'],
                Dinheiro::normalizar($regra['valor'])
            );
            $subtotal = $aplicacao['valor_depois'];

            $motivo = $condicoes === [] ? 'Regra geral (sem condições).' : 'Todas as condições atendidas.';
            $memoria->registrarRegra($regra, $condicoes, 'APLICADA', $motivo, $aplicacao);

            if ((int) ($regra['interromper'] ?? 0)) {
                $memoria->registrarInterrupcao($regra['codigo']);
                break;
            }
        }

        // 7. Total
        $valorTotal = Dinheiro::arredondar(Dinheiro::multiplicar($subtotal, (string) $quantidade));

        return $memoria->finalizar($subtotal, $quantidade, $valorTotal);
    }

    /**
     * Descobre a faixa de utilização da quantidade.
     * quantidade_final NULL = faixa aberta ("101 ou superior").
     */
    public static function identificarFaixa(int $quantidade, array $faixas): ?array
    {
        foreach ($faixas as $faixa) {
            $inicio = (int) $faixa['quantidade_inicial'];
            $fim    = $faixa['quantidade_final'] === null ? null : (int) $faixa['quantidade_final'];

            if ($quantidade >= $inicio && ($fim === null || $quantidade <= $fim)) {
                return $faixa;
            }
        }

        return null;
    }

    /** Menor prioridade primeiro; empate → menor id (ordem sempre determinística). */
    private static function ordenar(array $regras): array
    {
        usort($regras, fn ($a, $b) =>
            [(int) $a['prioridade'], (int) $a['id']] <=> [(int) $b['prioridade'], (int) $b['id']]
        );

        return $regras;
    }

    private static function vigente(array $regra, string $data): bool
    {
        $inicio = $regra['vigencia_inicio'] ?? null;
        $fim    = $regra['vigencia_fim'] ?? null;

        return ($inicio === null || $data >= $inicio)
            && ($fim === null || $data <= $fim);
    }
}