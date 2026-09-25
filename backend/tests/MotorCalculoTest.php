<?php
// =====================================================================
// TESTES AUTOMATIZADOS DO MOTOR DE CÁLCULO
// =====================================================================

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Engine\AplicadorAcao;
use App\Engine\AvaliadorCondicao;
use App\Engine\Dinheiro;
use App\Engine\MotorCalculo;
use App\Engine\Operadores;

// ---------------------------------------------------------------------
// Mini framework de testes
// ---------------------------------------------------------------------
$total = 0;
$falhas = [];

function teste(string $nome, callable $fn): void
{
    global $total, $falhas;
    $total++;
    try {
        $fn();
        echo "  ✔ {$nome}\n";
    } catch (Throwable $e) {
        $falhas[] = $nome;
        echo "  ✘ {$nome}\n      → {$e->getMessage()}\n";
    }
}

function igual(mixed $esperado, mixed $obtido, string $contexto = ''): void
{
    if ($esperado !== $obtido) {
        throw new RuntimeException(
            ($contexto ? "{$contexto}: " : '') . 'esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true)
        );
    }
}

// ---------------------------------------------------------------------
// Dados iguais ao 02_seed.sql
// ---------------------------------------------------------------------
const SERVICOS   = [1 => ['id' => 1, 'nome' => 'Suporte Técnico', 'valor_base' => '100.00'],
                    2 => ['id' => 2, 'nome' => 'Consultoria',     'valor_base' => '200.00'],
                    3 => ['id' => 3, 'nome' => 'Desenvolvimento', 'valor_base' => '300.00']];
const CATEGORIAS = [1 => ['id' => 1, 'nome' => 'Público'], 2 => ['id' => 2, 'nome' => 'Privado'], 3 => ['id' => 3, 'nome' => 'Estratégico']];
const REGIOES    = [1 => ['id' => 1, 'nome' => 'Local',    'fator_preco' => '1.0000'],
                    2 => ['id' => 2, 'nome' => 'Regional', 'fator_preco' => '1.1000'],
                    3 => ['id' => 3, 'nome' => 'Nacional', 'fator_preco' => '1.2500']];
const FAIXAS     = [['id' => 1, 'descricao' => '1 a 10',          'quantidade_inicial' => 1,   'quantidade_final' => 10],
                    ['id' => 2, 'descricao' => '11 a 50',         'quantidade_inicial' => 11,  'quantidade_final' => 50],
                    ['id' => 3, 'descricao' => '51 a 100',        'quantidade_inicial' => 51,  'quantidade_final' => 100],
                    ['id' => 4, 'descricao' => '101 ou superior', 'quantidade_inicial' => 101, 'quantidade_final' => null]];

function regra(int $id, string $codigo, int $prioridade, string $acao, string $valor, array $condicoes, array $extra = []): array
{
    return array_merge([
        'id' => $id, 'codigo' => $codigo, 'nome' => "Regra {$codigo}", 'prioridade' => $prioridade,
        'tipo_acao' => $acao, 'tipo_valor' => 'PERCENTUAL', 'valor' => $valor,
        'interromper' => 0, 'ativa' => 1, 'vigencia_inicio' => null, 'vigencia_fim' => null,
        'condicoes' => $condicoes,
    ], $extra);
}

function cond(string $campo, string $operador, string $valor, ?string $final = null): array
{
    return ['campo' => $campo, 'operador' => $operador, 'valor' => $valor, 'valor_final' => $final];
}

function regrasSeed(): array
{
    return [
        regra(1, 'R001', 10, 'DESCONTO',  '5.0000',  [cond('CATEGORIA', 'IGUAL', '3'), cond('QUANTIDADE', 'MAIOR', '50')]),
        regra(2, 'R002', 20, 'ACRESCIMO', '15.0000', [cond('REGIAO', 'IGUAL', '3'), cond('SERVICO', 'IGUAL', '3')]),
        regra(3, 'R003', 30, 'ACRESCIMO', '10.0000', [cond('QUANTIDADE', 'ENTRE', '51', '100')]),
        regra(4, 'R004', 40, 'ACRESCIMO', '5.0000',  [cond('FAIXA', 'IGUAL', '2')]),
        regra(5, 'R005', 50, 'ACRESCIMO', '20.0000', [cond('FAIXA', 'IGUAL', '4')]),
    ];
}

