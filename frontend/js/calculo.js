/* =====================================================================
   calculo.js — aba "Cálculo" (executar) e aba "Histórico" (consultar).
   A mesma função desenha a memória de cálculo nas duas abas.
   ===================================================================== */

const SITUACOES = {
  APLICADA:         { texto: 'Aplicada',          classe: 'sucesso' },
  NAO_ATENDIDA:     { texto: 'Não atendida',      classe: '' },
  FORA_DE_VIGENCIA: { texto: 'Fora da vigência',  classe: 'alerta' },
  INVALIDA:         { texto: 'Inválida',          classe: 'erro' },
};

const Calculo = {
  iniciado: false,

  async iniciar() {
    const form = document.getElementById('form-calculo');

    if (!this.iniciado) {
      form.addEventListener('submit', (e) => { e.preventDefault(); this.calcular(form); });
      this.iniciado = true;
    }

    // Recarrega as opções a cada abertura: um cadastro novo já aparece aqui
    const [servicos, categorias, regioes] = await Promise.all([
      Api.get('/servicos?ativos=1'),
      Api.get('/categorias?ativos=1'),
      Api.get('/regioes?ativos=1'),
    ]);

    this.preencherSelect(form.servico_id, servicos, s => `${s.nome} — ${moeda(s.valor_base)}`);
    this.preencherSelect(form.categoria_id, categorias, c => c.nome);
    this.preencherSelect(form.regiao_id, regioes, r => `${r.nome} (fator ${numeroBr(r.fator_preco)})`);
  },

  /** Preenche um <select> mantendo a opção escolhida, se ainda existir. */
  preencherSelect(select, itens, rotulo) {
    const atual = select.value;
    select.innerHTML = '<option value="">Selecione...</option>'
      + itens.map(i => `<option value="${i.id}">${esc(rotulo(i))}</option>`).join('');
    if (itens.some(i => String(i.id) === atual)) select.value = atual;
  },

  async calcular(form) {
    limparErrosFormulario(form);
    const botao = form.querySelector('button[type="submit"]');

    await comBotaoOcupado(botao, async () => {
      try {
        const resultado = await Api.post('/calculos', {
          servico_id:   form.servico_id.value,
          categoria_id: form.categoria_id.value,
          regiao_id:    form.regiao_id.value,
          quantidade:   form.quantidade.value,
        });
        const alvo = document.getElementById('resultado-calculo');
        alvo.innerHTML = this.renderizarResultado(resultado);
        alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (erro) {
        mostrarErrosFormulario(form, erro);
      }
    });
  },

  /** Desenha a memória de cálculo completa (usada também no Histórico). */
  renderizarResultado(r) {
    const e = r.entrada;
    const fatorAlterou = Number(r.fator_regiao) !== 1;

    const indicadores = `
      <div class="indicadores">
        <div class="indicador"><div class="rotulo">Valor base</div><div class="valor">${moeda(r.valor_base)}</div></div>
        ${fatorAlterou ? `<div class="indicador"><div class="rotulo">Com fator da região (× ${numeroBr(r.fator_regiao)})</div><div class="valor">${moeda(r.valor_inicial)}</div></div>` : ''}
        <div class="indicador"><div class="rotulo">Descontos (por unidade)</div><div class="valor desconto">− ${moeda(r.total_descontos)}</div></div>
        <div class="indicador"><div class="rotulo">Acréscimos (por unidade)</div><div class="valor acrescimo">+ ${moeda(r.total_acrescimos)}</div></div>
        <div class="indicador destaque"><div class="rotulo">Valor unitário final</div><div class="valor">${moeda(r.valor_unitario_final)}</div></div>
        <div class="indicador destaque"><div class="rotulo">Total (${r.quantidade} un.)</div><div class="valor">${moeda(r.valor_total)}</div></div>
      </div>`;

    const linhas = r.regras_avaliadas.map(regra => {
      const s = SITUACOES[regra.situacao] || { texto: regra.situacao, classe: '' };
      const condicoes = regra.condicoes.length
        ? '<ul class="condicao-lista">' + regra.condicoes.map(c =>
            `<li>${c.atendida ? '✔' : '✘'} ${esc(c.descricao)}${c.atendida ? '' : ` <span class="suave">(informado: ${esc(c.valor_informado ?? '—')})</span>`}</li>`
          ).join('') + '</ul>'
        : '<span class="suave">Sem condições (regra geral)</span>';
      const aplicada = regra.situacao === 'APLICADA';
      const sinal = regra.tipo_acao === 'DESCONTO' ? '−' : '+';

      return `
        <tr>
          <td class="num">${regra.prioridade}</td>
          <td><strong>${esc(regra.codigo)}</strong><br><span class="pequeno">${esc(regra.nome)}</span></td>
          <td>${condicoes}${aplicada ? '' : `<div class="pequeno suave">${esc(regra.motivo)}</div>`}</td>
          <td>${esc(regra.acao)}</td>
          <td><span class="etiqueta ${s.classe}">${s.texto}</span></td>
          <td class="num">${aplicada ? moeda(regra.valor_antes) : '—'}</td>
          <td class="num">${aplicada ? `${sinal} ${moeda(regra.ajuste)}` : '—'}</td>
          <td class="num">${aplicada ? `<strong>${moeda(regra.valor_depois)}</strong>` : '—'}</td>
        </tr>`;
    }).join('');

    return `
      <div class="cartao">
        <div class="cartao-cabecalho">
          <h2>Resultado${r.id ? ` — cálculo nº ${r.id}` : ''}</h2>
          <span class="suave pequeno">
            ${esc(e.servico.nome)} · ${esc(e.categoria.nome)} · ${esc(e.regiao.nome)} ·
            ${e.quantidade} un. · Faixa: ${esc(e.faixa ? e.faixa.descricao : 'nenhuma')}
          </span>
        </div>

        ${indicadores}

        ${r.interrompido_por ? `<div class="alerta-caixa info">A regra <strong>${esc(r.interrompido_por)}</strong> interrompeu a avaliação: as regras seguintes não foram consideradas.</div>` : ''}

        <h3>Regras avaliadas (${r.regras_avaliadas.length}) — aplicadas: ${r.regras_aplicadas.length ? esc(r.regras_aplicadas.join(', ')) : 'nenhuma'}</h3>
        <div class="tabela-rolagem">
          <table>
            <thead><tr>
              <th class="num">Prior.</th><th>Regra</th><th>Condições</th><th>Ação</th><th>Situação</th>
              <th class="num">Antes</th><th class="num">Ajuste</th><th class="num">Depois</th>
            </tr></thead>
            <tbody>${linhas || '<tr><td colspan="8" class="vazio">Nenhuma regra ativa.</td></tr>'}</tbody>
          </table>
        </div>

        <h3 style="margin-top:1.5rem">Memória de cálculo</h3>
        <pre class="memoria-texto">${esc(r.memoria_texto.join('\n'))}</pre>
      </div>`;
  },
};

/* ---------------------------------------------------------------------
   Aba Histórico
   --------------------------------------------------------------------- */
const Historico = {
  iniciado: false,

  async carregar() {
    if (!this.iniciado) {
      document.getElementById('botao-atualizar-historico').addEventListener('click', () => this.carregar());
      this.iniciado = true;
    }

    const calculos = await Api.get('/calculos?limite=100');
    const alvo = document.getElementById('lista-historico');

    if (!calculos.length) {
      alvo.innerHTML = '<p class="vazio">Nenhum cálculo realizado ainda.</p>';
      return;
    }

    alvo.innerHTML = `
      <table>
        <thead><tr>
          <th class="num">Nº</th><th>Data</th><th>Serviço</th><th>Categoria</th><th>Região</th>
          <th class="num">Qtd.</th><th>Regras aplicadas</th><th class="num">Unitário</th><th class="num">Total</th><th>Usuário</th>
        </tr></thead>
        <tbody>
          ${calculos.map(c => `
            <tr class="clicavel" data-id="${c.id}" title="Ver memória de cálculo">
              <td class="num">${c.id}</td>
              <td>${dataHora(c.criado_em)}</td>
              <td>${esc(c.servico)}</td>
              <td>${esc(c.categoria)}</td>
              <td>${esc(c.regiao)}</td>
              <td class="num">${c.quantidade}</td>
              <td>${c.regras_aplicadas.length ? c.regras_aplicadas.map(r => `<span class="etiqueta info">${esc(r)}</span>`).join(' ') : '<span class="suave">—</span>'}</td>
              <td class="num">${moeda(c.valor_unitario_final)}</td>
              <td class="num"><strong>${moeda(c.valor_total)}</strong></td>
              <td>${esc(c.usuario || '')}</td>
            </tr>`).join('')}
        </tbody>
      </table>`;

    alvo.querySelectorAll('tr[data-id]').forEach(tr => {
      tr.addEventListener('click', () => this.abrir(tr.dataset.id));
    });
  },

  async abrir(id) {
    try {
      const calculo = await Api.get(`/calculos/${id}`);
      document.getElementById('titulo-modal-calculo').textContent =
        `Cálculo nº ${calculo.id} — ${dataHora(calculo.criado_em)} por ${calculo.usuario || '—'}`;
      document.getElementById('conteudo-modal-calculo').innerHTML = Calculo.renderizarResultado(calculo);
      document.getElementById('modal-calculo').showModal();
    } catch (erro) {
      avisar(erro.message, 'erro');
    }
  },
};