@extends('layouts.app')

@section('titulo', 'PABs parados')

@php
    $data = function ($valor, $formato = 'd/m/Y H:i') {
        $tempo = $valor ? strtotime((string) $valor) : false;

        return $tempo ? date($formato, $tempo) : '—';
    };
    $comErro = array_filter($pabs, function ($p) { return (int) $p['cd_situacao'] === 11; });
    $semBoleto = count($pabs) - count($comErro);
    $antigos = array_filter($pabs, function ($p) { return (int) $p['dias_corridos'] >= 7; });
@endphp

@section('conteudo')
<header class="topo">
    <h1>PABs parados</h1>
    <div class="topo-meio">
        <span class="ambiente" title="Consulta direta no banco do matrícula">
            <strong>{{ $ambiente }}</strong>
            <code>tb_admissao</code>
        </span>
        @include('components.filtro-ano', ['ano' => $ano])
    </div>
    <span class="historico-nota">Consultado às {{ $consultadoEm->format('H:i:s') }}</span>
    <a class="botao" href="{{ route('pabs', ['ano' => $ano]) }}" data-carregando="Consultando PABs parados…">Atualizar</a>
    @if (! $erro)
        @if ($emAndamento)
            <a class="botao botao-primario" href="{{ route('commands', ['comando' => \App\Http\Controllers\PabController::COMANDO]) }}">
                <span class="pulso pulso-claro" aria-hidden="true"></span> Reprocessamento em andamento
            </a>
        @else
            <button type="button" class="botao botao-primario" id="reprocessar-todos">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.6-6.4M21 4v5h-5"/></svg>
                Reprocessar todos
            </button>
        @endif
    @endif
</header>

@if (session('erro'))
    <div class="aviso aviso-falha" role="alert">
        <h2>Não foi possível reprocessar</h2>
        <p>{{ session('erro') }}</p>
    </div>
@endif

@if ($erro)
    <div class="aviso aviso-falha" role="alert">
        <h2>Não consegui consultar os PABs</h2>
        <p>A lista vem direto do banco do matrícula, com as credenciais do .env de lá.</p>
        <pre>{{ $erro }}</pre>
    </div>
