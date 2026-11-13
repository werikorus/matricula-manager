@extends('layouts.app')

@section('titulo', 'Configurações')

@php $titulos = array_column($ambientes, 'titulo', 'chave'); @endphp

@section('conteudo')
<header class="topo">
    <h1>Configurações</h1>
</header>

@if (session('ok'))
    <div class="aviso aviso-ok" role="status">
        <h2>Configuração salva</h2>
        <p>{{ session('ok') }}</p>
    </div>
@endif

@if (session('erro'))
    <div class="aviso aviso-falha" role="alert">
        <h2>Não foi possível salvar</h2>
        <p>{{ session('erro') }}</p>
    </div>
@endif

<section class="folha config-secao" aria-labelledby="ambiente-titulo">
    <div class="config-cabeca">
        <div>
            <h2 id="ambiente-titulo">Ambiente do matrícula</h2>
            <p>Define em qual matrícula o painel trabalha: de onde vem a lista de commands, onde eles rodam, quais logs aparecem e qual banco é consultado e editado.</p>
        </div>
        @if ($alteradoEm)
            <span class="historico-nota">Alterado {{ date('d/m/Y H:i', strtotime($alteradoEm)) }}@if ($alteradoPor) por {{ $alteradoPor }}@endif</span>
        @endif
    </div>

    <form method="POST" action="{{ route('configuracoes.ambiente') }}" id="form-ambiente" data-carregando="Trocando o ambiente…">
        @csrf
        <div class="config-ambientes">
            @foreach ($ambientes as $chave => $a)
                <label class="config-ambiente" data-ambiente-opcao="{{ $chave }}">
                    <input type="radio" name="ambiente" value="{{ $chave }}" @if ($chave === $atual) checked @endif @if (! $a['pronto']) disabled @endif>
                    <span class="config-ambiente-corpo">
                        <span class="config-ambiente-titulo">
                            <span class="ambiente-chave ambiente-chave-{{ strtolower($chave) }}">{{ $chave }}</span>
                            <strong>{{ $a['titulo'] }}</strong>
                            @if ($chave === $atual)<span class="config-em-uso">em uso</span>@endif
                        </span>
                        <dl class="config-detalhes">
                            <dt>Pasta</dt>
                            <dd><code>{{ $a['caminho'] }}</code><small>{{ $a['caminho_windows'] }}</small></dd>
                            <dt>Banco</dt>
                            <dd><code>{{ $a['banco'] }}</code>@if ($a['app_env'])<small>APP_ENV={{ $a['app_env'] }}</small>@endif</dd>
                            @if ($a['git'])
                                <dt>Git</dt>
                                <dd><code>{{ $a['git'] }}</code></dd>
                            @endif
                        </dl>
                        <span class="config-verificacoes">
                            @foreach ($a['verificacoes'] as $item => $ok)
                                <span class="{{ $ok ? 'verificacao-ok' : 'verificacao-falha' }}">{{ $ok ? '✓' : '✗' }} {{ $item }}</span>
                            @endforeach
                            <span class="{{ $a['rede']['ok'] ? 'verificacao-ok' : 'verificacao-falha' }}">{{ $a['rede']['ok'] ? '✓' : '✗' }} {{ $a['rede']['texto'] }}</span>
                        </span>
                    </span>
                </label>
            @endforeach
        </div>

        <div class="acoes">
            <button type="submit" class="botao botao-primario" id="salvar-ambiente" disabled>Usar este ambiente</button>
            <span class="historico-nota">A troca vale para todo o painel na hora. Rotinas que já rodaram continuam registradas com o ambiente delas.</span>
        </div>
    </form>
</section>

<section class="folha config-secao" id="repositorios" aria-labelledby="caminhos-titulo">
    <div class="config-cabeca">
        <div>
            <h2 id="caminhos-titulo">Repositórios do matrícula</h2>
            <p>Pasta de cada ambiente. Pode colar o caminho do Windows (<code>\\wsl.localhost\Ubuntu\home\...</code>) ou o da WSL (<code>/home/...</code>). Deixe vazio para voltar ao padrão.</p>
        </div>
    </div>

    @if (session('caminhos_ok'))
        <div class="aviso aviso-ok aviso-na-secao" role="status"><p>✓ {{ session('caminhos_ok') }}</p></div>
    @endif
    @if ($errors->has('caminhos.*'))
        <div class="aviso aviso-falha aviso-na-secao" role="alert"><p>Nada foi salvo: corrija o caminho marcado abaixo.</p></div>
    @endif

    <form method="POST" action="{{ route('configuracoes.caminhos') }}" class="config-caminhos" data-carregando="Conferindo as pastas…">
        @csrf
        @foreach ($ambientes as $chave => $a)
            <div class="config-caminho {{ $errors->has('caminhos.'.$chave) ? 'campo-com-erro' : '' }}">
                <label for="caminho-{{ $chave }}">
                    <span class="ambiente-chave ambiente-chave-{{ strtolower($chave) }}">{{ $chave }}</span>
                    {{ $a['titulo'] }}
                    <small>{{ $a['origem'] === 'configuracoes' ? 'definido aqui' : 'padrão' }}</small>
                </label>
                <input type="text" id="caminho-{{ $chave }}" name="caminhos[{{ $chave }}]" class="controle"
                       value="{{ old('caminhos.'.$chave, $a['origem'] === 'configuracoes' ? $a['caminho'] : '') }}"
                       placeholder="{{ $a['caminho_padrao'] }}" autocomplete="off" spellcheck="false">
                <span class="campo-ajuda">
                    Em uso: <code>{{ $a['caminho'] }}</code>
                    @if ($a['origem'] === 'configuracoes') · padrão: <code>{{ $a['caminho_padrao'] }}</code>@endif
                </span>
                @error('caminhos.'.$chave)<span class="campo-erro-texto">{{ $message }}</span>@enderror
            </div>
        @endforeach
        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar caminhos</button>
            <span class="historico-nota">Antes de salvar, o painel confere se cada pasta existe e tem o <code>artisan</code>. Rotinas já criadas continuam na pasta em que foram criadas.</span>
        </div>
    </form>
</section>

@push('scripts')
<script>
(() => {
  'use strict';
  const form = document.getElementById('form-ambiente');
  const salvar = document.getElementById('salvar-ambiente');
  const atual = @json($atual);
  const titulos = @json($titulos, JSON_UNESCAPED_UNICODE);

  const escolhido = () => form.elements.ambiente.value;
  const desenhar = () => {
    salvar.disabled = escolhido() === atual;
    salvar.textContent = escolhido() === atual ? 'Em uso' : `Usar ${titulos[escolhido()] || escolhido()}`;
  };
  form.addEventListener('change', desenhar);
  form.addEventListener('submit', (evento) => {
    const chave = escolhido();
    const aviso = chave === 'PRD'
      ? 'Trocar para PRODUÇÃO?\n\nCommands, edições e reprocessamentos passam a valer nos dados reais.'
      : `Trocar para ${titulos[chave] || chave}?\n\nO painel passa a usar a pasta e o banco deste ambiente.`;
    if (!window.confirm(aviso)) evento.preventDefault();
  });
  desenhar();
})();
</script>
@endpush
@endsection
