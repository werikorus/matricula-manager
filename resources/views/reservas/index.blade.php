@extends('layouts.app')

@section('titulo', 'Reservas paradas')

@php
    use App\Http\Controllers\ReservaController;

    $data = function ($valor, $formato = 'd/m/Y H:i') {
        $tempo = $valor ? strtotime((string) $valor) : false;

        return $tempo ? date($formato, $tempo) : '—';
    };
    $porEtapa = array_count_values(array_column($reservas, 'etapa'));
    $antigas = array_filter($reservas, function ($r) { return (int) $r['dias_parada'] >= 7; });
    $turnos = ['M' => 'Manhã', 'T' => 'Tarde', 'V' => 'Vespertino', 'N' => 'Noite', 'I' => 'Integral'];
@endphp

@section('conteudo')
<header class="topo">
    <h1>Reservas paradas</h1>
    <div class="topo-meio">
        <span class="ambiente" title="Consulta direta no banco do matrícula">
            <strong>{{ $ambiente }}</strong>
            <code>ano letivo ≥ {{ $anoMinimo }}</code>
        </span>
    </div>
    <span class="historico-nota">Consultado às {{ $consultadoEm->format('H:i:s') }}</span>
    <a class="botao" href="{{ route('reservas') }}" data-carregando="Consultando reservas paradas…">Atualizar</a>
    @if (! $erro)
        @if ($emAndamento)
            <a class="botao botao-primario" href="{{ route('commands', ['comando' => $emAndamento->comando]) }}">
                <span class="pulso pulso-claro" aria-hidden="true"></span> Processamento em andamento
            </a>
        @else
            <button type="button" class="botao botao-primario" id="reprocessar-todos">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.6-6.4M21 4v5h-5"/></svg>
                Reprocessar todos
            </button>
        @endif
    @endif
</header>

@if ($errors->any())
    <div class="aviso aviso-falha" role="alert">
        <h2>Não foi possível processar</h2>
        <p>{{ $errors->first() }}</p>
    </div>
@endif

@if (session('erro'))
    <div class="aviso aviso-falha" role="alert">
        <h2>Não foi possível processar</h2>
        <p>{{ session('erro') }}</p>
    </div>
@endif

@if ($erro)
    <div class="aviso aviso-falha" role="alert">
        <h2>Não consegui consultar as reservas</h2>
        <p>A lista vem direto do banco do matrícula, com as credenciais do .env de lá.</p>
        <pre>{{ $erro }}</pre>
    </div>
