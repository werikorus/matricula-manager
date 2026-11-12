/* Edição da tb_solicitacao: detecta o que mudou, confirma com "antes → depois"
   e envia só os campos alterados, cada um com o valor que a tela leu (o servidor
   recusa a gravação se o banco tiver mudado nesse meio-tempo). */
(() => {
  'use strict';

  const DADOS = window.SOLICITACAO || {};
  const ORIGINAIS = DADOS.originais || {};
  const $ = (seletor, raiz = document) => raiz.querySelector(seletor);
  const $$ = (seletor, raiz = document) => [...raiz.querySelectorAll(seletor)];

  const campos = $$('.campo-edicao').filter((c) => $('[data-entrada]', c));
  const botaoSalvar = $('#salvar');
  const botaoDescartar = $('#descartar');
  const dialogo = $('#confirmar-edicao');

  // ── Estado de cada campo ─────────────────────────────────

  function partes(campo) {
    return { entrada: $('[data-entrada]', campo), nulo: $('[data-nulo]', campo), desfazer: $('[data-desfazer]', campo) };
  }

  /** Valor atual do controle, normalizado do mesmo jeito que o inicial. */
  function atual(campo) {
    const { entrada, nulo } = partes(campo);
    if (nulo && nulo.checked) return { nulo: true, valor: null };
    return { nulo: false, valor: entrada.value };
  }

  const iniciais = new Map(campos.map((campo) => [campo, atual(campo)]));

  function alterado(campo) {
    const a = atual(campo);
    const i = iniciais.get(campo);
    if (a.nulo || i.nulo) return a.nulo !== i.nulo;
    return a.valor !== i.valor;
  }

  function sincronizarNulo(campo) {
    const { entrada, nulo } = partes(campo);
    if (!nulo) return;
    entrada.classList.toggle('controle-nulo', nulo.checked);
    entrada.setAttribute('aria-disabled', String(nulo.checked));
  }

  function desenhar() {
    let total = 0;
    for (const campo of campos) {
      const mudou = alterado(campo);
      campo.classList.toggle('campo-alterado', mudou);
      partes(campo).desfazer.hidden = !mudou;
      if (mudou) total++;
    }
    botaoSalvar.disabled = total === 0;
    botaoDescartar.disabled = total === 0;
    botaoSalvar.textContent = total ? `Salvar ${total} ${total === 1 ? 'alteração' : 'alterações'}` : 'Salvar alterações';

    // Grupo recolhido ainda mostra no título quantos campos dele foram alterados.
    for (const grupo of $$('.grupo-campos')) {
      const n = $$('.campo-alterado', grupo).length;
      const selo = $('[data-alterados]', grupo);
      selo.hidden = n === 0;
      selo.textContent = n === 1 ? '1 alterado' : `${n} alterados`;
    }

    filtrar();
    return total;
  }

  function restaurar(campo) {
    const { entrada, nulo } = partes(campo);
    const i = iniciais.get(campo);
    if (nulo) nulo.checked = i.nulo;
    entrada.value = i.nulo ? '' : i.valor;
    // <select> sem a opção vazia: volta para a opção marcada no HTML.
    if (entrada.tagName === 'SELECT' && entrada.value !== (i.valor ?? '')) {
      for (const opcao of entrada.options) opcao.selected = opcao.defaultSelected;
    }
    sincronizarNulo(campo);
  }

  for (const campo of campos) {
    const { entrada, nulo, desfazer } = partes(campo);
    sincronizarNulo(campo);

    entrada.addEventListener('input', () => {
      // Digitou num campo marcado como NULL: deixa de ser NULL.
      if (nulo && nulo.checked) { nulo.checked = false; sincronizarNulo(campo); }
      desenhar();
    });
    entrada.addEventListener('change', desenhar);
    if (nulo) nulo.addEventListener('change', () => { sincronizarNulo(campo); desenhar(); });
    desfazer.addEventListener('click', () => { restaurar(campo); desenhar(); entrada.focus(); });
  }

  botaoDescartar.addEventListener('click', () => {
    if (!window.confirm('Descartar todas as alterações não gravadas?')) return;
    campos.forEach(restaurar);
    desenhar();
  });

  // ── Busca e filtros ──────────────────────────────────────

  const busca = $('#campos-busca');
  const soPreenchidos = $('#so-preenchidos');
  const soAlterados = $('#so-alterados');

  function filtrar() {
    const termo = busca.value.trim().toLowerCase();
    let visiveis = 0;
    for (const campo of $$('.campo-edicao')) {
      const mostra = (!termo || campo.dataset.campo.includes(termo))
        && (!soPreenchidos.checked || campo.dataset.preenchido === '1' || campo.classList.contains('campo-alterado'))
        && (!soAlterados.checked || campo.classList.contains('campo-alterado'));
      campo.hidden = !mostra;
      if (mostra) visiveis++;
    }
    const filtrando = termo !== '' || soPreenchidos.checked || soAlterados.checked;
    for (const grupo of $$('.grupo-campos')) {
      const temVisivel = $$('.campo-edicao', grupo).some((c) => !c.hidden);
      grupo.hidden = !temVisivel;
      // Buscando ou filtrando: abre os grupos que têm resultado, senão eles ficariam escondidos.
      if (filtrando && temVisivel) grupo.open = true;
    }
    $('#campos-vazio').hidden = visiveis > 0;
  }

  $('#expandir-grupos').addEventListener('click', () => { for (const g of $$('.grupo-campos')) g.open = true; });
  $('#recolher-grupos').addEventListener('click', () => { for (const g of $$('.grupo-campos')) g.open = false; });

  [busca, soPreenchidos, soAlterados].forEach((el) => el.addEventListener('input', filtrar));

  // ── Confirmação e envio ──────────────────────────────────

  function mostrar(valor) {
    if (valor === null || valor === undefined) return { texto: 'NULL', nulo: true };
    const texto = String(valor);
    if (texto === '') return { texto: '(vazio)', nulo: true };
    return { texto: texto.length > 160 ? `${texto.slice(0, 160)}… (${texto.length} caracteres)` : texto, nulo: false };
  }

  function celula(valor) {
    const td = document.createElement('td');
    const { texto, nulo } = mostrar(valor);
    td.textContent = texto;
    if (nulo) td.className = 'valor-nulo';
    return td;
  }

  botaoSalvar.addEventListener('click', () => {
    const alteracoes = {};
    const corpo = $('#diferencas-corpo');
    corpo.replaceChildren();

    for (const campo of campos.filter(alterado)) {
      const nome = campo.dataset.campo;
      const a = atual(campo);
      const original = Object.prototype.hasOwnProperty.call(ORIGINAIS, nome) ? ORIGINAIS[nome] : null;
      alteracoes[nome] = { valor: a.valor, nulo: a.nulo, original };

      const linha = document.createElement('tr');
      const nomeTd = document.createElement('td');
      const code = document.createElement('code');
      code.textContent = nome;
      nomeTd.append(code);
      linha.append(nomeTd, celula(original), celula(a.nulo ? null : a.valor));
      corpo.append(linha);
    }

    const total = Object.keys(alteracoes).length;
    if (!total) return;

    // Campos que o painel preenche sozinho em toda gravação.
    for (const [nome, previsto] of Object.entries(DADOS.automaticos || {})) {
      if (!Object.prototype.hasOwnProperty.call(ORIGINAIS, nome)) continue;
      const linha = document.createElement('tr');
      linha.className = 'diferenca-automatica';
      const nomeTd = document.createElement('td');
      const code = document.createElement('code');
      code.textContent = nome;
      nomeTd.append(code);
      const depois = document.createElement('td');
      depois.textContent = previsto;
      linha.append(nomeTd, celula(ORIGINAIS[nome]), depois);
      corpo.append(linha);
    }
    $('#alteracoes').value = JSON.stringify(alteracoes);
    $('#qtd-alteracoes').textContent = `${total} ${total === 1 ? 'alteração' : 'alterações'}`;
    const confirmar = $('#confirmar-salvar');
    confirmar.disabled = false;
    confirmar.textContent = 'Gravar no banco';
    dialogo.showModal();
  });

  let enviando = false;
  $('#form-salvar').addEventListener('submit', () => {
    enviando = true;
    const confirmar = $('#confirmar-salvar');
    confirmar.disabled = true;
    confirmar.textContent = 'Gravando…';
    setTimeout(() => dialogo.close(), 0);
  });

  // Avisa antes de sair com alterações não gravadas.
  window.addEventListener('beforeunload', (evento) => {
    if (enviando || !campos.some(alterado)) return;
    evento.preventDefault();
    evento.returnValue = '';
  });

  // ── Rascunho: depois de um erro ao gravar, reaplica o que foi digitado ──

  if (DADOS.rascunho) {
    let rascunho = {};
    try { rascunho = JSON.parse(DADOS.rascunho) || {}; } catch { rascunho = {}; }
    for (const campo of campos) {
      const item = rascunho[campo.dataset.campo];
      if (!item) continue;
      const { entrada, nulo } = partes(campo);
      if (nulo) nulo.checked = !!item.nulo;
      if (!item.nulo) entrada.value = item.valor ?? '';
      sincronizarNulo(campo);
    }
  }

  // Campo com erro do servidor: destaca e leva até ele.
  const comErro = $$('[data-erro-campo]').find((e) => e.textContent.trim() !== '');
  for (const e of $$('[data-erro-campo]')) e.closest('.campo-edicao').classList.toggle('campo-com-erro', e.textContent.trim() !== '');

  desenhar();

  // Grupos com campos alterados (rascunho após um erro) ou com erro do servidor abrem sozinhos.
  for (const grupo of $$('.grupo-campos')) {
    if ($('.campo-alterado, .campo-com-erro', grupo)) grupo.open = true;
  }
  if (comErro) comErro.closest('.campo-edicao').scrollIntoView({ block: 'center' });
})();
