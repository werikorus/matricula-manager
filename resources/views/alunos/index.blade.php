@extends('layouts.app')

@section('titulo', $termo !== '' ? 'Matrícula: '.$termo : 'Consultar matrícula')

@php
    $data = function ($valor, $formato = 'd/m/Y H:i') {
        $tempo = $valor ? strtotime((string) $valor) : false;

        return $tempo ? date($formato, $tempo) : '—';
    };
    $classeSituacao = function ($status) {
        $s = (string) $status;
        if (in_array($s, ['5', '16', '19'], true)) return 'selo-falha';
        if (in_array($s, ['2', '9'], true)) return 'selo-sucesso';
        if (in_array($s, ['6'], true)) return '';
        return 'selo-interrompido';
    };
    $linhas = $resultado['linhas'] ?? [];
    $alunos = count(array_unique(array_map(function ($l) {
        return mb_strtolower(trim((string) $l['nm_aluno'])).'|'.preg_replace('/\D/', '', (string) $l['nu_cpf_aluno']);
    }, $linhas)));
    $voltar = request()->getRequestUri();
@endphp

@section('conteudo')
<header class="topo">
    <h1>Consultar matrícula</h1>
    <div class="topo-meio">
        <span class="ambiente" title="Consulta direta no banco do matrícula">
            <strong>{{ $ambiente }}</strong>
            <code>tb_solicitacao</code>
        </span>
    </div>
</header>

<form method="GET" action="{{ route('alunos') }}" class="busca-aluno" data-carregando="Procurando…" role="search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input type="search" name="q" value="{{ $termo }}" placeholder="Nome do aluno, CPF, RA ou código da solicitação" aria-label="Consultar matrícula"
           autocomplete="off" spellcheck="false" maxlength="100" @if ($termo === '') autofocus @endif>
    <button type="submit" class="botao botao-primario">Buscar</button>
</form>

@if (session('erro'))
    <div class="aviso aviso-falha" role="alert">
        <h2>Não foi possível reprocessar</h2>
        <p>{{ session('erro') }}</p>
    </div>
@endif

@if ($erro)
    <div class="aviso aviso-falha" role="alert">
        <h2>Não consegui buscar</h2>
        <p>{{ $erro }}</p>
    </div>
@elseif ($resultado === null)
    <div class="folha dica-busca">
        <p>Busque por:</p>
        <ul>
            <li><strong>Nome</strong>: uma ou mais partes, em qualquer ordem, sem se preocupar com acento ou maiúscula (ex.: <code>anna pires</code>).</li>
            <li><strong>CPF</strong> do aluno, com ou sem pontuação.</li>
            <li><strong>RA</strong>, com ou sem os zeros à esquerda, ou o <strong>código da solicitação</strong>.</li>
        </ul>
        <p>O resultado mostra todas as solicitações encontradas, de qualquer situação. O lápis abre a solicitação para ver e editar; as que estão aguardando integração ou com erro podem ser reprocessadas direto da lista.</p>
    </div>
