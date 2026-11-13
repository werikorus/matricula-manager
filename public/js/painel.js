/* Tela de commands: lista, formulário gerado a partir da definição do command,
   console com a saída ao vivo e histórico. Sem dependências e sem build. */
(() => {
  'use strict';

  const P = window.PAINEL;
  const $ = (seletor) => document.querySelector(seletor);
  const terminal = window.ConsoleTerminal($('#console-saida'));

  const ROTULOS = {
    pendente: 'Na fila',
    executando: 'Executando',
    sucesso: 'Concluído',
    falha: 'Falhou',
    interrompido: 'Interrompido',
  };

  const estado = {
    selecionado: null,   // command aberto no formulário
    execucao: null,      // execução exibida no console
    offset: 0,
    temporizador: null,
    relogio: null,
    geracao: 0,          // descarta respostas de uma execução que saiu da tela
    historico: [],
    filtro: '',
    logsAuto: true,
    logsTimer: null,
  };

  // ── Utilidades ──────────────────────────────────────────

  function el(tag, attrs = {}, ...filhos) {
    const no = document.createElement(tag);
    for (const [chave, valor] of Object.entries(attrs)) {
      if (valor === false || valor == null) continue;
      if (chave === 'class') no.className = valor;
      else if (chave === 'text') no.textContent = valor;
      else if (chave.startsWith('on')) no.addEventListener(chave.slice(2), valor);
      else no.setAttribute(chave, valor === true ? '' : valor);
    }
    for (const filho of filhos.flat()) {
      if (filho != null && filho !== false) no.append(filho);
    }
    return no;
  }

  async function api(url, opcoes = {}) {
    const resposta = await fetch(url, {
      credentials: 'same-origin',
      ...opcoes,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': P.csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
    });

    if (resposta.status === 401 || resposta.status === 419) {
      window.location.href = P.urls.login;
      throw new Error('Sessão expirada.');
    }

    let corpo = null;
    try { corpo = await resposta.json(); } catch { /* resposta sem JSON */ }

    if (!resposta.ok) {
      const erro = new Error(corpo?.message || `O painel respondeu com erro ${resposta.status}.`);
      erro.campos = corpo?.errors || {};
      throw erro;
    }
    return corpo;
  }

  const formatoData = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

  function quando(iso) {
    return iso ? formatoData.format(new Date(iso)) : '';
  }

  function duracao(execucao) {
    if (!execucao.iniciado_em) return '';
    const fim = execucao.finalizado_em ? new Date(execucao.finalizado_em) : new Date();
    const segundos = Math.max(0, Math.round((fim - new Date(execucao.iniciado_em)) / 1000));
    if (segundos < 60) return `${segundos} s`;
    const minutos = Math.floor(segundos / 60);
    if (minutos < 60) return `${minutos} min ${String(segundos % 60).padStart(2, '0')} s`;
    return `${Math.floor(minutos / 60)} h ${String(minutos % 60).padStart(2, '0')} min`;
  }

  function entreAspas(valor) {
    return /^[\w:=.,@\/-]+$/.test(valor) ? valor : `'${valor.replaceAll("'", "'\\''")}'`;
  }

  // ── Lista de commands ───────────────────────────────────

  function ativos() {
    return new Set(estado.historico.filter((e) => e.ativa).map((e) => e.comando));
  }

  function desenharLista() {
    const rodando = ativos();
    const filtro = estado.filtro.toLowerCase();
    const visiveis = P.comandos.filter((c) => !filtro
      || c.titulo.toLowerCase().includes(filtro)
      || c.nome.toLowerCase().includes(filtro)
      || (c.descricao || '').toLowerCase().includes(filtro));

    // O formulário é movido para dentro do item aberto; guarda o foco para não perdê-lo na troca.
    const form = $('#form-comando');
    const foco = form.contains(document.activeElement) ? document.activeElement : null;

    $('#lista').replaceChildren(...visiveis.map((comando) => {
      const aberto = estado.selecionado?.nome === comando.nome;
      return el('li', { class: aberto ? 'aberto' : null },
        el('button', {
          type: 'button',
          class: 'comando',
          'aria-expanded': String(aberto),
          onclick: () => selecionar(aberto ? null : comando.nome),
        },
          el('span', { class: 'comando-titulo' },
            rodando.has(comando.nome) && el('span', { class: 'pulso', title: 'Em execução' }),
            comando.titulo,
            comando.argumentos.length + comando.opcoes.length > 0
              && el('span', { class: 'comando-params', title: 'Tem parâmetros', text: `${comando.argumentos.length + comando.opcoes.length}` })),
          el('span', { class: 'comando-nome', text: comando.nome })),
        aberto && form);
    }));

    $('#lista-vazia').hidden = visiveis.length > 0;
    form.hidden = !estado.selecionado || !visiveis.includes(estado.selecionado);
    if (foco && !form.hidden) foco.focus();
  }

  function selecionar(nome) {
    estado.selecionado = P.comandos.find((c) => c.nome === nome) || null;
    desenharFormulario();
    desenharLista();
    const url = new URL(window.location.href);
    if (estado.selecionado) url.searchParams.set('comando', estado.selecionado.nome);
    else url.searchParams.delete('comando');
    window.history.replaceState(null, '', url);
    if (estado.selecionado) {
      document.querySelector('#lista li.aberto')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      $('#campos').querySelector('input, textarea')?.focus({ preventScroll: true });
    }
  }

  // ── Formulário do command ───────────────────────────────

  function campoDeTexto(grupo, item, { rotulo, nota, lista }) {
    const id = `campo-${grupo}-${item.nome}`;
    const entrada = item.escolhas?.length
      ? el('select', { id, name: item.nome, 'data-grupo': grupo },
        el('option', { value: '', text: item.obrigatorio ? 'Selecione…' : '(nenhum)' }),
        ...item.escolhas.map((escolha) => el('option', { value: escolha.valor, text: escolha.rotulo })))
      : lista
      ? el('textarea', { id, name: item.nome, 'data-grupo': grupo, spellcheck: 'false', placeholder: 'Um valor por linha, ou separados por vírgula' })
      : el('input', { id, type: 'text', name: item.nome, 'data-grupo': grupo, autocomplete: 'off', spellcheck: 'false', placeholder: item.padrao ? `Padrão: ${item.padrao}` : null });

    return el('div', { class: 'campo' },
      el('label', { for: id }, rotulo, nota && el('small', { text: nota })),
      entrada,
      item.descricao && el('p', { class: 'campo-ajuda', text: item.descricao }),
      el('p', { class: 'campo-erro', 'data-erro': `${grupo}.${item.nome}`, hidden: true }));
  }

  function desenharFormulario() {
    const comando = estado.selecionado;
    if (!comando) return;

    $('#comando-descricao').textContent = comando.descricao || '';
    $('#comando-descricao').hidden = !comando.descricao;
    $('#form-erro').textContent = '';

    const campos = [];

    for (const argumento of comando.argumentos) {
      campos.push(campoDeTexto('argumentos', argumento, {
        rotulo: argumento.rotulo || argumento.nome,
        nota: argumento.obrigatorio ? 'obrigatório' : null,
        lista: argumento.lista,
      }));
    }

    for (const opcao of comando.opcoes) {
      if (opcao.aceita_valor) {
        campos.push(campoDeTexto('opcoes', opcao, { rotulo: opcao.rotulo || `--${opcao.nome}`, nota: null, lista: opcao.multipla }));
        continue;
      }
      const id = `campo-opcoes-${opcao.nome}`;
      campos.push(el('div', { class: 'campo' },
        el('div', { class: 'campo-marcar' },
          el('input', { id, type: 'checkbox', name: opcao.nome, 'data-grupo': 'opcoes' }),
          el('label', { for: id, text: opcao.rotulo || `--${opcao.nome}` })),
        opcao.descricao && el('p', { class: 'campo-ajuda', text: opcao.descricao })));
    }

    $('#campos').replaceChildren(...campos);
    $('#campos').hidden = campos.length === 0;
  }

  function coletar() {
    const valores = { argumentos: {}, opcoes: {} };
    for (const campo of $('#campos').querySelectorAll('[data-grupo]')) {
      const valor = campo.type === 'checkbox' ? campo.checked : campo.value.trim();
      if (valor !== '' && valor !== false) valores[campo.dataset.grupo][campo.name] = valor;
    }
    return valores;
  }

  /** Prévia da linha de comando; a linha definitiva é montada e validada no servidor. */
  function previa(comando, valores) {
    const partes = ['php artisan', comando.nome];
    const separar = (texto) => texto.split(/[\s,;]+/).filter(Boolean);

    for (const opcao of comando.opcoes) {
      const valor = valores.opcoes[opcao.nome];
      if (valor === undefined) continue;
      if (!opcao.aceita_valor) partes.push(`--${opcao.nome}`);
      else (opcao.multipla ? separar(valor) : [valor]).forEach((v) => partes.push(entreAspas(`--${opcao.nome}=${v}`)));
    }

    const posicionais = comando.argumentos.flatMap((argumento) => {
      const valor = valores.argumentos[argumento.nome];
      if (valor === undefined) return [];
      return argumento.lista ? separar(valor) : [valor];
    });
    if (posicionais.length) partes.push('--', ...posicionais.map(entreAspas));

    return partes.join(' ');
  }

  function mostrarErros(erro) {
    for (const no of document.querySelectorAll('[data-erro]')) {
      const mensagens = erro?.campos?.[no.dataset.erro];
      no.textContent = mensagens ? mensagens[0] : '';
      no.hidden = !mensagens;
    }
    const temCampos = erro && Object.keys(erro.campos || {}).length > 0;
    $('#form-erro').textContent = erro ? (temCampos ? 'Corrija os campos marcados.' : erro.message) : '';
  }

  function pedirConfirmacao(evento) {
    evento.preventDefault();
    const comando = estado.selecionado;
    if (!comando) return;

    mostrarErros(null);
    const faltando = comando.argumentos.find((a) => a.obrigatorio && coletar().argumentos[a.nome] === undefined);
    if (faltando) {
      mostrarErros({ campos: { [`argumentos.${faltando.nome}`]: [`Informe "${faltando.rotulo || faltando.nome}".`] } });
      document.getElementById(`campo-argumentos-${faltando.nome}`)?.focus();
      return;
    }

    $('#confirmar-linha').textContent = previa(comando, coletar());
    $('#confirmar').showModal();
  }

  async function executar() {
    const comando = estado.selecionado;
    const botao = $('#botao-executar');
    botao.disabled = true;

    try {
      const { execucao } = await api(P.urls.execucoes, {
        method: 'POST',
        body: JSON.stringify({ comando: comando.nome, ...coletar() }),
      });
      acompanhar(execucao);
      carregarHistorico();
    } catch (erro) {
      mostrarErros(erro);
    } finally {
      botao.disabled = false;
    }
  }

  // ── Console ─────────────────────────────────────────────

  function desenharCabecalho() {
    const execucao = estado.execucao;
    const console_ = $('#console');
    console_.dataset.status = execucao ? execucao.status : 'vazio';
    $('#console-status').textContent = execucao ? ROTULOS[execucao.status] || execucao.status : 'Nenhuma execução aberta';
    $('#console-linha').textContent = execucao ? execucao.linha : '';
    $('#console-linha').title = execucao ? execucao.linha : '';
    $('#console-tempo').textContent = execucao ? duracao(execucao) : '';
    $('#console-parar').hidden = !(execucao && execucao.status === 'executando');
  }

  function acompanhar(execucao) {
    clearTimeout(estado.temporizador);
    clearInterval(estado.relogio);
    estado.geracao += 1;
    estado.execucao = execucao;
    estado.offset = 0;

    terminal.limpar();
    desenharCabecalho();

    estado.relogio = setInterval(() => {
      if (estado.execucao?.ativa) $('#console-tempo').textContent = duracao(estado.execucao);
    }, 1000);

    consultar(estado.geracao);
  }

  async function consultar(geracao) {
    let resposta;
    try {
      resposta = await api(`${P.urls.execucoes}/${estado.execucao.id}/saida?offset=${estado.offset}`);
    } catch {
      // Falha passageira (ex.: servidor reiniciando): tenta de novo sem perder o ponto da leitura.
      if (geracao === estado.geracao) estado.temporizador = setTimeout(() => consultar(geracao), 3000);
      return;
    }
    if (geracao !== estado.geracao) return;

    const saida = $('#console-saida');
    const noFim = saida.scrollHeight - saida.scrollTop - saida.clientHeight < 40;
    if (resposta.saida) terminal.escrever(resposta.saida);
    if (noFim) saida.scrollTop = saida.scrollHeight;

    const terminou = estado.execucao.ativa && !resposta.execucao.ativa;
    estado.offset = resposta.offset;
    estado.execucao = resposta.execucao;
    desenharCabecalho();

    if (resposta.execucao.ativa || resposta.mais) {
      estado.temporizador = setTimeout(() => consultar(geracao), resposta.mais ? 50 : 1000);
    } else {
      clearInterval(estado.relogio);
    }
    if (terminou) { carregarHistorico(); carregarLogs(); }
  }

  async function parar() {
    const execucao = estado.execucao;
    if (!execucao || !window.confirm('Parar esta execução? O command recebe um sinal de término e pode deixar o processamento pela metade.')) return;
    try {
      await api(`${P.urls.execucoes}/${execucao.id}/parar`, { method: 'POST' });
    } catch (erro) {
      window.alert(erro.message);
    }
  }

  // ── Execuções (para o ponto "em execução" na lista e retomar o acompanhamento) ──
  // A tabela completa fica na tela "Histórico de commands".

  async function carregarHistorico() {
    try {
      estado.historico = (await api(P.urls.execucoes)).execucoes;
    } catch {
      return;
    }
    desenharLista();
  }

  // ── Logs do matrícula ───────────────────────────────────

  async function carregarLogs() {
    let resposta;
    try {
      const params = new URLSearchParams({ fonte: $('#logs-fonte').value, linhas: $('#logs-linhas').value });
      resposta = await api(`${P.urls.logs}?${params}`);
    } catch (erro) {
      resposta = { arquivo: null, conteudo: erro.message };
    }

    const saida = $('#logs-saida');
    const noFim = saida.scrollHeight - saida.scrollTop - saida.clientHeight < 80;
    $('#logs-arquivo').textContent = resposta.arquivo || '—';

    const linhas = (resposta.conteudo || '').split('\n').map((linha) => {
      const nivel = /\.(ERROR|CRITICAL|ALERT|EMERGENCY):/.test(linha) ? 'erro'
        : /\.WARNING:/.test(linha) ? 'alerta'
        : /\.(INFO|NOTICE):/.test(linha) ? 'info'
        : /\.DEBUG:/.test(linha) ? 'debug' : null;
      return nivel ? el('span', { class: `log-${nivel}`, text: linha }) : linha;
    });
    saida.replaceChildren(...linhas.flatMap((linha, i) => (i ? ['\n', linha] : [linha])));

    if (noFim) saida.scrollTop = saida.scrollHeight;
  }

  function agendarLogs() {
    clearInterval(estado.logsTimer);
    if (estado.logsAuto) estado.logsTimer = setInterval(() => { if (!document.hidden) carregarLogs(); }, 3000);
  }

  // ── Início ──────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', async () => {
    if (!$('#lista')) return;

    $('#form-comando').addEventListener('submit', pedirConfirmacao);
    $('#console-parar').addEventListener('click', parar);
    $('#confirmar').addEventListener('close', (e) => {
      if (e.target.returnValue === 'executar') executar();
      e.target.returnValue = '';
    });

    $('#lista-busca').addEventListener('input', (e) => { estado.filtro = e.target.value.trim(); desenharLista(); });

    $('#logs-fonte').addEventListener('change', () => { $('#logs-saida').scrollTop = 1e9; carregarLogs(); });
    $('#logs-linhas').addEventListener('change', carregarLogs);
    $('#logs-auto').addEventListener('click', (e) => {
      estado.logsAuto = !estado.logsAuto;
      e.currentTarget.setAttribute('aria-pressed', String(estado.logsAuto));
      e.currentTarget.title = `Atualização automática (3 s): ${estado.logsAuto ? 'ligada' : 'pausada'}`;
      agendarLogs();
    });
    $('#logs-fim').addEventListener('click', () => { $('#logs-saida').scrollTop = $('#logs-saida').scrollHeight; });
    carregarLogs().then(() => { $('#logs-saida').scrollTop = $('#logs-saida').scrollHeight; });
    agendarLogs();

    // Link direto: /commands?comando=nome abre o command já selecionado, e
    // arg.<nome>=valor / opt.<nome>=valor preenchem os campos (ex.: o botão
    // "Reprocessar" da tela de PABs parados).
    const params = new URLSearchParams(window.location.search);
    const pedido = params.get('comando');
    if (pedido && P.comandos.some((c) => c.nome === pedido)) {
      selecionar(pedido);
      for (const [chave, valor] of params) {
        const grupo = chave.startsWith('arg.') ? 'argumentos' : chave.startsWith('opt.') ? 'opcoes' : null;
        if (!grupo) continue;
        const campo = $('#campos').querySelector(`[data-grupo="${grupo}"][name="${CSS.escape(chave.slice(4))}"]`);
        if (!campo) continue;
        if (campo.type === 'checkbox') campo.checked = valor !== '' && valor !== '0';
        else campo.value = valor;
      }
    } else {
      desenharLista();
    }
    desenharCabecalho();

    await carregarHistorico();

    // ?execucao=ID (vindo do histórico) abre essa execução; senão, se já existe
    // algo rodando (ex.: a janela foi fechada no meio), volta a acompanhar.
    const pedida = new URLSearchParams(window.location.search).get('execucao');
    const escolhida = pedida && estado.historico.find((e) => String(e.id) === pedida);
    const emAndamento = escolhida || estado.historico.find((e) => e.ativa);
    if (emAndamento) acompanhar(emAndamento);

    // Mantém o histórico em dia quando a janela volta ao foco.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) carregarHistorico(); });
  });
})();