/** Monta a entrada do motor como o CalculoController fará com dados do banco. */
function calcular(int $servico, int $categoria, int $regiao, int $qtd, ?array $regras = null, string $data = '2026-09-24'): array
{
    return (new MotorCalculo())->calcular([
        'servico'    => SERVICOS[$servico],
        'categoria'  => CATEGORIAS[$categoria],
        'regiao'     => REGIOES[$regiao],
        'quantidade' => $qtd,
        'faixa'      => MotorCalculo::identificarFaixa($qtd, FAIXAS),
        'regras'     => $regras ?? regrasSeed(),
        'nomes'      => [
            'SERVICO'   => array_column(SERVICOS, 'nome', 'id'),
            'CATEGORIA' => array_column(CATEGORIAS, 'nome', 'id'),
            'REGIAO'    => array_column(REGIOES, 'nome', 'id'),
            'FAIXA'     => array_column(FAIXAS, 'descricao', 'id'),
        ],
        'data'       => $data,
    ]);
}

// =====================================================================
echo "\n▶ Exemplo do enunciado\n";
// =====================================================================

teste('Consultoria, Estratégico, Local, 60 un. → R$ 209,00 (R001 e R003)', function () {
    $r = calcular(2, 3, 1, 60);
    igual('209.00', $r['valor_unitario_final']);
    igual(['R001', 'R003'], $r['regras_aplicadas']);
    igual('12540.00', $r['valor_total'], 'total = 209 × 60');
    igual('10.00', $r['total_descontos']);
    igual('19.00', $r['total_acrescimos']);
    igual(5, count($r['regras_avaliadas']), 'todas as 5 regras avaliadas');
});

teste('Subtotal intermediário R$ 190,00 após R001', function () {
    $r = calcular(2, 3, 1, 60);
    igual('190.00', $r['regras_avaliadas'][0]['valor_depois']);
});

teste('Requisito principal: desconto 5% → 7% muda o resultado sem mudar código (R$ 204,60)', function () {
    $regras = regrasSeed();
    $regras[0]['valor'] = '7.0000';                       // "alteração feita pelo usuário"
    igual('204.60', calcular(2, 3, 1, 60, $regras)['valor_unitario_final']);  // 200 × 0,93 × 1,10
});

// =====================================================================
echo "\n▶ Demais regras do seed\n";
// =====================================================================

teste('R002: Desenvolvimento Nacional, 5 un. → 300 × 1,25 = 375 → +15% = R$ 431,25', function () {
    $r = calcular(3, 2, 3, 5);
    igual('375.00', $r['valor_inicial']);
    igual('431.25', $r['valor_unitario_final']);
    igual(['R002'], $r['regras_aplicadas']);
});

teste('R004: Suporte, Regional, 20 un. (faixa 11–50) → 110 → +5% = R$ 115,50', function () {
    $r = calcular(1, 1, 2, 20);
    igual('115.50', $r['valor_unitario_final']);
    igual(['R004'], $r['regras_aplicadas']);
});

teste('R005: Suporte, Estratégico, 150 un. → −5% (R001) → +20% (R005) = R$ 114,00', function () {
    $r = calcular(1, 3, 1, 150);
    igual('114.00', $r['valor_unitario_final']);
    igual(['R001', 'R005'], $r['regras_aplicadas']);
});

teste('Sem regra aplicável: Suporte, Privado, Local, 5 un. → R$ 100,00', function () {
    $r = calcular(1, 2, 1, 5);
    igual('100.00', $r['valor_unitario_final']);
    igual([], $r['regras_aplicadas']);
});

teste('Faixa 51–100 não duplica o acréscimo (só R003, sem regra de faixa)', function () {
    $r = calcular(2, 2, 1, 60);
    igual('220.00', $r['valor_unitario_final']);
    igual(['R003'], $r['regras_aplicadas']);
});

