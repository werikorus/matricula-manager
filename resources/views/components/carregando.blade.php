{{-- Carregando da Bernoulli: spinner em volta da lemniscata, igual ao
     components/loading-overlay do matrícula. Aparece ao clicar em links ou
     enviar formulários marcados com data-carregando (veja js/carregando.js). --}}
<div class="carregando" id="carregando" role="status" aria-live="polite" hidden>
    <div class="carregando-conteudo">
        <div class="carregando-anel">
            <img src="{{ asset('img/bernoulli-infinito.png') }}" alt="" class="carregando-logo">
        </div>
        <p class="carregando-texto" id="carregando-texto">Carregando…</p>
    </div>
</div>
