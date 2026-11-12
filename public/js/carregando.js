/* Overlay de carregamento da Bernoulli.
   - Links e formulários com data-carregando mostram o overlay até a próxima
     página chegar; o valor do atributo vira a mensagem.
   - window.mostrarCarregando(texto) / window.esconderCarregando() para uso manual. */
(() => {
  'use strict';

  const overlay = document.getElementById('carregando');
  if (!overlay) return;
  const texto = document.getElementById('carregando-texto');
  let atraso = null;

  function mostrar(mensagem) {
    texto.textContent = mensagem || 'Carregando…';
    // Um pequeno atraso evita o "piscar" quando a página responde na hora.
    clearTimeout(atraso);
    atraso = setTimeout(() => { overlay.hidden = false; }, 150);
  }

  function esconder() {
    clearTimeout(atraso);
    overlay.hidden = true;
  }

  window.mostrarCarregando = mostrar;
  window.esconderCarregando = esconder;

  document.addEventListener('click', (evento) => {
    const link = evento.target.closest('a[data-carregando]');
    if (!link || evento.defaultPrevented || evento.button !== 0
      || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey
      || link.target === '_blank') return;
    mostrar(link.dataset.carregando);
  });

  document.addEventListener('submit', (evento) => {
    const form = evento.target.closest('form[data-carregando]');
    if (form && !evento.defaultPrevented) mostrar(form.dataset.carregando);
  });

  // Ao voltar pelo histórico o navegador pode restaurar a página com o overlay aberto.
  window.addEventListener('pageshow', esconder);
})();
