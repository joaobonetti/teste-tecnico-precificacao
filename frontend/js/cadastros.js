/* =====================================================================
   cadastros.js — aba "Cadastros": serviços, categorias, regiões e faixas.

   Os quatro cadastros usam o MESMO código: cada um é só uma
   configuração (colunas da tabela + campos do formulário).
   Um cadastro novo = uma entrada nova em CONFIG_CADASTROS.
   ===================================================================== */

const CONFIG_CADASTROS = {
  servicos: {
    titulo: 'Serviços', singular: 'serviço',
    colunas: [
      { rotulo: 'Código', valor: i => i.id, num: true },
      { rotulo: 'Nome', valor: i => esc(i.nome) },
      { rotulo: 'Descrição', valor: i => esc(i.descricao || '') },
      { rotulo: 'Valor base', valor: i => moeda(i.valor_base), num: true },
    ],
    campos: [
      { nome: 'nome', rotulo: 'Nome', max: 100 },
      { nome: 'descricao', rotulo: 'Descrição', max: 255 },
      { nome: 'valor_base', rotulo: 'Valor base (R$)', tipo: 'number', passo: '0.01', min: 0 },
    ],
  },
  categorias: {
    titulo: 'Categorias de cliente', singular: 'categoria',
    colunas: [
      { rotulo: 'Código', valor: i => i.id, num: true },
      { rotulo: 'Nome', valor: i => esc(i.nome) },
      { rotulo: 'Descrição', valor: i => esc(i.descricao || '') },
    ],
    campos: [
      { nome: 'nome', rotulo: 'Nome', max: 100 },
      { nome: 'descricao', rotulo: 'Descrição', max: 255 },
    ],
  },
  regioes: {
    titulo: 'Regiões', singular: 'região',
    colunas: [
      { rotulo: 'Código', valor: i => i.id, num: true },
      { rotulo: 'Nome', valor: i => esc(i.nome) },
      { rotulo: 'Fator de preço', valor: i => numeroBr(i.fator_preco), num: true },
      { rotulo: 'Efeito', valor: i => efeitoFator(i.fator_preco) },
    ],
    campos: [
      { nome: 'nome', rotulo: 'Nome', max: 100 },
      { nome: 'fator_preco', rotulo: 'Fator de preço', tipo: 'number', passo: '0.0001', min: 0.0001,
        ajuda: 'Multiplica o valor base: 1,00 = sem alteração; 1,10 = +10%; 0,90 = −10%.' },
    ],
  },
  faixas: {
    titulo: 'Faixas de utilização', singular: 'faixa',
    colunas: [
      { rotulo: 'Código', valor: i => i.id, num: true },
      { rotulo: 'Descrição', valor: i => esc(i.descricao) },
      { rotulo: 'Quantidade inicial', valor: i => i.quantidade_inicial, num: true },
      { rotulo: 'Quantidade final', valor: i => (i.quantidade_final ?? 'ou superior'), num: true },
      { rotulo: 'Acréscimo de referência', valor: i => `${numeroBr(i.percentual_acrescimo)}%`, num: true },
    ],
    campos: [
      { nome: 'descricao', rotulo: 'Descrição', max: 100 },
      { nome: 'quantidade_inicial', rotulo: 'Quantidade inicial', tipo: 'number', passo: '1', min: 1 },
      { nome: 'quantidade_final', rotulo: 'Quantidade final', tipo: 'number', passo: '1', min: 1,
        ajuda: 'Deixe em branco para "ou superior".' },
      { nome: 'percentual_acrescimo', rotulo: 'Acréscimo de referência (%)', tipo: 'number', passo: '0.01', min: 0,
        ajuda: 'Informativo. O acréscimo é aplicado por uma regra com a condição "Faixa = ...".' },
    ],
    aviso: 'A faixa é identificada automaticamente pela quantidade e pode ser usada como condição de regra. '
         + 'O percentual é apenas referência: quem aplica o acréscimo é a regra (evita cobrar duas vezes).',
  },
};

/** 1.10 → "+10%" ; 0.9 → "−10%" ; 1 → "sem alteração" */
function efeitoFator(fator) {
  const pct = Math.round((Number(fator) - 1) * 10000) / 100;
  if (pct === 0) return '<span class="suave">sem alteração</span>';
  return pct > 0 ? `<span class="etiqueta alerta">+${numeroBr(pct, 2)}%</span>` : `<span class="etiqueta sucesso">${numeroBr(pct, 2)}%</span>`;
}

