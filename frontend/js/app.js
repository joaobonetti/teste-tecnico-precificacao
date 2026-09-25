/* =====================================================================
   app.js — inicialização do sistema: usuário logado, perfil, navegação
   entre abas e comportamento comum das janelas (modais).
   Carregado por último: usa Calculo, Regras e Cadastros.
   ===================================================================== */

const App = {
  usuario: null,

  get ehAdmin() {
    return this.usuario && this.usuario.perfil === 'ADMIN';
  },

  /** Cada aba sabe se carregar quando aberta. */
  abas: {
    calculo:   () => Calculo.iniciar(),
    regras:    () => Regras.iniciar(),
    cadastros: () => Cadastros.iniciar(),
    historico: () => Historico.carregar(),
  },

  async iniciar() {
    try {
      this.usuario = await Api.get('/auth/me');
    } catch (e) {
      window.location.href = 'index.html';
      return;
    }

    document.getElementById('usuario-nome').textContent = this.usuario.nome;
    const perfil = document.getElementById('usuario-perfil');
    perfil.textContent = this.ehAdmin ? 'Administrador' : 'Operador';
    perfil.classList.add(this.ehAdmin ? 'alerta' : 'info');

    // Operador não vê botões de alteração. (A proteção REAL está na API:
    // mesmo que alguém force o botão, o backend responde 403.)
    if (!this.ehAdmin) {
      document.body.classList.add('perfil-operador');
      document.querySelectorAll('.somente-admin').forEach(e => e.classList.add('oculto'));
    }

    document.querySelectorAll('.abas button').forEach(botao => {
      botao.addEventListener('click', () => { window.location.hash = botao.dataset.aba; });
    });
    window.addEventListener('hashchange', () => this.abrirAba());

    document.getElementById('botao-sair').addEventListener('click', async () => {
      try { await Api.post('/auth/logout'); } catch (e) { /* segue para o login */ }
      window.location.href = 'index.html';
    });

    // Qualquer elemento com data-fechar fecha a janela em que está
    document.querySelectorAll('dialog').forEach(dialogo => {
      dialogo.querySelectorAll('[data-fechar]').forEach(b => b.addEventListener('click', () => dialogo.close()));
    });

    this.abrirAba();
  },

  /** Abre a aba indicada no endereço (#regras) — permite atualizar a página sem perder a aba. */
  abrirAba() {
    const nome = window.location.hash.replace('#', '') || 'calculo';
    const aba = this.abas[nome] ? nome : 'calculo';

    // Trocar de aba (inclusive pelo "voltar" do navegador) fecha janelas abertas
    document.querySelectorAll('dialog[open]').forEach(d => d.close());

    document.querySelectorAll('.aba').forEach(s => s.classList.add('oculto'));
    document.getElementById(`aba-${aba}`).classList.remove('oculto');
    document.querySelectorAll('.abas button').forEach(b => b.classList.toggle('ativa', b.dataset.aba === aba));

    Promise.resolve(this.abas[aba]()).catch(erro => avisar(erro.message, 'erro'));
  },
};

document.addEventListener('DOMContentLoaded', () => App.iniciar());