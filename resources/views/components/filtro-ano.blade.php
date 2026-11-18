{{-- Select do ano letivo das telas de acompanhamento (veja App\Support\AnoLetivo).
     Troca a página na hora, mantendo os outros parâmetros da URL. --}}
<form method="GET" class="filtro-ano" data-carregando="Consultando…">
    <label for="filtro-ano">Ano letivo</label>
    <select id="filtro-ano" name="ano" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
        @foreach (\App\Support\AnoLetivo::opcoes() as $opcao)
            <option value="{{ $opcao }}" @if ($opcao === $ano) selected @endif>{{ $opcao }}</option>
        @endforeach
    </select>
    <noscript><button type="submit" class="botao botao-mini">Filtrar</button></noscript>
</form>