@else
    <div class="resumo" role="status">
        <span class="resumo-item"><strong>{{ count($pabs) }}</strong> {{ count($pabs) === 1 ? 'PAB parado' : 'PABs parados' }}</span>
        <span class="resumo-item resumo-aviso"><strong>{{ $semBoleto }}</strong> sem boleto e sem contrato</span>
        <span class="resumo-item resumo-falha"><strong>{{ count($comErro) }}</strong> com erro de integração</span>
        <span class="resumo-item"><strong>{{ count($antigos) }}</strong> há 7 dias ou mais</span>
        <input type="search" class="lista-busca resumo-busca" id="tabela-busca" placeholder="Filtrar por aluno, RA ou admissão" aria-label="Filtrar PABs" autocomplete="off" spellcheck="false">
    </div>

    <div class="folha">
        @if (count($pabs) === 0)
            <p class="vazio">Nenhum PAB parado. Tudo integrado. 🎉</p>
        @else
            <nav class="paginacao paginacao-topo" data-paginacao aria-label="Paginação" hidden></nav>
            <div class="tabela-rolagem">
                <table class="tabela-pabs">
                    <thead>
                        <tr>
                            <th>Admissão</th>
                            <th>Aluno</th>
                            <th class="num">Col/Fil</th>
                            <th class="num">Ano</th>
                            <th>Situação</th>
                            <th class="num">Tentativas</th>
                            <th class="num">Incluído em</th>
                            <th class="num">Parado há</th>
                            <th><span class="sr">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody id="tabela-corpo">
                        @foreach ($pabs as $pab)
                        @php
                            $situacao = (int) $pab['cd_situacao'];
                            $dias = (int) $pab['dias_corridos'];
                            $tentativas = $pab['nu_quantidade_tentativas'];
                        @endphp
                        <tr data-busca="{{ strtolower($pab['nm_aluno'].' '.$pab['nu_ra'].' '.$pab['cd_admissao']) }}">
                            <td><code title="Contrato: {{ $pab['cd_contrato'] ?: '—' }} · Início: {{ $data($pab['dh_inicio']) }} · Término: {{ $data($pab['dh_termino']) }}">{{ $pab['cd_admissao'] }}</code></td>
                            <td>
                                <span class="pab-aluno">{{ $pab['nm_aluno'] }}</span>
                                <span class="pab-ra">RA {{ $pab['nu_ra'] ?: '—' }}</span>
                            </td>
                            <td class="num">{{ $pab['cd_coligada'] }}/{{ $pab['cd_filial'] }}</td>
                            <td class="num">{{ $pab['nu_ano_letivo'] }}</td>
                            <td>
                                <span class="selo {{ $situacao === 11 ? 'selo-falha' : 'selo-interrompido' }}" title="Código {{ $situacao }}">
                                    {{ $pab['ds_situacao_admissao'] ?: 'Situação '.$situacao }}
                                </span>
                            </td>
                            <td class="num">{{ $tentativas === null ? '—' : $tentativas }}</td>
                            <td class="num">{{ $data($pab['dh_inclusao'], 'd/m/Y H:i') }}</td>
                            <td class="num"><span class="{{ $dias >= 7 ? 'dias-alto' : '' }}">{{ $dias }} {{ $dias === 1 ? 'dia' : 'dias' }}</span></td>
                            <td class="num">
                                @if (in_array((int) $pab['nu_ano_letivo'], $anosDoCommand, true))
                                    <a class="botao botao-mini"
                                       href="{{ route('commands') }}?comando=ProcessaAdmissaoRMCommand&arg.codigoProcessoAdmissao={{ $pab['cd_admissao'] }}"
                                       title="Abre o command Processa Admissão (RM) já com este PAB preenchido">Reprocessar</a>
                                @else
                                    <span class="botao botao-mini" aria-disabled="true"
                                          title="O ProcessaAdmissaoRMCommand só processa os anos letivos {{ implode(' e ', $anosDoCommand) }}">Fora do ano</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="vazio" id="tabela-vazia" hidden>Nenhum PAB corresponde ao filtro.</p>
            <nav class="paginacao" data-paginacao aria-label="Paginação (rodapé)" hidden></nav>
        @endif
    </div>
@endif

@if (! $erro && ! $emAndamento)
@php $ficamDeFora = count($pabs) - count($selecionados); @endphp
<dialog id="confirmar-todos" aria-labelledby="confirmar-todos-titulo">
    <form method="POST" action="{{ route('pabs.reprocessar-todos') }}" class="dialogo" data-carregando="Iniciando o reprocessamento…">
        @csrf
        <h2 id="confirmar-todos-titulo">Reprocessar PABs em {{ $ambiente }}?</h2>
        <p>Roda o command do matrícula sem filtro; ele mesmo busca e processa os PABs parados:</p>
        <pre>php artisan {{ \App\Http\Controllers\PabController::COMANDO }}</pre>
        <p class="dialogo-nota">
            Pela regra do command, devem entrar <strong>{{ count($selecionados) }} dos {{ count($pabs) }}</strong> PABs desta lista.
            @if ($ficamDeFora > 0)
                Ficam de fora {{ $ficamDeFora }}: os com erro que já têm 3 tentativas ou mais e os fora dos anos letivos {{ implode(' e ', $anosDoCommand) }}. Para esses, use o "Reprocessar" da linha.
            @endif
        </p>
        <div class="dialogo-acoes">
            <button type="button" class="botao" onclick="this.closest('dialog').close()">Cancelar</button>
            <button type="submit" class="botao botao-primario" id="confirmar-todos-enviar">Reprocessar todos</button>
        </div>
    </form>
</dialog>
@endif

@push('scripts')
<script src="{{ asset('js/tabela.js') }}?v={{ filemtime(public_path('js/tabela.js')) }}"></script>
<script src="{{ asset('js/copiar.js') }}?v={{ filemtime(public_path('js/copiar.js')) }}"></script>
@endpush
@endsection