@else
    <div class="resumo" role="status">
        <span class="resumo-item"><strong>{{ count($linhas) }}</strong> {{ count($linhas) === 1 ? 'solicitação' : 'solicitações' }}</span>
        <span class="resumo-item"><strong>{{ $alunos }}</strong> {{ $alunos === 1 ? 'aluno' : 'alunos' }}</span>
        <span class="resumo-item">busca por {{ $resultado['tipo'] }}</span>
        @if ($resultado['cortado'])
            <span class="resumo-item resumo-aviso">mostrando as {{ \App\Services\BancoDoMatricula::LIMITE_BUSCA }} primeiras: refine a busca</span>
        @endif
        @if (count($linhas) > 0)
            <input type="search" class="lista-busca resumo-busca" id="tabela-busca" placeholder="Filtrar o resultado" aria-label="Filtrar o resultado" autocomplete="off" spellcheck="false">
        @endif
    </div>

    <div class="folha">
        @if (count($linhas) === 0)
            <p class="vazio">Nenhuma solicitação encontrada para "{{ $termo }}".</p>
        @else
            <nav class="paginacao paginacao-topo" data-paginacao aria-label="Paginação" hidden></nav>
            <div class="tabela-rolagem">
                <table class="tabela-pabs tabela-matriculas">
                    <thead>
                        <tr>
                            <th>Solicitação</th>
                            <th>Aluno</th>
                            <th>Processo</th>
                            <th>Situação e erro</th>
                            <th class="num">Tentativas</th>
                            <th class="num">Incluída em</th>
                            <th class="num">Alterada em</th>
                            <th><span class="sr">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody id="tabela-corpo">
                        @foreach ($linhas as $l)
                            @php $editar = route('solicitacoes.editar', ['codigo' => $l['cd_solicitacao'], 'voltar' => $voltar]); @endphp
                            <tr data-busca="{{ mb_strtolower($l['cd_solicitacao'].' '.$l['nm_aluno'].' '.$l['nu_ra'].' '.$l['nu_cpf_aluno'].' '.$l['processo'].' '.($situacoes[(string) $l['status']] ?? '').' '.$l['erro_resumo']) }}">
                                <td>
                                    <a class="link-codigo" href="{{ $editar }}" data-carregando="Abrindo a solicitação {{ $l['cd_solicitacao'] }}…" title="Ver e editar a solicitação">
                                        <code>{{ $l['cd_solicitacao'] }}</code>
                                    </a>
                                </td>
                                <td>
                                    <span class="aluno-linha">
                                        <span class="pab-aluno">{{ $l['nm_aluno'] ?: '—' }}</span>
                                        <a class="link-editar" href="{{ $editar }}" data-carregando="Abrindo a solicitação {{ $l['cd_solicitacao'] }}…"
                                           title="Ver e editar a solicitação {{ $l['cd_solicitacao'] }}" aria-label="Editar a solicitação {{ $l['cd_solicitacao'] }}">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                                        </a>
                                    </span>
                                    <span class="pab-ra">RA {{ $l['nu_ra'] ?: '—' }} · CPF {{ $l['nu_cpf_aluno'] ?: '—' }}@if ($l['dt_nascimento_aluno']) · nasc. {{ $data($l['dt_nascimento_aluno'], 'd/m/Y') }}@endif</span>
                                </td>
                                <td class="mat-processo">
                                    <span class="mat-processo-nome" title="{{ $l['processo'] }}">{{ $l['processo'] ?: '—' }}</span>
                                    <span class="pab-ra">{{ $l['ano'] ?: '—' }} · col {{ $l['cd_coligada'] ?? '—' }}/fil {{ $l['cd_filial'] ?? '—' }} · proc {{ $l['cd_processo'] ?? '—' }}@if ($l['cd_turma']) · turma {{ $l['cd_turma'] }}@endif</span>
                                </td>
                                <td class="mat-erro">
                                    <span class="selo {{ $classeSituacao($l['status']) }}" title="Código {{ $l['status'] }}">{{ $l['status'] }} · {{ $situacoes[(string) $l['status']] ?? 'Situação desconhecida' }}</span>
                                    @if ($l['erro_resumo'] !== '')
                                        <details>
                                            <summary>{{ $l['erro_resumo'] }}</summary>
                                            <pre>{{ trim($l['ds_erro']) }}</pre>
                                        </details>
                                    @endif
                                </td>
                                <td class="num">{{ $l['tentativas'] === null ? '—' : $l['tentativas'] }}</td>
                                <td class="num">{{ $data($l['dh_inclusao']) }}</td>
                                <td class="num">{{ $data($l['dh_alteracao']) }}</td>
                                <td class="num">
                                    @if (! in_array((int) $l['status'], [14, 16], true))
                                        <span class="botao botao-mini" aria-disabled="true"
                                              title="Só dá para reprocessar solicitações aguardando integração (14) ou com erro na integração (16).">Reprocessar</span>
                                    @elseif ($emAndamento)
                                        <span class="botao botao-mini" aria-disabled="true"
                                              title="Já há um integrador rodando ({{ $emAndamento->comando }}). Espere terminar.">Reprocessar</span>
                                    @else
                                        <button type="button" class="botao botao-mini"
                                                data-reprocessar-solicitacao="{{ $l['cd_solicitacao'] }}"
                                                data-aluno="{{ $l['nm_aluno'] ?: '—' }}"
                                                data-situacao="{{ $l['status'] }} · {{ $situacoes[(string) $l['status']] ?? 'Situação desconhecida' }}"
                                                data-tentativas="{{ $l['tentativas'] === null ? 'vazio' : $l['tentativas'] }}"
                                                title="Volta a solicitação para 14, zera as tentativas, limpa o erro e roda o integradorCommand">Reprocessar</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="vazio" id="tabela-vazia" hidden>Nenhuma linha corresponde ao filtro.</p>
            <nav class="paginacao" data-paginacao aria-label="Paginação (rodapé)" hidden></nav>
        @endif
    </div>
@endif

@if ($resultado !== null && count($linhas) > 0 && ! $emAndamento)
    @include('components.reprocessar-solicitacao', ['ambiente' => $ambiente, 'voltar' => $voltar])
@endif

@push('scripts')
<script src="{{ asset('js/tabela.js') }}?v={{ filemtime(public_path('js/tabela.js')) }}"></script>
<script src="{{ asset('js/copiar.js') }}?v={{ filemtime(public_path('js/copiar.js')) }}"></script>
@endpush
@endsection