const Cadastros = {
  iniciado: false,
  atual: 'servicos',
  editando: null,

  async iniciar() {
    if (!this.iniciado) {
      const subabas = document.getElementById('subabas-cadastro');
      subabas.innerHTML = Object.entries(CONFIG_CADASTROS)
        .map(([chave, cfg]) => `<button type="button" data-recurso="${chave}">${cfg.titulo}</button>`).join('');
      subabas.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        this.atual = b.dataset.recurso;
        this.carregar();
      }));

      document.getElementById('botao-novo-cadastro').addEventListener('click', () => this.abrirFormulario(null));
      document.getElementById('form-cadastro').addEventListener('submit', (e) => { e.preventDefault(); this.salvar(); });
      this.iniciado = true;
    }

    await this.carregar();
  },

  async carregar() {
    const cfg = CONFIG_CADASTROS[this.atual];

    document.querySelectorAll('#subabas-cadastro button')
      .forEach(b => b.classList.toggle('ativa', b.dataset.recurso === this.atual));
    document.getElementById('titulo-cadastro').textContent = cfg.titulo;

    const itens = await Api.get(`/${this.atual}`);
    const alvo = document.getElementById('lista-cadastro');

    alvo.innerHTML = `
      ${cfg.aviso ? `<div class="alerta-caixa info">${esc(cfg.aviso)}</div>` : ''}
      <table>
        <thead><tr>
          ${cfg.colunas.map(c => `<th class="${c.num ? 'num' : ''}">${c.rotulo}</th>`).join('')}
          <th>Status</th>${App.ehAdmin ? '<th>Ações</th>' : ''}
        </tr></thead>
        <tbody>
          ${itens.length ? itens.map(item => `
            <tr class="${item.ativo ? '' : 'inativo'}">
              ${cfg.colunas.map(c => `<td class="${c.num ? 'num' : ''}">${c.valor(item)}</td>`).join('')}
              <td><span class="etiqueta ${item.ativo ? 'sucesso' : ''}">${item.ativo ? 'Ativo' : 'Inativo'}</span></td>
              ${App.ehAdmin ? `
                <td><div class="acoes">
                  <button class="botao secundario pequeno" data-acao="editar" data-id="${item.id}">Editar</button>
                  <button class="botao ${item.ativo ? 'perigo' : 'secundario'} pequeno" data-acao="status" data-id="${item.id}">
                    ${item.ativo ? 'Desativar' : 'Ativar'}
                  </button>
                </div></td>` : ''}
            </tr>`).join('')
          : `<tr><td colspan="${cfg.colunas.length + 2}" class="vazio">Nenhum registro.</td></tr>`}
        </tbody>
      </table>`;

    const porId = Object.fromEntries(itens.map(i => [i.id, i]));
    alvo.querySelectorAll('button[data-acao]').forEach(botao => {
      const item = porId[botao.dataset.id];
      botao.addEventListener('click', () => {
        if (botao.dataset.acao === 'editar') this.abrirFormulario(item);
        if (botao.dataset.acao === 'status') this.alternarStatus(item, botao);
      });
    });
  },

  abrirFormulario(item) {
    const cfg = CONFIG_CADASTROS[this.atual];
    this.editando = item;

    document.getElementById('titulo-modal-cadastro').textContent =
      (item ? 'Editar ' : 'Novo(a) ') + cfg.singular;

    document.getElementById('campos-cadastro').innerHTML = cfg.campos.map(c => `
      <div class="campo">
        <label for="cad-${c.nome}">${c.rotulo}</label>
        <input id="cad-${c.nome}" name="${c.nome}" type="${c.tipo || 'text'}"
               ${c.passo ? `step="${c.passo}"` : ''} ${c.min !== undefined ? `min="${c.min}"` : ''}
               ${c.max ? `maxlength="${c.max}"` : ''}
               value="${esc(item ? (item[c.nome] ?? '') : '')}">
        ${c.ajuda ? `<span class="ajuda">${esc(c.ajuda)}</span>` : ''}
        <span class="erro-campo oculto" data-erro="${c.nome}"></span>
      </div>`).join('');

    const form = document.getElementById('form-cadastro');
    limparErrosFormulario(form);
    document.getElementById('modal-cadastro').showModal();
    form.querySelector('input').focus();
  },

  async salvar() {
    const cfg = CONFIG_CADASTROS[this.atual];
    const form = document.getElementById('form-cadastro');
    limparErrosFormulario(form);

    const dados = {};
    cfg.campos.forEach(c => { dados[c.nome] = form.elements[c.nome].value; });

    const botao = form.querySelector('button[type="submit"]');
    await comBotaoOcupado(botao, async () => {
      try {
        if (this.editando) {
          await Api.put(`/${this.atual}/${this.editando.id}`, dados);
        } else {
          await Api.post(`/${this.atual}`, dados);
        }
        document.getElementById('modal-cadastro').close();
        avisar('Registro salvo.');
        await this.carregar();
      } catch (erro) {
        mostrarErrosFormulario(form, erro);
      }
    });
  },

  async alternarStatus(item, botao) {
    const nome = item.nome || item.descricao;
    if (!confirm(`Deseja ${item.ativo ? 'desativar' : 'ativar'} "${nome}"?`)) return;

    await comBotaoOcupado(botao, async () => {
      try {
        await Api.patch(`/${this.atual}/${item.id}/status`, { ativo: !item.ativo });
        avisar(`"${nome}" ${item.ativo ? 'desativado(a)' : 'ativado(a)'}.`);
        await this.carregar();
      } catch (erro) {
        avisar(erro.message, 'erro');
      }
    });
  },
};