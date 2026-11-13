/* Mostra a saída de um command num <pre> como um terminal mostraria.
   Os commands rodam com --ansi para a barra de progresso do Symfony se redesenhar
   na mesma linha (\r + ESC[2K). Aqui esses códigos são interpretados em vez de
   virarem texto: \r volta ao início da linha, ESC[2K/ESC[K apagam, ESC[nA sobe
   linhas e as cores (ESC[...m) são descartadas. Sem dependências e sem build. */
(() => {
  'use strict';

  // Sequência de escape cortada no fim de um trecho: guardada para o próximo.
  const ESCAPE_INCOMPLETO = /\x1b(\[[0-9;?]*)?$/;
  const PARTES = /(\x1b\[[0-9;?]*[A-Za-z]|\r\n|\r|\n)/;

  window.ConsoleTerminal = (elemento) => {
    let linhas = [''];
    let coluna = 0;
    let pendente = '';

    const atual = () => linhas.length - 1;

    function escreverTexto(texto) {
      const linha = linhas[atual()].padEnd(coluna, ' ');
      linhas[atual()] = linha.slice(0, coluna) + texto + linha.slice(coluna + texto.length);
      coluna += texto.length;
    }

    function aplicarEscape(sequencia) {
      const comando = sequencia.slice(-1);
      const parametro = sequencia.slice(2, -1);
      const n = parseInt(parametro, 10) || 1;

      if (comando === 'K') {
        // ESC[2K apaga a linha toda; ESC[K/ESC[0K, do cursor até o fim.
        linhas[atual()] = parametro === '2' ? '' : linhas[atual()].slice(0, coluna);
      } else if (comando === 'A') {
        // Subir: as linhas de baixo são sempre apagadas e reescritas em seguida pelo Symfony.
        for (let i = 0; i < n && linhas.length > 1; i++) linhas.pop();
      } else if (comando === 'G') {
        coluna = n - 1;
      }
      // Cores (m) e o resto: ignorados.
    }

    function escrever(trecho) {
      let texto = pendente + trecho;
      pendente = '';

      const corte = texto.match(ESCAPE_INCOMPLETO);
      if (corte) {
        pendente = corte[0];
        texto = texto.slice(0, corte.index);
      }

      for (const parte of texto.split(PARTES)) {
        if (!parte) continue;
        if (parte === '\n' || parte === '\r\n') {
          linhas.push('');
          coluna = 0;
        } else if (parte === '\r') {
          coluna = 0;
        } else if (parte[0] === '\x1b') {
          aplicarEscape(parte);
        } else {
          escreverTexto(parte);
        }
      }

      elemento.textContent = linhas.join('\n');
    }

    function limpar() {
      linhas = [''];
      coluna = 0;
      pendente = '';
      elemento.textContent = '';
    }

    return { escrever, limpar };
  };
})();