// =====================================================================
echo "\n▶ Prioridade, interrupção e conflitos\n";
// =====================================================================

teste('Interromper: Público −12% exclusivo → R$ 176,00 (R003 nem é avaliada)', function () {
    $regras = regrasSeed();
    $regras[] = regra(9, 'R900', 5, 'DESCONTO', '12', [cond('CATEGORIA', 'IGUAL', '1')], ['interromper' => 1]);
    $r = calcular(2, 1, 1, 60, $regras);
    igual('176.00', $r['valor_unitario_final']);
    igual('R900', $r['interrompido_por']);
    igual(1, count($r['regras_avaliadas']));
});

teste('Sem interromper a mesma regra somaria com R003 → R$ 193,60', function () {
    $regras = regrasSeed();
    $regras[] = regra(9, 'R900', 5, 'DESCONTO', '12', [cond('CATEGORIA', 'IGUAL', '1')]);
    igual('193.60', calcular(2, 1, 1, 60, $regras)['valor_unitario_final']);
});

teste('Valor fixo: a prioridade muda o resultado (R$ 198,00 × R$ 200,00)', function () {
    $fixo = regra(10, 'F1', 1, 'DESCONTO', '20', [], ['tipo_valor' => 'VALOR_FIXO']);
    $pct  = regra(11, 'P1', 2, 'ACRESCIMO', '10', []);
    igual('198.00', calcular(2, 2, 1, 1, [$fixo, $pct])['valor_unitario_final'], 'fixo antes');

    $fixo['prioridade'] = 3;
    igual('200.00', calcular(2, 2, 1, 1, [$fixo, $pct])['valor_unitario_final'], 'fixo depois');
});

teste('Mesma prioridade → desempate pelo id (ordem determinística)', function () {
    $a = regra(20, 'A', 1, 'DESCONTO', '20', [], ['tipo_valor' => 'VALOR_FIXO']);
    $b = regra(21, 'B', 1, 'ACRESCIMO', '10', []);
    igual(['A', 'B'], calcular(2, 2, 1, 1, [$b, $a])['regras_aplicadas']);
});

// =====================================================================
echo "\n▶ Ativação, vigência e robustez\n";
// =====================================================================

teste('Regra desativada não participa', function () {
    $regras = regrasSeed();
    $regras[0]['ativa'] = 0;
    igual('220.00', calcular(2, 3, 1, 60, $regras)['valor_unitario_final']);
});

teste('Regra fora da vigência é registrada e não aplicada', function () {
    $regras = [regra(30, 'PROMO', 1, 'DESCONTO', '50', [], ['vigencia_inicio' => '2026-12-01', 'vigencia_fim' => '2026-12-31'])];
    $r = calcular(2, 2, 1, 1, $regras, '2026-09-24');
    igual('200.00', $r['valor_unitario_final']);
    igual('FORA_DE_VIGENCIA', $r['regras_avaliadas'][0]['situacao']);
    igual('100.00', calcular(2, 2, 1, 1, $regras, '2026-12-15')['valor_unitario_final'], 'dentro da vigência');
});

teste('Condição inválida no banco é ignorada sem derrubar o cálculo', function () {
    $regras = [regra(31, 'X', 1, 'DESCONTO', '50', [cond('CAMPO_QUE_NAO_EXISTE', 'IGUAL', '1')])];
    $r = calcular(2, 2, 1, 1, $regras);
    igual('200.00', $r['valor_unitario_final']);
    igual('INVALIDA', $r['regras_avaliadas'][0]['situacao']);
});

teste('Desconto fixo maior que o subtotal zera, sem ficar negativo', function () {
    $r = calcular(1, 2, 1, 1, [regra(32, 'D', 1, 'DESCONTO', '500', [], ['tipo_valor' => 'VALOR_FIXO'])]);
    igual('0.00', $r['valor_unitario_final']);
});

