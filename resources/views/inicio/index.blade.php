@extends('layouts.app')

@section('titulo', 'Início')

@php
    $hora = (int) date('G');
    $saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
    $primeiroNome = trim(explode(' ', trim($usuario))[0] ?? '');
    $nomeNaSaudacao = ($primeiroNome !== '' && mb_strtolower($usuario) !== 'acesso livre') ? ', '.$primeiroNome : '';

    // "há 5 min", "hoje 14:03", "ontem 09:10", "12/11 08:00"
    $quando = function ($iso) {
        $t = $iso ? strtotime((string) $iso) : false;
        if (! $t) return '';
        $segundos = time() - $t;
        if ($segundos < 60) return 'agora';
        if ($segundos < 3600) return 'há '.intdiv($segundos, 60).' min';
        if (date('Y-m-d', $t) === date('Y-m-d')) return 'hoje '.date('H:i', $t);
        if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'ontem '.date('H:i', $t);
        return date('d/m H:i', $t);
    };
    $rotulos = ['pendente' => 'Na fila', 'executando' => 'Executando', 'sucesso' => 'Concluído', 'falha' => 'Falhou', 'interrompido' => 'Interrompido'];
    $classeSituacao = function ($s) {
        $s = (string) $s;
        if (in_array($s, ['5', '16', '19'], true)) return 'selo-falha';
        if (in_array($s, ['2', '9'], true)) return 'selo-sucesso';
        return 'selo-interrompido';
    };
    $destaques = array_slice($telas, 0, 3);
    $demais = array_slice($telas, 3);
@endphp

@section('conteudo')
<header class="topo inicio-topo">
    <div>
        <h1>{{ $saudacao }}{{ $nomeNaSaudacao }}</h1>
        <p class="inicio-sub">{{ \Illuminate\Support\Str::ucfirst(\Carbon\Carbon::now()->locale('pt_BR')->isoFormat('dddd, D [de] MMMM')) }} · ambiente <strong>{{ $ambiente }}</strong></p>
    </div>
</header>

<form method="GET" action="{{ route('alunos') }}" class="busca-aluno" data-carregando="Procurando…" role="search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    <input type="search" name="q" placeholder="Consultar aluno: nome, CPF, RA ou código da solicitação" aria-label="Consultar aluno" autocomplete="off" spellcheck="false" maxlength="100" autofocus>
    <button type="submit" class="botao botao-primario">Buscar</button>
</form>

@if (count($emAndamento))
    <a class="aviso aviso-andamento" href="{{ route('commands', ['comando' => $emAndamento[0]->comando]) }}" data-carregando="Abrindo o console…">
        <span class="pulso" aria-hidden="true"></span>
        <span>
            <strong>{{ count($emAndamento) === 1 ? 'Rotina em execução' : count($emAndamento).' rotinas em execução' }}:</strong>
            <code>{{ $emAndamento[0]->linha() }}</code>
            · desde {{ $quando($emAndamento[0]->iniciado_em ?: $emAndamento[0]->criado_em) }}
        </span>
        <span class="aviso-andamento-ir">Acompanhar →</span>
    </a>
@endif

