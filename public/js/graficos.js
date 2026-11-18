/* Tela Gráficos: KPIs, colunas por semana, medidores por tipo, linhas das
   paradas em aberto e barras dos erros que voltam. SVG desenhado à mão, sem
   dependências. Dados em window.GRAFICOS (veja GraficoController); os filtros
   de período e tipo são aplicados aqui. */
(() => {
  'use strict';

  const G = window.GRAFICOS;
  if (!G) return;

  const TIPOS = [
    { chave: 'matriculas', nome: 'Matrículas', cor: '--s-matriculas' },
    { chave: 'pabs', nome: 'PABs', cor: '--s-pabs' },
    { chave: 'reservas', nome: 'Reservas', cor: '--s-reservas' },
  ];
  const RESULTADOS = [
    ['resolvido', '--st-resolvido', 'Resolvido'],
    ['parado', '--st-parado', 'Ainda parado'],
    ['voltou', '--st-voltou', 'Voltou a dar erro'],
  ];
  const estado = { periodo: 28, tipo: 'todos' }; // dias; múltiplo de 7 para as colunas serem semanas inteiras

  // ── Utilidades ──────────────────────────────────────────────────
  const $ = (s) => document.querySelector(s);
  const raizCss = $('.graficos');
  const NS = 'http://www.w3.org/2000/svg';
  const svg = (nome, attrs, pai) => {
    const el = document.createElementNS(NS, nome);
    for (const [k, v] of Object.entries(attrs || {})) el.setAttribute(k, v);
    if (pai) pai.appendChild(el);
    return el;
  };
  const cor = (token) => getComputedStyle(raizCss).getPropertyValue(token).trim();
  const fmt = (n) => n.toLocaleString('pt-BR');
  const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
  const escapar = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const data = (iso) => { const [a, m, d] = iso.split('-').map(Number); return new Date(a, m - 1, d); };
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const somarDias = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
  const curta = (d) => d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
  const hoje = data(G.hoje);
  const passo = (max) => {
    const bruto = Math.max(1, max) / 4;
    const p = Math.pow(10, Math.floor(Math.log10(bruto)));
    return [1, 2, 2.5, 5, 10].map((m) => m * p).find((s) => s >= bruto && Number.isInteger(s)) || Math.ceil(bruto);
  };
  const semanas = () => estado.periodo / 7;
  const tiposAtivos = () => TIPOS.filter((t) => estado.tipo === 'todos' || t.chave === estado.tipo);
  const doTipo = (i) => estado.tipo === 'todos' || i.tipo === estado.tipo;
  /** Itens do tipo escolhido entre $de e $ate dias atrás (0 = hoje). */
  const itensEntre = (de, ate) => {
    const inicio = iso(somarDias(hoje, -de)), fim = iso(somarDias(hoje, -ate));
    return G.itens.filter((i) => doTipo(i) && i.dia >= inicio && i.dia <= fim);
  };
  const contar = (itens) => {
    const c = { resolvido: 0, parado: 0, voltou: 0 };
    itens.forEach((i) => { c[i.resultado]++; });
    c.total = c.resolvido + c.parado + c.voltou;
    return c;
  };
  const vazio = (area, texto) => { area.innerHTML = `<p class="grafico-vazio">${texto}</p>`; };
  const linhaDica = (token, nome, valor) =>
    `<div><span><i class="chave" style="background:var(${token})"></i>${escapar(nome)}</span><em>${valor}</em></div>`;

  function dica(area) {
    let el = area.querySelector('.dica-grafico');
    if (!el) { el = document.createElement('div'); el.className = 'dica-grafico'; el.hidden = true; area.appendChild(el); }
    return {
      mostrar(html, x, y) {
        el.innerHTML = html; el.hidden = false;
        el.style.left = Math.min(Math.max(0, x + 14), area.clientWidth - el.offsetWidth) + 'px';
        el.style.top = Math.max(0, y - el.offsetHeight - 10) + 'px';
      },
      esconder() { el.hidden = true; },
    };
  }

  function eixoY(raiz, m, W, y, topo, p) {
    for (let v = 0; v <= topo; v += p) {
      svg('line', { x1: m.l, x2: W - m.r, y1: y(v), y2: y(v), stroke: v ? cor('--grade') : cor('--eixo'), 'stroke-width': 1 }, raiz);
      svg('text', { x: m.l - 8, y: y(v) + 4, 'text-anchor': 'end' }, raiz).textContent = fmt(v);
    }
  }

  // ── KPIs ────────────────────────────────────────────────────────
  function kpis() {
    const c = contar(itensEntre(estado.periodo - 1, 0));
    const taxa = pct(c.resolvido, c.total);
    let nota = 'saíram da fila de erro';
    if (estado.periodo * 2 <= G.dias) {
      const antes = contar(itensEntre(estado.periodo * 2 - 1, estado.periodo));
      if (antes.total && c.total) {
        const delta = taxa - pct(antes.resolvido, antes.total);
        const sinal = delta > 0 ? ['kpi-sobe', '▲ +'] : delta < 0 ? ['kpi-desce', '▼ −'] : ['', '= '];
        nota = `<strong class="${sinal[0]}">${sinal[1]}${Math.abs(delta)} p.p.</strong> em relação às ${semanas()} semanas anteriores`;
      }
    }
    $('#kpis').innerHTML = `
      <div class="kpi"><div class="kpi-rotulo">Reprocessados</div>
        <div class="kpi-valor">${fmt(c.total)}</div><div class="kpi-nota">nas últimas ${semanas()} semanas</div></div>
      <div class="kpi"><div class="kpi-rotulo"><i class="chave" style="background:var(--st-resolvido)"></i>Resolvidos</div>
        <div class="kpi-valor">${fmt(c.resolvido)}${c.total ? `<small>${taxa}%</small>` : ''}</div><div class="kpi-nota">${nota}</div></div>
      <div class="kpi"><div class="kpi-rotulo"><i class="chave" style="background:var(--st-parado)"></i>Ainda parados</div>
        <div class="kpi-valor">${fmt(c.parado)}</div><div class="kpi-nota">continuam aguardando</div></div>
      <div class="kpi"><div class="kpi-rotulo"><i class="chave" style="background:var(--st-voltou)"></i>Voltaram a dar erro</div>
        <div class="kpi-valor">${fmt(c.voltou)}</div><div class="kpi-nota">precisam de correção</div></div>`;
  }

  // ── Colunas empilhadas por semana ───────────────────────────────
  function colunas() {
    const area = $('#semanas');
    area.innerHTML = '';
    const n = semanas();
    const dados = Array.from({ length: n }, (_, i) => {
      const ate = (n - 1 - i) * 7, de = Math.min(ate + 6, estado.periodo - 1);
      return { ...contar(itensEntre(de, ate)), inicio: somarDias(hoje, -de), fim: somarDias(hoje, -ate) };
    });
    const tabela = $('#semanas-tabela');
    if (!dados.some((d) => d.total)) {
      vazio(area, 'Nenhum reprocessamento registrado no período. Cada reprocessamento feito pelo painel passa a aparecer aqui.');
      tabela.innerHTML = '';
      return;
    }
    const W = area.clientWidth, H = 250, m = { t: 18, r: 8, b: 26, l: 34 };
    const max = Math.max(...dados.map((d) => d.total)), p = passo(max), topo = Math.ceil(max / p) * p;
    const y = (v) => m.t + (H - m.t - m.b) * (1 - v / topo);
    const banda = (W - m.l - m.r) / n, larg = Math.min(24, banda * 0.5);
    const raiz = svg('svg', { viewBox: `0 0 ${W} ${H}`, height: H, role: 'img', 'aria-label': 'Reprocessamentos por semana, por resultado' }, area);
    eixoY(raiz, m, W, y, topo, p);
    const tip = dica(area);
    dados.forEach((d, i) => {
      const cx = m.l + banda * i + banda / 2, x = cx - larg / 2;
      const visiveis = RESULTADOS.filter(([k]) => d[k] > 0);
      let base = 0;
      visiveis.forEach(([k, token], j) => {
        const y0 = y(base), y1 = y(base + d[k]), gap = j > 0 ? 2 : 0, alto = Math.max(0, y0 - y1 - gap);
        if (j === visiveis.length - 1) {
          const r = Math.min(4, alto);
          svg('path', { d: `M${x},${y0 - gap} V${y1 + r} Q${x},${y1} ${x + r},${y1} H${x + larg - r} Q${x + larg},${y1} ${x + larg},${y1 + r} V${y0 - gap} Z`, fill: cor(token) }, raiz);
        } else {
          svg('rect', { x, y: y1, width: larg, height: alto, fill: cor(token) }, raiz);
        }
        base += d[k];
      });
      svg('text', { x: cx, y: H - 8, 'text-anchor': 'middle' }, raiz).textContent = curta(d.inicio);
      if (i === n - 1 && d.total) svg('text', { x: cx, y: y(d.total) - 6, 'text-anchor': 'middle', class: 'rotulo-forte' }, raiz).textContent = d.total;
      const alvo = svg('rect', { x: m.l + banda * i, y: m.t, width: banda, height: H - m.t - m.b, fill: 'transparent' }, raiz);
      alvo.addEventListener('mousemove', (e) => {
        const r = area.getBoundingClientRect();
        tip.mostrar(`<b>${curta(d.inicio)} a ${curta(d.fim)}</b>${RESULTADOS.map(([k, t, nome]) => linhaDica(t, nome, d[k])).join('')}
          <div><span>Resolvidos</span><em>${pct(d.resolvido, d.total)}%</em></div>`, e.clientX - r.left, e.clientY - r.top);
      });
      alvo.addEventListener('mouseleave', tip.esconder);
    });
    tabela.innerHTML = '<tr><th>Semana</th><th>Resolvido</th><th>Ainda parado</th><th>Voltou a dar erro</th><th>Total</th></tr>'
      + dados.map((d) => `<tr><td>${curta(d.inicio)} a ${curta(d.fim)}</td><td>${d.resolvido}</td><td>${d.parado}</td><td>${d.voltou}</td><td>${d.total}</td></tr>`).join('');
  }

  // ── Medidores por tipo ──────────────────────────────────────────
  function medidores() {
    const itens = itensEntre(estado.periodo - 1, 0);
    $('#medidores').innerHTML = tiposAtivos().map((t) => {
      const c = contar(itens.filter((i) => i.tipo === t.chave));
      const p = pct(c.resolvido, c.total);
      return `<div>
        <div class="medidor-topo"><strong><i class="chave" style="background:var(${t.cor})"></i>${t.nome}</strong>
          <span>${c.total ? `${fmt(c.resolvido)} de ${fmt(c.total)} · ${p}%` : 'nenhum reprocessado'}</span></div>
        <div class="medidor-trilho" style="background:color-mix(in srgb, var(${t.cor}) 18%, transparent)"><i style="width:${p}%;background:var(${t.cor})"></i></div>
      </div>`;
    }).join('');
  }

  // ── Linhas: paradas em aberto ───────────────────────────────────
  function linhas() {
    const area = $('#linhas'), tabela = $('#linhas-tabela');
    area.innerHTML = '';
    const tipos = tiposAtivos(), dias = estado.periodo;
    const inicio = iso(somarDias(hoje, -(dias - 1)));
    const fotos = G.paradas.filter((f) => f.dia >= inicio);
    $('#linhas-legenda').innerHTML = tipos.map((t) => `<span><i class="chave" style="background:var(${t.cor})"></i>${t.nome}</span>`).join('');
    if (!fotos.length) {
      vazio(area, 'Ainda não há contagens no período. Elas são feitas quando Gráficos, PABs, Matrículas ou Reservas paradas são abertas.');
      tabela.innerHTML = '';
      return;
    }
    const W = area.clientWidth, H = 230, m = { t: 14, r: 104, b: 26, l: 34 };
    const max = Math.max(1, ...fotos.flatMap((f) => tipos.map((t) => f[t.chave])));
    const p = passo(max), topo = Math.ceil(max / p) * p;
    const idx = (f) => Math.round((data(f.dia) - data(inicio)) / 864e5);
    const x = (i) => m.l + (W - m.l - m.r) * (dias > 1 ? i / (dias - 1) : 0);
    const y = (v) => m.t + (H - m.t - m.b) * (1 - v / topo);
    const raiz = svg('svg', { viewBox: `0 0 ${W} ${H}`, height: H, role: 'img', 'aria-label': 'Paradas em aberto por dia' }, area);
    eixoY(raiz, m, W, y, topo, p);
    const marcas = [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(f * (dias - 1)));
    marcas.forEach((i) => {
      svg('text', { x: x(i), y: H - 8, 'text-anchor': i === 0 ? 'start' : i === dias - 1 ? 'end' : 'middle' }, raiz).textContent = curta(somarDias(data(inicio), i));
    });
    const fundo = cor('--superficie'), ultima = fotos[fotos.length - 1];
    for (const t of tipos) {
      if (fotos.length > 1) {
        svg('polyline', { points: fotos.map((f) => `${x(idx(f))},${y(f[t.chave])}`).join(' '), fill: 'none', stroke: cor(t.cor), 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, raiz);
      }
      svg('circle', { cx: x(idx(ultima)), cy: y(ultima[t.chave]), r: 4, fill: cor(t.cor), stroke: fundo, 'stroke-width': 2 }, raiz);
    }
    // Rótulos no fim das linhas, afastados quando os valores ficam próximos.
    const rotulos = tipos.map((t) => ({ t, yy: y(ultima[t.chave]) })).sort((a, b) => a.yy - b.yy);
    rotulos.forEach((r, i) => { if (i && r.yy - rotulos[i - 1].yy < 14) r.yy = rotulos[i - 1].yy + 14; });
    rotulos.forEach((r) => {
      svg('text', { x: x(idx(ultima)) + 10, y: r.yy + 4, class: 'rotulo-forte' }, raiz).textContent = `${fmt(ultima[r.t.chave])} ${r.t.nome}`;
    });
    const cruz = svg('line', { y1: m.t, y2: H - m.b, stroke: cor('--eixo'), 'stroke-width': 1, visibility: 'hidden' }, raiz);
    const pontos = tipos.map((t) => svg('circle', { r: 4, fill: cor(t.cor), stroke: fundo, 'stroke-width': 2, visibility: 'hidden' }, raiz));
    const tip = dica(area);
    const alvo = svg('rect', { x: m.l, y: m.t, width: W - m.l - m.r, height: H - m.t - m.b, fill: 'transparent' }, raiz);
    alvo.addEventListener('mousemove', (e) => {
      const r = area.getBoundingClientRect(), px = e.clientX - r.left;
      const f = fotos.reduce((a, b) => (Math.abs(x(idx(b)) - px) < Math.abs(x(idx(a)) - px) ? b : a));
      const fx = x(idx(f));
      cruz.setAttribute('x1', fx); cruz.setAttribute('x2', fx); cruz.setAttribute('visibility', 'visible');
      tipos.forEach((t, k) => { pontos[k].setAttribute('cx', fx); pontos[k].setAttribute('cy', y(f[t.chave])); pontos[k].setAttribute('visibility', 'visible'); });
      tip.mostrar(`<b>${data(f.dia).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' })}</b>`
        + tipos.map((t) => linhaDica(t.cor, t.nome, fmt(f[t.chave]))).join(''), fx, e.clientY - r.top);
    });
    alvo.addEventListener('mouseleave', () => { cruz.setAttribute('visibility', 'hidden'); pontos.forEach((c) => c.setAttribute('visibility', 'hidden')); tip.esconder(); });
    tabela.innerHTML = `<tr><th>Dia</th>${tipos.map((t) => `<th>${t.nome}</th>`).join('')}</tr>`
      + fotos.slice().reverse().map((f) => `<tr><td>${curta(data(f.dia))}</td>${tipos.map((t) => `<td>${fmt(f[t.chave])}</td>`).join('')}</tr>`).join('');
  }

  // ── Barras horizontais: erros que voltam ────────────────────────
  function erros() {
    const area = $('#erros');
    area.innerHTML = '';
    const contagem = new Map();
    itensEntre(estado.periodo - 1, 0).forEach((i) => {
      if (i.resultado === 'voltou' && i.erro) contagem.set(i.erro, (contagem.get(i.erro) || 0) + 1);
    });
    const lista = [...contagem].sort((a, b) => b[1] - a[1]).slice(0, 6);
    if (!lista.length) {
      vazio(area, estado.tipo === 'pabs'
        ? 'Para PABs o painel lê só a situação (11 = erro), não o texto do erro: os que voltaram a dar erro aparecem no total acima.'
        : 'Nenhum reprocessado voltou a dar erro no período.');
      return;
    }
    const W = area.clientWidth, alturaLinha = 40, H = lista.length * alturaLinha + 6;
    const max = lista[0][1], util = W - 40;
    const raiz = svg('svg', { viewBox: `0 0 ${W} ${H}`, height: H, role: 'img', 'aria-label': 'Erros que mais voltam' }, area);
    const caber = Math.floor(W / 6.2);
    lista.forEach(([nome, n], i) => {
      const yy = i * alturaLinha, w = Math.max(6, (util * n) / max), r = 4;
      const rotulo = svg('text', { x: 0, y: yy + 13, class: 'rotulo-texto' }, raiz);
      rotulo.textContent = nome.length > caber ? nome.slice(0, caber - 1) + '…' : nome;
      svg('title', {}, rotulo).textContent = nome;
      svg('path', { d: `M0,${yy + 20} H${w - r} Q${w},${yy + 20} ${w},${yy + 20 + r} V${yy + 32 - r} Q${w},${yy + 32} ${w - r},${yy + 32} H0 Z`, fill: cor('--s-erro') }, raiz);
      svg('text', { x: w + 8, y: yy + 30, class: 'rotulo-forte' }, raiz).textContent = n;
    });
  }

  function desenhar() { kpis(); colunas(); medidores(); linhas(); erros(); }

  document.querySelectorAll('.graficos [data-filtro]').forEach((grupo) => {
    grupo.addEventListener('click', (e) => {
      const botao = e.target.closest('button');
      if (!botao) return;
      grupo.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === botao)));
      estado[grupo.dataset.filtro] = grupo.dataset.filtro === 'periodo' ? Number(botao.dataset.valor) : botao.dataset.valor;
      desenhar();
    });
  });
  let espera;
  window.addEventListener('resize', () => { clearTimeout(espera); espera = setTimeout(desenhar, 120); });
  // As cores vêm dos tokens do tema: redesenha quando ele troca.
  new MutationObserver(desenhar).observe(document.documentElement, { attributes: true, attributeFilter: ['data-tema'] });
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', desenhar);
  desenhar();
})();