teste('Quantidade zero é rejeitada', function () {
    try {
        calcular(1, 2, 1, 0);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('deveria lançar exceção');
});

// =====================================================================
echo "\n▶ Operadores, faixas e arredondamento\n";
// =====================================================================

teste('ENTRE é inclusivo: 51 e 100 entram; 50 e 101 não', function () {
    igual(true,  Operadores::avaliar('ENTRE', 51, '51', '100'));
    igual(true,  Operadores::avaliar('ENTRE', 100, '51', '100'));
    igual(false, Operadores::avaliar('ENTRE', 50, '51', '100'));
    igual(false, Operadores::avaliar('ENTRE', 101, '51', '100'));
});

teste('MAIOR 50 é estrito: 50 não passa, 51 passa', function () {
    igual(false, Operadores::avaliar('MAIOR', 50, '50'));
    igual(true,  Operadores::avaliar('MAIOR', 51, '50'));
});

teste('EM e DIFERENTE', function () {
    igual(true,  Operadores::avaliar('EM', 2, '2,3'));
    igual(false, Operadores::avaliar('EM', 1, '2, 3'));
    igual(true,  Operadores::avaliar('DIFERENTE', 1, '3'));
});

teste('Operador fora da whitelist é recusado', function () {
    igual(false, Operadores::avaliar('system("rm -rf")', 1, '1'));
});

teste('Identificação de faixa (inclusive faixa aberta 101+)', function () {
    igual(1, MotorCalculo::identificarFaixa(10, FAIXAS)['id']);
    igual(2, MotorCalculo::identificarFaixa(11, FAIXAS)['id']);
    igual(3, MotorCalculo::identificarFaixa(100, FAIXAS)['id']);
    igual(4, MotorCalculo::identificarFaixa(5000, FAIXAS)['id']);
    igual(null, MotorCalculo::identificarFaixa(0, FAIXAS));
});

teste('Validação de condições (usada no cadastro de regras)', function () {
    igual([], AvaliadorCondicao::validar(cond('QUANTIDADE', 'ENTRE', '51', '100')));
    igual(1, count(AvaliadorCondicao::validar(cond('CATEGORIA', 'MAIOR', '2'))), 'MAIOR em cadastro');
    igual(1, count(AvaliadorCondicao::validar(cond('QUANTIDADE', 'ENTRE', '100', '51'))), 'ENTRE invertido');
    igual(1, count(AvaliadorCondicao::validar(cond('QUANTIDADE', 'IGUAL', 'abc'))), 'valor não numérico');
});

teste('Arredondamento meio para cima e precisão exata (sem erro de float)', function () {
    igual('10.01', Dinheiro::arredondar('10.005'));
    igual('10.00', Dinheiro::arredondar('10.004'));
    igual('0.30', Dinheiro::arredondar(Dinheiro::somar('0.1', '0.2')));
    igual('3.33', AplicadorAcao::aplicar('33.33', 'ACRESCIMO', 'PERCENTUAL', '10')['ajuste']);  // 3,333 → 3,33
    igual('36.66', AplicadorAcao::aplicar('33.33', 'ACRESCIMO', 'PERCENTUAL', '10')['valor_depois']);
});

teste('Memória de cálculo em texto segue o formato do enunciado', function () {
    $texto = implode("\n", calcular(2, 3, 1, 60)['memoria_texto']);
    foreach (['Valor base: R$ 200,00', 'Categoria = Estratégico', 'Quantidade > 50', 'Desconto de 5%',
              'Subtotal: R$ 190,00', 'Quantidade entre 51 e 100', 'Acréscimo de 10%', 'Valor unitário final: R$ 209,00'] as $trecho) {
        if (!str_contains($texto, $trecho)) {
            throw new RuntimeException("trecho ausente: {$trecho}");
        }
    }
});

// ---------------------------------------------------------------------
echo "\n" . str_repeat('─', 60) . "\n";
echo count($falhas) === 0
    ? "✅ {$total} testes, todos passaram.\n"
    : '❌ ' . count($falhas) . " de {$total} testes falharam.\n";

exit(count($falhas) === 0 ? 0 : 1);