/* Telas de listagem (PABs e matrículas paradas): filtro da tabela, atalhos
   de filtro e confirmação do "Reprocessar todos". Sem dependências. */
(() => {
  'use strict';

  const todos = document.getElementById('reprocessar-todos');
  const dialogo = document.getElementById('confirmar-todos');
  if (todos && dialogo) {
    todos.addEventListener('click', () => dialogo.showModal());
    dialogo.querySelector('form').addEventListener('submit', () => {
      const enviar = document.getElementById('confirmar-todos-enviar');
      enviar.disabled = true;
      enviar.textContent = 'Iniciando…';
      // O <dialog> fica acima de tudo; fecha para o overlay de carregamento aparecer.
      setTimeout(() => dialogo.close(), 0);
    });
  }

  // Matrículas: integrador em lote por ano letivo ou por processo.
  const formLote = document.getElementById('form-lote');
  if (formLote && dialogo) {
    const processo = document.getElementById('lote-processo');
    const linha = document.getElementById('lote-linha');
    const nota = document.getElementById('lote-nota');
    const enviar = document.getElementById('confirmar-todos-enviar');

    const atualizarLote = () => {
      const porProcesso = formLote.elements.filtro.value === 'processo';
      processo.disabled = !porProcesso;
      processo.required = porProcesso;
      const opcao = processo.selectedOptions[0];
      linha.textContent = porProcesso
        ? `${linha.dataset.comando} processo ${processo.value || '<processo>'}`
        : `${linha.dataset.comando} anoLetivo ${linha.dataset.ano}`;
      nota.textContent = porProcesso
        ? (processo.value ? `${opcao.dataset.total} solicitação(ões) deste processo na situação 14 ou 16, de qualquer data.` : 'Escolha o processo.')
        : 'O command não filtra pela data de inclusão: pode pegar solicitações que não aparecem nesta lista.';
      enviar.disabled = porProcesso && !processo.value;
    };

    formLote.addEventListener('change', atualizarLote);
    atualizarLote();

    // Atalho na tabela: o código do processo abre o diálogo com ele escolhido.
    document.addEventListener('click', (evento) => {
      const botao = evento.target.closest('[data-lote-processo]');
      if (!botao) return;
      formLote.elements.filtro.value = 'processo';
      processo.disabled = false;
      processo.value = botao.dataset.loteProcesso;
      atualizarLote();
      dialogo.showModal();
      processo.focus();
    });
  }

  // Matrículas: reprocessar uma solicitação (atualiza o banco e roda o integrador).
  const dialogoUm = document.getElementById('confirmar-um');
  const formUm = document.getElementById('form-reprocessar-um');
  if (dialogoUm && formUm) {
    document.addEventListener('click', (evento) => {
      const botao = evento.target.closest('[data-reprocessar-solicitacao]');
      if (!botao) return;
      const codigo = botao.dataset.reprocessarSolicitacao;
      formUm.action = formUm.dataset.acao.replace('__codigo__', encodeURIComponent(codigo));
      const valores = { codigo, aluno: botao.dataset.aluno, situacao: botao.dataset.situacao, tentativas: botao.dataset.tentativas };
      for (const campo of formUm.querySelectorAll('[data-campo]')) campo.textContent = valores[campo.dataset.campo] ?? '';
      const enviar = document.getElementById('confirmar-um-enviar');
      enviar.disabled = false;
      enviar.textContent = 'Atualizar e integrar';
      dialogoUm.showModal();
    });
    formUm.addEventListener('submit', () => {
      const enviar = document.getElementById('confirmar-um-enviar');
      enviar.disabled = true;
      enviar.textContent = 'Iniciando…';
      // O <dialog> fica acima de tudo; fecha para o overlay de carregamento aparecer.
      setTimeout(() => dialogoUm.close(), 0);
    });
  }

  const busca = document.getElementById('tabela-busca');
  const corpo = document.getElementById('tabela-corpo');
  if (!busca || !corpo) return;

  const atalhos = [...document.querySelectorAll('.atalho[data-filtro]')];
  const barras = [...document.querySelectorAll('[data-paginacao]')];
  const linhas = [...corpo.rows];
  const TAMANHOS = [25, 50, 100];

  // Página e tamanho ficam na URL: o "Atualizar" e o voltar do navegador mantêm a posição.
  const url = new URL(window.location.href);
  const estado = {
    pagina: Math.max(1, parseInt(url.searchParams.get('pagina'), 10) || 1),
    tamanho: TAMANHOS.includes(parseInt(url.searchParams.get('por_pagina'), 10)) ? parseInt(url.searchParams.get('por_pagina'), 10) : TAMANHOS[0],
    filtradas: linhas,
  };

  function el(tag, attrs = {}, texto = '') {
    const no = document.createElement(tag);
    for (const [chave, valor] of Object.entries(attrs)) {
      if (valor === false || valor == null) continue;
      if (chave.startsWith('on')) no.addEventListener(chave.slice(2), valor);
      else no.setAttribute(chave, valor === true ? '' : valor);
    }
    no.textContent = texto;
    return no;
  }

  function guardarNaUrl() {
    const nova = new URL(window.location.href);
    if (estado.pagina > 1) nova.searchParams.set('pagina', estado.pagina); else nova.searchParams.delete('pagina');
    if (estado.tamanho !== TAMANHOS[0]) nova.searchParams.set('por_pagina', estado.tamanho); else nova.searchParams.delete('por_pagina');
    window.history.replaceState(null, '', nova);
    // O botão Atualizar recarrega mantendo página e tamanho.
    for (const link of document.querySelectorAll('a.botao[data-carregando]')) {
      if (link.closest('.topo')) link.search = nova.search;
    }
  }

  /** Números de página com reticências: 1 … 4 5 [6] 7 8 … 12 */
  function numeros(atual, total) {
    const lista = [];
    for (let n = 1; n <= total; n++) {
      if (n === 1 || n === total || Math.abs(n - atual) <= 2) lista.push(n);
      else if (lista[lista.length - 1] !== '…') lista.push('…');
    }
    return lista;
  }

  function irPara(pagina, rolar = true) {
    estado.pagina = pagina;
    desenhar();
    if (rolar) corpo.closest('.folha').scrollIntoView({ block: 'start', behavior: 'smooth' });
  }

  function desenhar() {
    const total = estado.filtradas.length;
    const paginas = Math.max(1, Math.ceil(total / estado.tamanho));
    estado.pagina = Math.min(estado.pagina, paginas);
    const inicio = (estado.pagina - 1) * estado.tamanho;
    const visiveis = new Set(estado.filtradas.slice(inicio, inicio + estado.tamanho));

    for (const linha of linhas) linha.hidden = !visiveis.has(linha);
    document.getElementById('tabela-vazia').hidden = total > 0;
    guardarNaUrl();

    for (const barra of barras) barra.hidden = total === 0;
    if (total === 0) return;
    for (const barra of barras) barra.replaceChildren(...montarBarra(total, paginas, inicio));
  }

  function montarBarra(total, paginas, inicio) {
    const botoes = el('div', { class: 'paginacao-paginas' });
    botoes.append(el('button', {
      type: 'button', class: 'pagina', 'aria-label': 'Página anterior',
      disabled: estado.pagina === 1, onclick: () => irPara(estado.pagina - 1),
    }, '‹'));
    for (const n of numeros(estado.pagina, paginas)) {
      botoes.append(n === '…'
        ? el('span', { class: 'pagina-reticencias', 'aria-hidden': 'true' }, '…')
        : el('button', {
          type: 'button', class: 'pagina', 'aria-label': `Página ${n}`,
          'aria-current': n === estado.pagina ? 'page' : null, onclick: () => irPara(n),
        }, String(n)));
    }
    botoes.append(el('button', {
      type: 'button', class: 'pagina', 'aria-label': 'Próxima página',
      disabled: estado.pagina === paginas, onclick: () => irPara(estado.pagina + 1),
    }, '›'));

    const tamanho = el('select', { class: 'paginacao-tamanho', 'aria-label': 'Itens por página' });
    for (const n of TAMANHOS) {
      const opcao = el('option', { value: n, selected: n === estado.tamanho }, `${n} por página`);
      tamanho.append(opcao);
    }
    tamanho.addEventListener('change', () => {
      // Mantém visível o primeiro item que estava na tela.
      const primeiro = (estado.pagina - 1) * estado.tamanho;
      estado.tamanho = parseInt(tamanho.value, 10);
      irPara(Math.floor(primeiro / estado.tamanho) + 1, false);
    });

    const fim = Math.min(inicio + estado.tamanho, total);
    return [
      el('span', { class: 'paginacao-info' }, `Mostrando ${inicio + 1}–${fim} de ${total}${total !== linhas.length ? ` (filtrado de ${linhas.length})` : ''}`),
      botoes,
      tamanho,
    ];
  }

  function filtrar() {
    const filtro = busca.value.trim().toLowerCase();
    estado.filtradas = filtro ? linhas.filter((linha) => linha.dataset.busca.includes(filtro)) : linhas;
    for (const atalho of atalhos) {
      atalho.setAttribute('aria-pressed', String(filtro !== '' && atalho.dataset.filtro.toLowerCase() === filtro));
    }
  }

  busca.addEventListener('input', () => { filtrar(); irPara(1, false); });
  for (const atalho of atalhos) {
    atalho.addEventListener('click', () => {
      const ativo = atalho.getAttribute('aria-pressed') === 'true';
      busca.value = ativo ? '' : atalho.dataset.filtro;
      filtrar();
      irPara(1, false);
    });
  }

  // O navegador pode restaurar o texto do filtro ao voltar pelo histórico.
  filtrar();
  desenhar();
})();
