/* Histórico de commands: escolhe uma execução na lista e mostra a saída dela
   no console ao lado (ao vivo, se ainda estiver rodando). */
(() => {
  'use strict';

  const H = window.HISTORICO;
  if (!H) return;
  const $ = (seletor) => document.querySelector(seletor);
  const corpo = $('#tabela-corpo');
  if (!corpo) return;
  const terminal = window.ConsoleTerminal($('#console-saida'));

  const estado = { execucao: null, offset: 0, geracao: 0, temporizador: null, relogio: null };

  async function api(url, opcoes = {}) {
    const resposta = await fetch(url, {
      credentials: 'same-origin',
      ...opcoes,
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': H.painel.csrf, 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (resposta.status === 401 || resposta.status === 419) {
      window.location.href = H.painel.urls.login;
      throw new Error('Sessão expirada.');
    }
    const json = await resposta.json().catch(() => null);
    if (!resposta.ok) throw new Error(json?.message || `Erro ${resposta.status}.`);
    return json;
  }

  function duracao(e) {
    if (!e.iniciado_em) return '';
    const fim = e.finalizado_em ? new Date(e.finalizado_em) : new Date();
    const s = Math.max(0, Math.round((fim - new Date(e.iniciado_em)) / 1000));
    if (s < 60) return `${s} s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m} min ${String(s % 60).padStart(2, '0')} s`;
    return `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min`;
  }

  function cabecalho() {
    const e = estado.execucao;
    $('#console').dataset.status = e ? e.status : 'vazio';
    $('#console-status').textContent = e ? `#${e.id} · ${H.rotulos[e.status] || e.status}` : 'Nenhuma execução selecionada';
    $('#console-linha').textContent = e ? e.linha : '';
    $('#console-linha').title = e ? e.linha : '';
    $('#console-tempo').textContent = e ? duracao(e) : '';
    $('#console-acoes').hidden = !e;
    if (!e) return;

    const dados = H.execucoes[e.id] || {};
    const repetir = $('#acao-repetir');
    repetir.hidden = !dados.repetir;
    if (dados.repetir) repetir.href = dados.repetir;

    const acompanhar = $('#acao-acompanhar');
    acompanhar.hidden = !e.ativa;
    acompanhar.href = `${H.commands}?execucao=${encodeURIComponent(e.id)}`;
    $('#acao-parar').hidden = e.status !== 'executando';
  }

  function marcarLinha() {
    for (const linha of corpo.rows) {
      linha.setAttribute('aria-selected', String(String(estado.execucao?.id) === linha.dataset.execucao));
    }
  }

  function abrir(id) {
    const dados = H.execucoes[id];
    if (!dados) return;
    clearTimeout(estado.temporizador);
    clearInterval(estado.relogio);
    estado.geracao += 1;
    estado.execucao = dados;
    estado.offset = 0;
    terminal.limpar();
    cabecalho();
    marcarLinha();

    const url = new URL(window.location.href);
    url.searchParams.set('id', id);
    window.history.replaceState(null, '', url);

    estado.relogio = setInterval(() => { if (estado.execucao?.ativa) $('#console-tempo').textContent = duracao(estado.execucao); }, 1000);
    consultar(estado.geracao);
  }

  async function consultar(geracao) {
    let resposta;
    try {
      resposta = await api(`${H.painel.urls.execucoes}/${estado.execucao.id}/saida?offset=${estado.offset}`);
    } catch (erro) {
      if (geracao !== estado.geracao) return;
      if (estado.offset === 0) $('#console-saida').textContent = `Não consegui ler a saída: ${erro.message}`;
      estado.temporizador = setTimeout(() => consultar(geracao), 3000);
      return;
    }
    if (geracao !== estado.geracao) return;

    const saida = $('#console-saida');
    const noFim = saida.scrollHeight - saida.scrollTop - saida.clientHeight < 40;
    if (resposta.saida) terminal.escrever(resposta.saida);
    if (noFim || estado.offset === 0) saida.scrollTop = saida.scrollHeight;
    if (!saida.textContent && !resposta.mais && !resposta.execucao.ativa) saida.textContent = '(execução sem saída)';

    estado.offset = resposta.offset;
    estado.execucao = { ...estado.execucao, ...resposta.execucao };
    cabecalho();

    if (resposta.execucao.ativa || resposta.mais) {
      estado.temporizador = setTimeout(() => consultar(geracao), resposta.mais ? 30 : 1000);
    } else {
      clearInterval(estado.relogio);
    }
  }

  // Clique ou Enter numa linha abre a execução.
  corpo.addEventListener('click', (evento) => {
    const linha = evento.target.closest('tr[data-execucao]');
    if (linha) abrir(linha.dataset.execucao);
  });
  corpo.addEventListener('keydown', (evento) => {
    const linha = evento.target.closest('tr[data-execucao]');
    if (linha && (evento.key === 'Enter' || evento.key === ' ')) { evento.preventDefault(); abrir(linha.dataset.execucao); }
  });

  $('#acao-copiar').addEventListener('click', async (evento) => {
    const botao = evento.currentTarget;
    const texto = $('#console-saida').textContent;
    try {
      await navigator.clipboard.writeText(texto);
    } catch {
      const campo = document.createElement('textarea');
      campo.value = texto;
      document.body.append(campo);
      campo.select();
      document.execCommand('copy');
      campo.remove();
    }
    botao.textContent = 'Copiado ✓';
    setTimeout(() => { botao.textContent = 'Copiar saída'; }, 1500);
  });

  $('#acao-parar').addEventListener('click', async () => {
    const e = estado.execucao;
    if (!e || !window.confirm('Parar esta execução? O command recebe um sinal de término e pode deixar o processamento pela metade.')) return;
    try {
      await api(`${H.painel.urls.execucoes}/${e.id}/parar`, { method: 'POST' });
    } catch (erro) {
      window.alert(erro.message);
    }
  });

  // Abre a execução pedida no endereço (?id=) ou a mais recente.
  const pedida = new URLSearchParams(window.location.search).get('id');
  abrir(pedida && H.execucoes[pedida] ? pedida : corpo.rows[0]?.dataset.execucao);
})();
