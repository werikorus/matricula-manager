@extends('layouts.app')

@section('titulo', 'Solicitação '.$codigo)

{{-- Nesta tela a barra lateral começa recolhida, para dar espaço aos campos. --}}
@section('menu_inicial', 'recolhido')

@php
    // Valor inicial do controle a partir do que o banco devolveu.
    $inicial = function (array $coluna, $valor) {
        if ($valor === null) {
            return '';
        }
        if ($coluna['controle'] === 'datahora') {
            return str_replace(' ', 'T', substr((string) $valor, 0, 19));
        }
        if ($coluna['controle'] === 'data') {
            return substr((string) $valor, 0, 10);
        }

        return (string) $valor;
    };
    $situacaoAtual = $registro['cd_situacao_solicitacao'] ?? null;
    $classeSituacao = in_array((string) $situacaoAtual, ['5', '16', '19'], true) ? 'selo-falha'
        : (in_array((string) $situacaoAtual, ['2', '9'], true) ? 'selo-sucesso' : 'selo-interrompido');
@endphp

@section('conteudo')
<header class="topo">
    <a class="botao botao-discreto botao-voltar" href="{{ $voltar }}" data-carregando="Voltando…" title="Voltar para a lista">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        Voltar
    </a>
    <h1>Solicitação {{ $codigo }}</h1>
    <div class="topo-meio">
        <span class="ambiente" title="Edição direta na tabela do matrícula">
            <strong>{{ $ambiente }}</strong>
            <code>tb_solicitacao</code>
        </span>
    </div>
</header>

@if (session('salvo'))
    <div class="aviso aviso-ok" role="status">
        <h2>Alterações gravadas</h2>
        <p>{{ count(session('salvo')) }} {{ count(session('salvo')) === 1 ? 'campo atualizado' : 'campos atualizados' }}: <code>{{ implode(', ', session('salvo')) }}</code>. Registrado na auditoria do painel.</p>
    </div>
@endif

@if (session('erro'))
    <div class="aviso aviso-falha" role="alert">
        <h2>Nada foi gravado</h2>
        <p>{{ session('erro') }}</p>
    </div>
@endif

