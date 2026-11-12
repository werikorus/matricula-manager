/* Alternador de tema claro/escuro (escuro = Dracula).
   A escolha fica no navegador (localStorage); sem escolha, segue o sistema. */
(() => {
  'use strict';

  const raiz = document.documentElement;
  const sistemaEscuro = window.matchMedia('(prefers-color-scheme: dark)');

  const atual = () => raiz.dataset.tema || (sistemaEscuro.matches ? 'escuro' : 'claro');

  function desenhar() {
    const escuro = atual() === 'escuro';
    for (const botao of document.querySelectorAll('[data-alternar-tema]')) {
      // O botão mostra para onde vai: no escuro oferece o claro, e vice-versa.
      botao.dataset.temaAtual = escuro ? 'escuro' : 'claro';
      botao.setAttribute('aria-label', escuro ? 'Mudar para o tema claro' : 'Mudar para o tema escuro (Dracula)');
      botao.title = botao.getAttribute('aria-label');
      const texto = botao.querySelector('[data-tema-texto]');
      if (texto) texto.textContent = escuro ? 'Tema claro' : 'Tema escuro';
    }
  }

  function alternar() {
    const novo = atual() === 'escuro' ? 'claro' : 'escuro';
    raiz.dataset.tema = novo;
    try { localStorage.setItem('painel.tema', novo); } catch (e) { /* sem armazenamento: vale só nesta página */ }
    desenhar();
  }

  document.addEventListener('click', (evento) => {
    if (evento.target.closest('[data-alternar-tema]')) alternar();
  });

  // Sem escolha salva, acompanha a troca do sistema em tempo real.
  sistemaEscuro.addEventListener('change', desenhar);

  // Outra aba trocou o tema: aplica aqui também.
  window.addEventListener('storage', (evento) => {
    if (evento.key !== 'painel.tema') return;
    if (evento.newValue === 'claro' || evento.newValue === 'escuro') raiz.dataset.tema = evento.newValue;
    else delete raiz.dataset.tema;
    desenhar();
  });

  desenhar();
})();
