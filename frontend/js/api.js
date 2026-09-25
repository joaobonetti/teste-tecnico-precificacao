/* =====================================================================
   api.js — comunicação com o backend + utilitários de tela.
   Carregado antes dos demais scripts; tudo o que outros arquivos usam
   fica no objeto global "Api" e em funções auxiliares abaixo.
   ===================================================================== */

/** Erro vindo da API, com o status HTTP e os erros por campo (422). */
class ErroApi extends Error {
  constructor(mensagem, status, detalhes) {
    super(mensagem);
    this.status = status;
    this.detalhes = detalhes || {};
  }
}

const Api = {
  /**
   * Chamada genérica. Sempre devolve o conteúdo de "dados" da resposta
   * ou lança ErroApi. Sessão expirada (401) volta para o login.
   */
  async requisicao(metodo, url, corpo) {
    const opcoes = {
      method: metodo,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',   // envia o cookie de sessão
    };
    if (corpo !== undefined) {
      opcoes.headers['Content-Type'] = 'application/json';
      opcoes.body = JSON.stringify(corpo);
    }

    let resposta;
    try {
      resposta = await fetch('/api' + url, opcoes);
    } catch (e) {
      throw new ErroApi('Não foi possível conectar ao servidor.', 0);
    }

    let json = null;
    try { json = await resposta.json(); } catch (e) { /* resposta sem JSON */ }

    // Sessão expirada dentro do sistema → volta para o login
    // (na própria página de login, apenas devolve o erro, sem redirecionar)
    const naPaginaDeLogin = !window.location.pathname.endsWith('app.html');
    if (resposta.status === 401 && !naPaginaDeLogin) {
      window.location.href = 'index.html';
      throw new ErroApi('Sessão expirada. Faça login novamente.', 401);
    }

    if (!resposta.ok || !json || json.sucesso === false) {
      const erro = (json && json.erro) || {};
      throw new ErroApi(erro.mensagem || `Erro ${resposta.status}`, resposta.status, erro.detalhes);
    }

    return json.dados;
  },

  get(url)          { return this.requisicao('GET', url); },
  post(url, corpo)  { return this.requisicao('POST', url, corpo); },
  put(url, corpo)   { return this.requisicao('PUT', url, corpo); },
  patch(url, corpo) { return this.requisicao('PATCH', url, corpo); },
};

/* ---------------------------------------------------------------------
   Utilitários
   --------------------------------------------------------------------- */

/**
 * Escapa texto antes de colocar em HTML. TODO dado vindo da API passa por
 * aqui: impede que um nome como "<script>..." seja executado (XSS).
 */
function esc(valor) {
  if (valor === null || valor === undefined) return '';
  return String(valor)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** 1234.5 → "R$ 1.234,50" */
function moeda(valor) {
  return Number(valor || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

/** "7.5000" → "7,5" */
function numeroBr(valor, maxCasas = 4) {
  return Number(valor || 0).toLocaleString('pt-BR', { maximumFractionDigits: maxCasas });
}

/** "2026-09-24 18:10:00" → "24/09/2026 18:10" */
function dataHora(texto) {
  if (!texto) return '';
  const [data, hora = ''] = String(texto).split(' ');
  const [a, m, d] = data.split('-');
  return `${d}/${m}/${a}${hora ? ' ' + hora.slice(0, 5) : ''}`;
}

/** "2026-12-31" → "31/12/2026" */
function dataBr(texto) {
  if (!texto) return '';
  const [a, m, d] = String(texto).split('-');
  return `${d}/${m}/${a}`;
}

/** Mensagem flutuante no canto da tela. */
function avisar(mensagem, tipo = 'sucesso') {
  let caixa = document.getElementById('avisos');
  if (!caixa) {
    caixa = document.createElement('div');
    caixa.id = 'avisos';
    document.body.appendChild(caixa);
  }
  const aviso = document.createElement('div');
  aviso.className = `aviso ${tipo}`;
  aviso.textContent = mensagem;
  caixa.appendChild(aviso);
  setTimeout(() => aviso.remove(), 4000);
}

/**
 * Mostra os erros de validação (422) no formulário: marca cada campo e
 * escreve a mensagem logo abaixo. Erros sem campo correspondente vão para
 * a caixa de alerta do formulário.
 */
function mostrarErrosFormulario(form, erro) {
  limparErrosFormulario(form);

  const alerta = form.querySelector('.alerta-caixa');
  const semCampo = [];

  Object.entries(erro.detalhes || {}).forEach(([campo, mensagem]) => {
    const alvo = form.querySelector(`[data-erro="${campo}"]`);
    const entrada = form.querySelector(`[name="${campo}"]`);
    if (entrada) entrada.classList.add('invalido');
    if (alvo) {
      alvo.textContent = mensagem;
      alvo.classList.remove('oculto');
    } else {
      semCampo.push(mensagem);
    }
  });

  if (alerta) {
    alerta.innerHTML = esc(erro.message)
      + (semCampo.length ? '<ul>' + semCampo.map(m => `<li>${esc(m)}</li>`).join('') + '</ul>' : '');
    alerta.classList.remove('oculto');
  } else {
    avisar(erro.message, 'erro');
  }
}

function limparErrosFormulario(form) {
  form.querySelectorAll('.invalido').forEach(e => e.classList.remove('invalido'));
  form.querySelectorAll('[data-erro]').forEach(e => { e.textContent = ''; e.classList.add('oculto'); });
  const alerta = form.querySelector('.alerta-caixa');
  if (alerta) alerta.classList.add('oculto');
}

/** Desabilita o botão enquanto a requisição acontece (evita clique duplo). */
async function comBotaoOcupado(botao, acao) {
  const texto = botao.textContent;
  botao.disabled = true;
  botao.textContent = 'Aguarde...';
  try {
    return await acao();
  } finally {
    botao.disabled = false;
    botao.textContent = texto;
  }
}