@if ($errors->any())
    <div class="aviso aviso-falha" role="alert">
        <h2>Nada foi gravado</h2>
        <p>Corrija os campos marcados abaixo. Suas alterações foram mantidas na tela.</p>
        <ul class="lista-erros">
            @foreach ($errors->messages() as $campo => $mensagens)
                <li><code>{{ $campo }}</code>: {{ $mensagens[0] }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($erro)
    <div class="aviso aviso-falha" role="alert">
        <h2>Não consegui abrir a solicitação</h2>
        <pre>{{ $erro }}</pre>
    </div>
@else
    <section class="ficha">
        <div class="ficha-principal">
            <span class="pab-aluno ficha-aluno">{{ $registro['nm_aluno'] ?: '—' }}</span>
            <span class="pab-ra">RA {{ $registro['nu_ra'] ?: '—' }} · CPF {{ $registro['nu_cpf_aluno'] ?: '—' }} · processo {{ $registro['cd_processo'] ?? '—' }}</span>
        </div>
        <div class="ficha-situacao">
            <span class="selo {{ $classeSituacao }}">{{ $situacaoAtual }} · {{ $situacoes[(string) $situacaoAtual] ?? 'Situação desconhecida' }}</span>
            @if ($erroResumo !== '')
                <span class="pab-ra ficha-erro" title="{{ $erroResumo }}">{{ $erroResumo }}</span>
            @endif
        </div>
    </section>

    <div class="barra-edicao">
        <input type="search" class="lista-busca" id="campos-busca" placeholder="Buscar campo (ex.: tentativas, boleto, dh_)" aria-label="Buscar campo" autocomplete="off" spellcheck="false">
        <label class="filtro-check"><input type="checkbox" id="so-preenchidos"> Só preenchidos</label>
        <label class="filtro-check"><input type="checkbox" id="so-alterados"> Só alterados</label>
        <span class="barra-grupos">
            <button type="button" class="botao botao-discreto" id="expandir-grupos" title="Abre todos os grupos de campos">Expandir tudo</button>
            <button type="button" class="botao botao-discreto" id="recolher-grupos" title="Recolhe todos os grupos de campos">Recolher tudo</button>
        </span>
        <span class="barra-espaco"></span>
        <button type="button" class="botao" id="descartar" disabled>Descartar</button>
        <button type="button" class="botao botao-primario" id="salvar" disabled>Salvar alterações</button>
    </div>

    <form id="form-edicao" novalidate onsubmit="return false">
        @foreach ($grupos as $chaveGrupo => $grupo)
            {{-- Só "Situação e integração" começa aberto; os outros grupos ficam recolhidos. --}}
            <details class="folha grupo-campos" data-grupo="{{ $chaveGrupo }}" @if ($loop->first) open @endif>
                <summary class="grupo-titulo">
                    <svg class="grupo-seta" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                    {{ $grupo['titulo'] }} <span class="contagem">{{ count($grupo['colunas']) }}</span>
                    <span class="grupo-alterados" data-alterados hidden></span>
                </summary>
                <div class="grade-campos">
                    @foreach ($grupo['colunas'] as $nome => $coluna)
                        @php
                            $valor = $registro[$nome] ?? null;
                            $id = 'c-'.$nome;
                            $longo = $coluna['controle'] === 'textolongo';
                            $termo = $longo && \Illuminate\Support\Str::startsWith($nome, 'ds_termo_');
                        @endphp
                        <div class="campo-edicao {{ $longo ? 'campo-largo' : '' }} {{ $coluna['editavel'] ? '' : 'campo-bloqueado' }}"
                             data-campo="{{ $nome }}" data-controle="{{ $coluna['controle'] }}" data-preenchido="{{ $valor === null || $valor === '' ? '0' : '1' }}">
                            <div class="campo-cabeca">
                                <label for="{{ $id }}" class="campo-nome">{{ $nome }}</label>
                                <span class="campo-meta">
                                    {{ $coluna['rotulo_tipo'] }}@if (! $coluna['nulo']) · obrigatório @endif
                                    @if ($coluna['chave']) · chave @endif
                                    @if ($coluna['automatico']) · <span class="campo-automatico">preenchido ao salvar</span> @endif
                                    @if ($coluna['referencia']) · → {{ $coluna['referencia'] }} @endif
                                </span>
                            </div>

                            @if (! $coluna['editavel'])
                                <input id="{{ $id }}" type="text" value="{{ $valor }}" readonly class="controle"
                                       @if ($coluna['automatico']) title="{{ $coluna['automatico'] }}: {{ $automaticos[$nome] ?? '' }}" @endif>
                            @elseif ($nome === 'cd_situacao_solicitacao')
                                <select id="{{ $id }}" class="controle" data-entrada>
                                    @if ($valor === null) <option value="">—</option> @endif
                                    @foreach ($situacoes as $cod => $desc)
                                        <option value="{{ $cod }}" @if ((string) $valor === (string) $cod) selected @endif>{{ $cod }} · {{ $desc }}</option>
                                    @endforeach
                                    @if ($valor !== null && ! isset($situacoes[(string) $valor]))
                                        <option value="{{ $valor }}" selected>{{ $valor }} · (desconhecida)</option>
                                    @endif
                                </select>
                            @elseif ($coluna['controle'] === 'bit')
                                <select id="{{ $id }}" class="controle" data-entrada>
                                    @if ($valor === null) <option value="">—</option> @endif
                                    <option value="1" @if ((string) $valor === '1') selected @endif>1 · Sim</option>
                                    <option value="0" @if ((string) $valor === '0') selected @endif>0 · Não</option>
                                </select>
                            @elseif ($longo && $termo)
                                <details class="campo-termo">
                                    <summary>{{ $valor === null ? 'NULL' : number_format(mb_strlen((string) $valor), 0, ',', '.').' caracteres' }} · mostrar e editar</summary>
                                    <textarea id="{{ $id }}" class="controle controle-termo" rows="14" spellcheck="false" data-entrada>{{ $valor }}</textarea>
                                </details>
                            @elseif ($longo)
                                <textarea id="{{ $id }}" class="controle" rows="{{ $nome === 'ds_erro' ? 6 : 3 }}" spellcheck="false" data-entrada>{{ $valor }}</textarea>
                            @elseif ($coluna['controle'] === 'data')
                                <input id="{{ $id }}" type="date" class="controle" value="{{ $inicial($coluna, $valor) }}" data-entrada>
                            @elseif ($coluna['controle'] === 'datahora')
                                <input id="{{ $id }}" type="datetime-local" step="1" class="controle" value="{{ $inicial($coluna, $valor) }}" data-entrada>
                            @elseif (in_array($coluna['controle'], ['inteiro', 'decimal'], true))
                                <input id="{{ $id }}" type="text" inputmode="{{ $coluna['controle'] === 'inteiro' ? 'numeric' : 'decimal' }}" class="controle controle-numero" value="{{ $valor }}" autocomplete="off" data-entrada>
                            @else
                                <input id="{{ $id }}" type="text" class="controle" value="{{ $valor }}" maxlength="{{ $coluna['caracteres'] }}" autocomplete="off" spellcheck="false" data-entrada>
                            @endif

                            <div class="campo-rodape">
                                @if ($coluna['editavel'] && $coluna['nulo'])
                                    <label class="campo-nulo"><input type="checkbox" data-nulo @if ($valor === null) checked @endif> NULL</label>
                                @endif
                                <button type="button" class="campo-desfazer" data-desfazer hidden>desfazer</button>
                                <span class="campo-erro-texto" data-erro-campo>{{ $errors->first($nome) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
        <p class="vazio" id="campos-vazio" hidden>Nenhum campo corresponde à busca.</p>
    </form>

    <dialog id="confirmar-edicao" aria-labelledby="confirmar-edicao-titulo">
        <form method="POST" action="{{ route('solicitacoes.salvar', ['codigo' => $codigo, 'voltar' => request()->query('voltar')]) }}" class="dialogo dialogo-largo" id="form-salvar"
              data-carregando="Gravando a solicitação…">
            @csrf
            <input type="hidden" name="alteracoes" id="alteracoes">
            <h2 id="confirmar-edicao-titulo">Gravar <span id="qtd-alteracoes"></span> na solicitação {{ $codigo }} em {{ $ambiente }}?</h2>
            <p>Grava direto na <code>tb_solicitacao</code> do matrícula. Se o registro tiver mudado no banco desde que você abriu a tela, nada é gravado.
               <code>dh_alteracao</code> e <code>nu_ip_alteracao</code> são preenchidos automaticamente.</p>
            <div class="tabela-rolagem diferencas-rolagem">
                <table class="diferencas">
                    <thead><tr><th>Campo</th><th>Antes</th><th>Depois</th></tr></thead>
                    <tbody id="diferencas-corpo"></tbody>
                </table>
            </div>
            <div class="dialogo-acoes">
                <button type="button" class="botao" onclick="this.closest('dialog').close()">Cancelar</button>
                <button type="submit" class="botao botao-primario" id="confirmar-salvar">Gravar no banco</button>
            </div>
        </form>
    </dialog>
@endif

@push('scripts')
    @if (! $erro)
        <script>
            window.SOLICITACAO = {
                originais: @json($registro, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                rascunho: @json(session('rascunho')),
                automaticos: @json($automaticos, JSON_UNESCAPED_UNICODE),
            };
        </script>
        <script src="{{ asset('js/solicitacao.js') }}?v={{ filemtime(public_path('js/solicitacao.js')) }}"></script>
    @endif
@endpush
@endsection
