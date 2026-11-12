/* Copiar com um clique: elementos com data-copiar (ou o nome do aluno nas
   tabelas, .pab-aluno) copiam o próprio texto para a área de transferência. */
(() => {
  'use strict';

  const ALVOS = '[data-copiar], .pab-aluno';
  let aviso = null;
  let temporizador = null;

  function texto(alvo) {
    return (alvo.dataset.copiar || alvo.textContent || '').trim();
  }

  async function copiar(valor) {
    // A API moderna só existe em contexto seguro (https ou localhost).
    if (navigator.clipboard && window.isSecureContext) {
      try {
        // Alguns navegadores deixam a promessa pendurada esperando permissão:
        // depois de 1 s, desiste e usa o método antigo.
        await Promise.race([
          navigator.clipboard.writeText(valor),
          new Promise((_, recusar) => setTimeout(() => recusar(new Error('sem resposta')), 1000)),
        ]);
        return;
      } catch { /* recusada ou sem resposta: tenta o método antigo */ }
    }
    const campo = document.createElement('textarea');
    campo.value = valor;
    campo.setAttribute('readonly', '');
    campo.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.append(campo);
    campo.select();
    const ok = document.execCommand('copy');
    campo.remove();
    if (!ok) throw new Error('cópia recusada');
  }

  function avisar(alvo, mensagem, falhou = false) {
    if (!aviso) {
      aviso = document.createElement('div');
      aviso.className = 'aviso-copia';
      aviso.setAttribute('role', 'status');
      aviso.setAttribute('aria-live', 'polite');
      document.body.append(aviso);
    }
    const caixa = alvo.getBoundingClientRect();
    aviso.textContent = mensagem;
    aviso.classList.toggle('aviso-copia-falha', falhou);
    aviso.style.left = `${Math.max(8, caixa.left)}px`;
    aviso.style.top = `${Math.max(8, caixa.top - 34)}px`;
    aviso.classList.add('visivel');
    clearTimeout(temporizador);
    temporizador = setTimeout(() => aviso.classList.remove('visivel'), 1400);
  }

  async function acionar(alvo) {
    const valor = texto(alvo);
    if (!valor || valor === '—') return;
    try {
      await copiar(valor);
      alvo.classList.add('copiado');
      setTimeout(() => alvo.classList.remove('copiado'), 1400);
      avisar(alvo, 'Copiado');
    } catch {
      avisar(alvo, 'Não foi possível copiar', true);
    }
  }

  // Deixa os alvos acessíveis pelo teclado e com dica.
  for (const alvo of document.querySelectorAll(ALVOS)) {
    if (!texto(alvo) || texto(alvo) === '—') continue;
    alvo.classList.add('copiavel');
    alvo.setAttribute('role', 'button');
    alvo.setAttribute('tabindex', '0');
    alvo.title = 'Clique para copiar';
    alvo.setAttribute('aria-label', `Copiar: ${texto(alvo)}`);
  }

  document.addEventListener('click', (evento) => {
    const alvo = evento.target.closest('.copiavel');
    // Seleção de texto com o mouse continua funcionando: só copia num clique simples.
    if (!alvo || String(window.getSelection()).length > 0) return;
    acionar(alvo);
  });

  document.addEventListener('keydown', (evento) => {
    const alvo = evento.target.closest?.('.copiavel');
    if (!alvo || (evento.key !== 'Enter' && evento.key !== ' ')) return;
    evento.preventDefault();
    acionar(alvo);
  });
})();