<section class="inicio-secao" aria-labelledby="atalhos-titulo">
    <div class="inicio-secao-topo">
        <h2 id="atalhos-titulo">{{ $semUso ? 'Atalhos' : 'Mais acessadas' }}</h2>
        <span class="historico-nota">{{ $semUso ? 'A ordem se ajusta conforme você usa o painel.' : 'Ordenadas pelo seu uso.' }}</span>
    </div>

    <div class="atalhos-grade">
        @foreach ($destaques as $i => $tela)
            <a class="atalho-card atalho-destaque" href="{{ route($tela['rota']) }}" data-carregando="{{ $tela['carregando'] }}">
                <span class="atalho-icone"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $tela['icone'] !!}</svg></span>
                <span class="atalho-texto">
                    <strong>{{ $tela['texto'] }}</strong>
                    <span>{{ $tela['descricao'] }}</span>
                </span>
                <span class="atalho-uso">
                    @if ($tela['total'])
                        @if ($i === 0 && ! $semUso)<span class="atalho-selo">mais usada</span>@endif
                        {{ $tela['total'] }} {{ $tela['total'] === 1 ? 'acesso' : 'acessos' }} · {{ $quando($tela['ultimo']) }}
                    @else
                        ainda não acessada
                    @endif
                </span>
            </a>
        @endforeach
    </div>

    @if (count($demais))
        <div class="atalhos-linha">
            @foreach ($demais as $tela)
                <a class="atalho-card atalho-compacto" href="{{ route($tela['rota']) }}" data-carregando="{{ $tela['carregando'] }}" title="{{ $tela['descricao'] }}">
                    <span class="atalho-icone"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $tela['icone'] !!}</svg></span>
                    <span class="atalho-texto">
                        <strong>{{ $tela['texto'] }}</strong>
                        <span>{{ $tela['total'] ? $tela['total'].' '.($tela['total'] === 1 ? 'acesso' : 'acessos').' · '.$quando($tela['ultimo']) : 'ainda não acessada' }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</section>

<div class="inicio-colunas">
    <section class="inicio-secao folha inicio-bloco" aria-labelledby="recentes-titulo">
        <div class="inicio-secao-topo">
            <h2 id="recentes-titulo">Solicitações abertas recentemente</h2>
        </div>
        @if (count($solicitacoes))
            <ul class="inicio-lista">
                @foreach ($solicitacoes as $s)
                    <li>
                        <a href="{{ route('solicitacoes.editar', ['codigo' => $s['codigo'], 'voltar' => '/']) }}" data-carregando="Abrindo a solicitação {{ $s['codigo'] }}…">
                            <code class="inicio-codigo">{{ $s['codigo'] }}</code>
                            <span class="inicio-lista-texto">
                                <strong>{{ $s['aluno'] ?: '—' }}</strong>
                                @if ($s['situacao'] !== null)<span class="selo {{ $classeSituacao($s['situacao']) }}">situação {{ $s['situacao'] }}</span>@endif
                            </span>
                            <span class="inicio-quando">{{ $quando($s['quando']) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="vazio">As solicitações que você abrir aparecem aqui.</p>
        @endif
    </section>

    <section class="inicio-secao folha inicio-bloco" aria-labelledby="comandos-titulo">
        <div class="inicio-secao-topo">
            <h2 id="comandos-titulo">Commands mais executados</h2>
        </div>
        @if (count($comandos))
            <ul class="inicio-lista">
                @foreach ($comandos as $c)
                    <li>
                        <a href="{{ route('commands', ['comando' => $c['nome']]) }}" data-carregando="Abrindo commands…">
                            <span class="inicio-contador" title="Execuções pelo painel">{{ $c['total'] }}×</span>
                            <span class="inicio-lista-texto">
                                <strong>{{ $titulos[$c['nome']] ?? $c['nome'] }}</strong>
                                <code>{{ $c['nome'] }}</code>
                            </span>
                            <span class="inicio-quando">
                                <span class="selo selo-{{ $c['ultima']['status'] }}">{{ $rotulos[$c['ultima']['status']] ?? $c['ultima']['status'] }}</span>
                                {{ $quando($c['ultima']['criado_em']) }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="vazio">Os commands que você executar pelo painel aparecem aqui.</p>
        @endif
    </section>

    @if (count($buscas))
        <section class="inicio-secao folha inicio-bloco" aria-labelledby="buscas-titulo">
            <div class="inicio-secao-topo">
                <h2 id="buscas-titulo">Buscas recentes</h2>
            </div>
            <div class="inicio-buscas">
                @foreach ($buscas as $b)
                    <a class="atalho" href="{{ route('alunos', ['q' => $b['termo']]) }}" data-carregando="Procurando…" title="{{ $b['resultados'] }} resultado(s) · {{ $quando($b['quando']) }}">
                        {{ $b['termo'] }} <strong>{{ $b['resultados'] }}</strong>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
