/* Barra lateral recolhível: botão no rodapé do menu ou Ctrl+B (como no VS Code).
   O estado fica no navegador; o <head> já aplica antes de desenhar a página. */
(() => {
  'use strict';

  const raiz = document.documentElement;
  const botao = document.querySelector('[data-alternar-menu]');
  if (!botao) return;
  const texto = botao.querySelector('[data-menu-texto]');

  const recolhido = () => raiz.dataset.menu === 'recolhido';

  function desenhar() {
    const fechado = recolhido();
    botao.setAttribute('aria-expanded', String(!fechado));
    botao.setAttribute('aria-label', fechado ? 'Expandir menu' : 'Recolher menu');
    botao.dataset.dica = fechado ? 'Expandir menu (Ctrl+B)' : 'Recolher menu (Ctrl+B)';
    if (texto) texto.textContent = fechado ? 'Expandir menu' : 'Recolher menu';
  }

  function alternar() {
    if (recolhido()) delete raiz.dataset.menu;
    else raiz.dataset.menu = 'recolhido';
    try { localStorage.setItem('painel.menu', recolhido() ? 'recolhido' : 'aberto'); } catch (e) { /* vale só nesta página */ }
    desenhar();
  }

  botao.addEventListener('click', alternar);

  document.addEventListener('keydown', (evento) => {
    if ((evento.ctrlKey || evento.metaKey) && !evento.shiftKey && !evento.altKey && evento.key.toLowerCase() === 'b') {
      // Não atrapalha quem está digitando num campo.
      if (evento.target.closest('input, textarea, select, [contenteditable]')) return;
      evento.preventDefault();
      alternar();
    }
  });

  // A dica do tema acompanha o texto do botão de tema.
  const tema = document.querySelector('.lateral [data-alternar-tema]');
  if (tema) {
    const copiar = () => { tema.dataset.dica = tema.querySelector('[data-tema-texto]')?.textContent || 'Tema'; };
    new MutationObserver(copiar).observe(tema, { subtree: true, childList: true, characterData: true });
    copiar();
  }

  desenhar();

  // Só anima a partir de agora: na carga a página já vem no estado certo, sem deslizar.
  requestAnimationFrame(() => raiz.classList.add('menu-animado'));
})();