@else
    <div class="resumo" role="status">
        <span class="resumo-item"><strong>{{ count($reservas) }}</strong> {{ count($reservas) === 1 ? 'reserva parada' : 'reservas paradas' }}</span>
        <span class="resumo-item resumo-falha"><strong>{{ ($porEtapa['erro'] ?? 0) + ($porEtapa['processando'] ?? 0) }}</strong> com erro ou travadas</span>
        <span class="resumo-item resumo-aviso"><strong>{{ $porEtapa['sem_boleto'] ?? 0 }}</strong> sem boleto</span>
        <span class="resumo-item"><strong>{{ $porEtapa['aguarda_pagamento'] ?? 0 }}</strong> aguardando pagamento</span>
        <span class="resumo-item"><strong>{{ count($antigas) }}</strong> há 7 dias ou mais</span>
        <input type="search" class="lista-busca resumo-busca" id="tabela-busca" placeholder="Filtrar por aluno, RA, CPF, reserva, processo ou erro" aria-label="Filtrar reservas" autocomplete="off" spellcheck="false">
    </div>

    @if (count($porEtapa) > 1)
        <div class="atalhos" aria-label="Filtrar por etapa">
            <span class="atalhos-titulo">Etapa:</span>
            @foreach (ReservaController::ETAPAS as $chave => $etapa)
                @if (! empty($porEtapa[$chave]))
                    <button type="button" class="atalho" data-filtro="etapa:{{ $chave }}"><strong>{{ $porEtapa[$chave] }}</strong> {{ $etapa['titulo'] }}</button>
                @endif
            @endforeach
        </div>
    @endif

    <div class="folha">
        @if (count($reservas) === 0)
            <p class="vazio">Nenhuma reserva parada. Tudo integrado. 🎉</p>
        @else
            <nav class="paginacao paginacao-topo" data-paginacao aria-label="Paginação" hidden></nav>
            <div class="tabela-rolagem">
                <table class="tabela-pabs tabela-matriculas">
                    <thead>
                        <tr>
                            <th>Reserva</th>
                            <th>Aluno</th>
                            <th>Processo</th>
                            <th>Turma</th>
                            <th>Etapa e erro</th>
                            <th class="num">Tentativas</th>
                            <th class="num">Incluída em</th>
                            <th class="num">Parada há</th>
                            <th><span class="sr">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody id="tabela-corpo">
                        @foreach ($reservas as $r)
                        @php
                            $etapa = ReservaController::ETAPAS[$r['etapa']] ?? ['titulo' => $r['etapa'], 'classe' => ''];
                            $dias = (int) $r['dias_parada'];
                            $doAno = in_array((int) $r['nu_ano_letivo'], $anosDoCommand, true);
                        @endphp
                        <tr data-busca="{{ mb_strtolower('etapa:'.$r['etapa'].' '.$r['nm_pessoa'].' '.$r['nu_ra'].' '.$r['nu_cpf'].' '.$r['cd_reserva_vaga'].' '.$r['nm_processo'].' '.$r['erro_resumo'].' '.$etapa['titulo']) }}">
                            <td><code title="Contrato: {{ $r['cd_contrato'] ?: '—' }} · Aluno: {{ $r['cd_aluno'] ?: '—' }} · Resp. financeiro: {{ $r['cd_responsavel_financeiro'] ?: '—' }}">{{ $r['cd_reserva_vaga'] }}</code></td>
                            <td>
                                <span class="pab-aluno">{{ $r['nm_pessoa'] ?: '—' }}</span>
                                <span class="pab-ra">RA {{ $r['nu_ra'] ?: '—' }} · CPF {{ $r['nu_cpf'] ?: '—' }}</span>
                                @if ($r['in_colaborador'] === 'S')
                                    <span class="etiqueta">Colaborador</span>
                                @endif
                            </td>
                            <td class="mat-processo">
                                <span class="mat-processo-nome" title="{{ $r['nm_processo'] }}">{{ $r['nm_processo'] }}</span>
                                <span class="pab-ra">{{ $r['nu_ano_letivo'] }} · col {{ $r['cd_coligada'] }}/fil {{ $r['cd_filial'] }} · proc {{ $r['cd_processo_reserva'] }}</span>
                            </td>
                            <td>
                                <code>{{ $r['cd_turma'] ?: '—' }}</code>
                                <span class="pab-ra">{{ $turnos[$r['ds_turno_a_cursar']] ?? ($r['ds_turno_a_cursar'] ?: '—') }} · {{ $r['ds_periodo_a_cursar'] ?: '—' }}</span>
                            </td>
                            <td class="mat-erro">
                                <span class="selo {{ $etapa['classe'] }}">{{ $etapa['titulo'] }}</span>
                                <span class="pab-ra">{{ $r['cd_situacao'] }} · {{ $r['ds_situacao_reserva_vaga'] }}</span>
                                @if ($r['etapa'] === 'sem_boleto' && ! $r['tem_envio_codigo'])
                                    <span class="pab-ra aviso-texto" title="Sem registro em tb_envio_codigo para o responsável financeiro">o processamento automático ignora: sem envio de código</span>
                                @endif
                                @if ($r['erro_resumo'] !== '')
                                    <details>
                                        <summary>{{ $r['erro_resumo'] }}</summary>
                                        <pre>{{ trim($r['ds_erro']) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td class="num">{{ $r['nu_quantidade_tentativas'] === null ? '—' : $r['nu_quantidade_tentativas'] }}</td>
                            <td class="num">{{ $data($r['dh_inclusao']) }}</td>
                            <td class="num"><span class="{{ $dias >= 7 ? 'dias-alto' : '' }}" title="Desde a última alteração ({{ $data($r['dh_alteracao'] ?: $r['dh_inclusao']) }})">{{ $dias }} {{ $dias === 1 ? 'dia' : 'dias' }}</span></td>
                            <td class="num">
                                @if ($r['etapa'] === 'aguarda_pagamento')
                                    <span class="botao botao-mini" aria-disabled="true"
                                          title="Já tem boleto: reprocessar poderia gerar outro. Use &quot;Reprocessar todos&quot; › Verificar pagamentos.">Aguarda RM</span>
                                @elseif (! $doAno)
                                    <span class="botao botao-mini" aria-disabled="true"
                                          title="O ProcessaReservaRMCommand só processa os anos letivos {{ implode(' e ', $anosDoCommand) }}">Fora do ano</span>
                                @else
                                    <a class="botao botao-mini"
                                       href="{{ route('commands') }}?comando={{ ReservaController::COMANDO_INTEGRAR }}&arg.codigoReserva={{ $r['cd_reserva_vaga'] }}"
                                       title="Abre o ProcessaReservaRMCommand já com esta reserva preenchida">Reprocessar</a>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="vazio" id="tabela-vazia" hidden>Nenhuma reserva corresponde ao filtro.</p>
            <nav class="paginacao" data-paginacao aria-label="Paginação (rodapé)" hidden></nav>
        @endif
    </div>
@endif

@if (! $erro && ! $emAndamento)
<dialog id="confirmar-todos" aria-labelledby="confirmar-todos-titulo">
    <form method="POST" action="{{ route('reservas.processar') }}" class="dialogo" data-carregando="Iniciando o processamento das reservas…">
        @csrf
        <h2 id="confirmar-todos-titulo">Processar reservas em {{ $ambiente }}?</h2>
        <p>Roda um dos commands de reserva do matrícula, sem filtro: cada um busca as reservas pela própria regra.</p>

        <fieldset class="escolha-filtro">
            <legend>O que fazer?</legend>
            <label class="escolha">
                <input type="radio" name="acao" value="pagamento" @if ($contagem['pagamento'] > 0 || $contagem['integrar'] === 0) checked @endif>
                <span>
                    <strong>Verificar pagamentos</strong>
                    <small><code>{{ ReservaController::COMANDO_PAGAMENTO }}</code> consulta o RM e passa para "Reserva de vaga" as de termo assinado já pagas. Só lê o RM; não gera boleto.</small>
                    <small><b>{{ $contagem['pagamento'] }}</b> {{ $contagem['pagamento'] === 1 ? 'reserva' : 'reservas' }} com termo assinado (ano letivo ≥ {{ date('Y') }}).</small>
                </span>
            </label>
            <label class="escolha">
                <input type="radio" name="acao" value="integrar" @if ($contagem['pagamento'] === 0 && $contagem['integrar'] > 0) checked @endif>
                <span>
                    <strong>Integrar com o RM</strong>
                    <small><code>{{ ReservaController::COMANDO_INTEGRAR }}</code> integra e gera o boleto das reservas sem boleto e das com erro (menos de 3 tentativas), dos anos {{ implode(' e ', $anosDoCommand) }}.</small>
                    <small><b>{{ $contagem['integrar'] }}</b> {{ $contagem['integrar'] === 1 ? 'reserva se encaixa' : 'reservas se encaixam' }}.
                        @if ($contagem['integrar'] > ReservaController::LIMITE_POR_EXECUCAO)
                            <span class="aviso-texto">Ele processa no máximo {{ ReservaController::LIMITE_POR_EXECUCAO }} por execução: rode de novo ou use o "Reprocessar" de cada linha.</span>
                        @endif
                    </small>
                </span>
            </label>
        </fieldset>

        <div class="dialogo-acoes">
            <button type="button" class="botao" onclick="this.closest('dialog').close()">Cancelar</button>
            <button type="submit" class="botao botao-primario" id="confirmar-todos-enviar">Executar</button>
        </div>
    </form>
</dialog>
@endif

@push('scripts')
<script src="{{ asset('js/tabela.js') }}?v={{ filemtime(public_path('js/tabela.js')) }}"></script>
<script src="{{ asset('js/copiar.js') }}?v={{ filemtime(public_path('js/copiar.js')) }}"></script>
@endpush
@endsection
