/* =====================================================================
   regras.js — aba "Regras": listagem, editor (condições + ação +
   prioridade), ativação/desativação e histórico de alterações.

   O editor NÃO tem listas fixas de campos/operadores: tudo vem de
   GET /api/regras/metadados. Campo ou operador novo no motor aparece
   aqui sem alterar este arquivo.
   ===================================================================== */

const Regras = {
  iniciado: false,
  meta: null,          // metadados: campos, operadores, opções dos cadastros
  regraEditando: null, // null = nova regra

  async iniciar() {
    if (!this.iniciado) {
      document.getElementById('filtro-regras').addEventListener('change', () => this.carregar());
      document.getElementById('botao-nova-regra').addEventListener('click', () => this.abrirEditor(null));
      document.getElementById('botao-add-condicao').addEventListener('click', () => this.adicionarCondicao());
      document.getElementById('form-regra').addEventListener('submit', (e) => { e.preventDefault(); this.salvar(); });
      this.iniciado = true;
    }

    this.meta = await Api.get('/regras/metadados');
    await this.carregar();
  },

  campo(codigo)    { return this.meta.campos.find(c => c.codigo === codigo); },
  operador(codigo) { return this.meta.operadores.find(o => o.codigo === codigo); },

  /** Nome do cadastro a partir do id (3 → "Estratégico"). */
  nomeOpcao(campo, id) {
    const def = this.campo(campo);
    const opcao = def && def.opcoes.find(o => String(o.id) === String(id));
    return opcao ? opcao.nome : id;
  },

  /** "Categoria = Estratégico", "Quantidade entre 51 e 100"... */
  descreverCondicao(c) {
    const def = this.campo(c.campo);
    const rotulo = def ? def.rotulo : c.campo;
    const op = this.operador(c.operador);
    const nome = v => this.nomeOpcao(c.campo, v);

    if (c.operador === 'ENTRE') return `${rotulo} entre ${nome(c.valor)} e ${nome(c.valor_final)}`;
    if (c.operador === 'EM') return `${rotulo} em ${String(c.valor).split(',').map(v => nome(v.trim())).join(', ')}`;
    return `${rotulo} ${op ? op.simbolo : c.operador} ${nome(c.valor)}`;
  },

  descreverAcao(r) {
    const tipo = r.tipo_acao === 'DESCONTO' ? 'Desconto' : 'Acréscimo';
    return r.tipo_valor === 'PERCENTUAL'
      ? `${tipo} de ${numeroBr(r.valor)}%`
      : `${tipo} de ${moeda(r.valor)}`;
  },

  async carregar() {
    const filtro = document.getElementById('filtro-regras').value;
    const regras = await Api.get('/regras' + (filtro !== '' ? `?ativa=${filtro}` : ''));
    const alvo = document.getElementById('lista-regras');

    if (!regras.length) {
      alvo.innerHTML = '<p class="vazio">Nenhuma regra encontrada.</p>';
      return;
    }

    alvo.innerHTML = `
      <table>
        <thead><tr>
          <th class="num">Prior.</th><th>Regra</th><th>Quando</th><th>Aplicar</th><th>Opções</th><th>Status</th><th>Ações</th>
        </tr></thead>
        <tbody>
          ${regras.map(r => `
            <tr class="${r.ativa ? '' : 'inativo'}">
              <td class="num">${r.prioridade}</td>
              <td><strong>${esc(r.codigo)}</strong><br><span class="pequeno">${esc(r.nome)}</span></td>
              <td>${r.condicoes.length
                    ? '<ul class="condicao-lista">' + r.condicoes.map(c => `<li>${esc(this.descreverCondicao(c))}</li>`).join('') + '</ul>'
                    : '<span class="suave">Todos os cálculos</span>'}</td>
              <td>${esc(this.descreverAcao(r))}</td>
              <td class="pequeno">
                ${r.interromper ? '<span class="etiqueta alerta">Interrompe</span><br>' : ''}
                ${r.vigencia_inicio || r.vigencia_fim
                  ? `Vigência: ${r.vigencia_inicio ? dataBr(r.vigencia_inicio) : '…'} a ${r.vigencia_fim ? dataBr(r.vigencia_fim) : '…'}`
                  : ''}
              </td>
              <td><span class="etiqueta ${r.ativa ? 'sucesso' : ''}">${r.ativa ? 'Ativa' : 'Inativa'}</span></td>
              <td>
                <div class="acoes">
                  ${App.ehAdmin ? `
                    <button class="botao secundario pequeno" data-acao="editar" data-id="${r.id}">Editar</button>
                    <button class="botao ${r.ativa ? 'perigo' : 'secundario'} pequeno" data-acao="status" data-id="${r.id}" data-ativa="${r.ativa}">
                      ${r.ativa ? 'Desativar' : 'Ativar'}
                    </button>` : ''}
                  <button class="botao secundario pequeno" data-acao="historico" data-id="${r.id}">Histórico</button>
                </div>
              </td>
            </tr>`).join('')}
        </tbody>
      </table>`;

    const porId = Object.fromEntries(regras.map(r => [r.id, r]));
    alvo.querySelectorAll('button[data-acao]').forEach(botao => {
      const regra = porId[botao.dataset.id];
      botao.addEventListener('click', () => {
        if (botao.dataset.acao === 'editar') this.abrirEditor(regra);
        if (botao.dataset.acao === 'status') this.alternarStatus(regra, botao);
        if (botao.dataset.acao === 'historico') this.abrirHistorico(regra);
      });
    });
  },

  async alternarStatus(regra, botao) {
    const acao = regra.ativa ? 'desativar' : 'ativar';
    if (!confirm(`Deseja ${acao} a regra ${regra.codigo} — ${regra.nome}?`)) return;

    await comBotaoOcupado(botao, async () => {
      try {
        await Api.patch(`/regras/${regra.id}/status`, { ativa: !regra.ativa });
        avisar(`Regra ${regra.codigo} ${regra.ativa ? 'desativada' : 'ativada'}.`);
        await this.carregar();
      } catch (erro) {
        avisar(erro.message, 'erro');
      }
    });
  },

  /* ------------------------- Editor ------------------------- */

  abrirEditor(regra) {
    this.regraEditando = regra;
    const form = document.getElementById('form-regra');
    form.reset();
    limparErrosFormulario(form);

    document.getElementById('titulo-modal-regra').textContent =
      regra ? `Editar regra ${regra.codigo}` : 'Nova regra';
    form.codigo.placeholder = regra ? '' : `automático (${this.meta.proximo_codigo})`;

    // Na edição, ativar/desativar é feito pelo botão da lista (fica registrado na auditoria)
    document.getElementById('grupo-ativa').classList.toggle('oculto', !!regra);

    if (regra) {
      form.codigo.value = regra.codigo;
      form.nome.value = regra.nome;
      form.descricao.value = regra.descricao || '';
      form.tipo_acao.value = regra.tipo_acao;
      form.tipo_valor.value = regra.tipo_valor;
      form.valor.value = Number(regra.valor);
      form.prioridade.value = regra.prioridade;
      form.vigencia_inicio.value = regra.vigencia_inicio || '';
      form.vigencia_fim.value = regra.vigencia_fim || '';
      form.interromper.checked = regra.interromper;
    }

    const lista = document.getElementById('lista-condicoes');
    lista.innerHTML = '';
    (regra ? regra.condicoes : []).forEach(c => this.adicionarCondicao(c));

    document.getElementById('modal-regra').showModal();
    form.nome.focus();
  },

  /** Adiciona uma linha "campo | operador | valor" ao editor. */
  adicionarCondicao(condicao = null) {
    const lista = document.getElementById('lista-condicoes');
    const linha = document.createElement('div');
    linha.className = 'condicao-linha';

    linha.innerHTML = `
      <select class="sel-campo" aria-label="Campo">
        ${this.meta.campos.map(c => `<option value="${c.codigo}">${esc(c.rotulo)}</option>`).join('')}
      </select>
      <select class="sel-operador" aria-label="Operador"></select>
      <div class="valores"></div>
      <button type="button" class="botao perigo pequeno" title="Remover condição">✕</button>
      <span class="erro-campo oculto"></span>`;

    const selCampo = linha.querySelector('.sel-campo');
    const selOperador = linha.querySelector('.sel-operador');

    selCampo.addEventListener('change', () => { this.montarOperadores(linha); this.montarValores(linha); });
    selOperador.addEventListener('change', () => this.montarValores(linha));
    linha.querySelector('button').addEventListener('click', () => { linha.remove(); this.renumerarCondicoes(); });

    if (condicao) selCampo.value = condicao.campo;
    this.montarOperadores(linha);
    if (condicao) selOperador.value = condicao.operador;
    this.montarValores(linha, condicao);

    lista.appendChild(linha);
    this.renumerarCondicoes();
  },

  /** Cada linha recebe data-erro="condicoes.N" para exibir o erro vindo da API. */
  renumerarCondicoes() {
    document.querySelectorAll('#lista-condicoes .condicao-linha').forEach((linha, i) => {
      linha.querySelector('.erro-campo').dataset.erro = `condicoes.${i}`;
    });
  },

  /** Operadores permitidos para o tipo do campo (">" não vale para Categoria). */
  montarOperadores(linha) {
    const campo = this.campo(linha.querySelector('.sel-campo').value);
    const sel = linha.querySelector('.sel-operador');
    const atual = sel.value;

    const permitidos = this.meta.operadores.filter(o => campo.tipo === 'numero' || !o.somente_numericos);
    sel.innerHTML = permitidos.map(o => `<option value="${o.codigo}">${esc(o.simbolo)}</option>`).join('');
    if (permitidos.some(o => o.codigo === atual)) sel.value = atual;
  },

  /** Monta a entrada do valor conforme campo e operador. */
  montarValores(linha, condicao = null) {
    const campo = this.campo(linha.querySelector('.sel-campo').value);
    const operador = linha.querySelector('.sel-operador').value;
    const alvo = linha.querySelector('.valores');
    const valor = condicao ? condicao.valor : '';
    const nomeOpcao = o => esc(o.nome) + (o.ativo ? '' : ' (inativo)');

    if (campo.tipo === 'cadastro' && operador === 'EM') {
      const marcados = String(valor).split(',').map(v => v.trim());
      alvo.innerHTML = `<div class="lista-opcoes">${campo.opcoes.map(o => `
        <label class="check"><input type="checkbox" value="${o.id}" ${marcados.includes(String(o.id)) ? 'checked' : ''}> ${nomeOpcao(o)}</label>`).join('')}</div>`;
    } else if (campo.tipo === 'cadastro') {
      alvo.innerHTML = `<select class="val-1" aria-label="Valor">
        <option value="">Selecione...</option>
        ${campo.opcoes.map(o => `<option value="${o.id}" ${String(o.id) === String(valor) ? 'selected' : ''}>${nomeOpcao(o)}</option>`).join('')}
      </select>`;
    } else if (operador === 'ENTRE') {
      alvo.innerHTML = `
        <input class="val-1" type="number" step="any" placeholder="de" value="${esc(valor)}" aria-label="Valor inicial">
        <span>e</span>
        <input class="val-2" type="number" step="any" placeholder="até" value="${esc(condicao ? condicao.valor_final : '')}" aria-label="Valor final">`;
    } else if (operador === 'EM') {
      alvo.innerHTML = `<input class="val-1" placeholder="ex.: 10, 20, 30" value="${esc(valor)}" aria-label="Lista de valores">`;
    } else {
      alvo.innerHTML = `<input class="val-1" type="number" step="any" value="${esc(valor)}" aria-label="Valor">`;
    }
  },

  coletarCondicoes() {
    return [...document.querySelectorAll('#lista-condicoes .condicao-linha')].map(linha => {
      const marcados = [...linha.querySelectorAll('.lista-opcoes input:checked')].map(i => i.value);
      const v1 = linha.querySelector('.val-1');
      const v2 = linha.querySelector('.val-2');
      return {
        campo: linha.querySelector('.sel-campo').value,
        operador: linha.querySelector('.sel-operador').value,
        valor: linha.querySelector('.lista-opcoes') ? marcados.join(',') : (v1 ? v1.value : ''),
        valor_final: v2 ? v2.value : null,
      };
    });
  },

  async salvar() {
    const form = document.getElementById('form-regra');
    limparErrosFormulario(form);

    const dados = {
      codigo: form.codigo.value,
      nome: form.nome.value,
      descricao: form.descricao.value,
      tipo_acao: form.tipo_acao.value,
      tipo_valor: form.tipo_valor.value,
      valor: form.valor.value,
      prioridade: form.prioridade.value,
      vigencia_inicio: form.vigencia_inicio.value,
      vigencia_fim: form.vigencia_fim.value,
      interromper: form.interromper.checked,
      ativa: form.ativa.checked,
      condicoes: this.coletarCondicoes(),
    };

    const botao = document.getElementById('botao-salvar-regra');
    await comBotaoOcupado(botao, async () => {
      try {
        const salva = this.regraEditando
          ? await Api.put(`/regras/${this.regraEditando.id}`, dados)
          : await Api.post('/regras', dados);

        document.getElementById('modal-regra').close();
        avisar(`Regra ${salva.codigo} salva. Já vale para os próximos cálculos.`);
        this.meta = await Api.get('/regras/metadados');   // atualiza o próximo código
        await this.carregar();
      } catch (erro) {
        mostrarErrosFormulario(form, erro);
      }
    });
  },

  /* ------------------------- Histórico (auditoria) ------------------------- */

  async abrirHistorico(regra) {
    try {
      const historico = await Api.get(`/regras/${regra.id}/historico`);
      document.getElementById('titulo-historico-regra').textContent = `Histórico — ${regra.codigo} · ${regra.nome}`;

      const ACOES = {
        CRIACAO:     ['Criação', 'info'],
        ALTERACAO:   ['Alteração', 'alerta'],
        ATIVACAO:    ['Ativação', 'sucesso'],
        DESATIVACAO: ['Desativação', 'erro'],
      };

      document.getElementById('conteudo-historico-regra').innerHTML = historico.length
        ? `<ul class="linha-tempo">${historico.map(h => {
            const [texto, classe] = ACOES[h.acao] || [h.acao, ''];
            return `
              <li>
                <span class="etiqueta ${classe}">${texto}</span>
                <strong>${dataHora(h.criado_em)}</strong>
                <span class="suave">por ${esc(h.usuario || 'sistema')}</span>
                ${h.acao === 'ALTERACAO' ? this.tabelaMudancas(h.dados_anteriores, h.dados_novos) : ''}
              </li>`;
          }).join('')}</ul>`
        : '<p class="vazio">Sem registros.</p>';

      document.getElementById('modal-historico-regra').showModal();
    } catch (erro) {
      avisar(erro.message, 'erro');
    }
  },

  /** Mostra só o que mudou entre a versão anterior e a nova. */
  tabelaMudancas(antes, depois) {
    if (!antes || !depois) return '';

    const texto = {
      codigo: r => r.codigo,
      nome: r => r.nome,
      descricao: r => r.descricao || '—',
      prioridade: r => r.prioridade,
      acao: r => this.descreverAcao(r),
      interromper: r => (r.interromper ? 'Sim' : 'Não'),
      vigencia: r => `${r.vigencia_inicio ? dataBr(r.vigencia_inicio) : '…'} a ${r.vigencia_fim ? dataBr(r.vigencia_fim) : '…'}`,
      condicoes: r => (r.condicoes || []).map(c => this.descreverCondicao(c)).join('; ') || 'nenhuma',
    };
    const rotulos = {
      codigo: 'Código', nome: 'Nome', descricao: 'Descrição', prioridade: 'Prioridade',
      acao: 'Ação', interromper: 'Interromper', vigencia: 'Vigência', condicoes: 'Condições',
    };

    const linhas = Object.keys(texto)
      .map(chave => [chave, String(texto[chave](antes)), String(texto[chave](depois))])
      .filter(([, a, d]) => a !== d)
      .map(([chave, a, d]) => `<tr><th>${rotulos[chave]}</th><td>${esc(a)}</td><td>→</td><td><strong>${esc(d)}</strong></td></tr>`)
      .join('');

    return linhas
      ? `<table class="mudancas"><tbody>${linhas}</tbody></table>`
      : '<div class="pequeno suave">Salva sem mudanças nos dados.</div>';
  },
};