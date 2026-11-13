{{-- Diálogo de confirmação do "Reprocessar" de uma solicitação. Os botões
     com data-reprocessar-solicitacao abrem ele (ver tabela.js). --}}
<dialog id="confirmar-um" aria-labelledby="confirmar-um-titulo">
    <form method="POST" action="" class="dialogo" id="form-reprocessar-um"
          data-acao="{{ route('matriculas.reprocessar', ['codigo' => '__codigo__']) }}"
          data-carregando="Preparando a solicitação e iniciando a integração…">
        @csrf
        @if (! empty($voltar))
            <input type="hidden" name="voltar" value="{{ $voltar }}">
        @endif
        <h2 id="confirmar-um-titulo">Reprocessar a solicitação <span data-campo="codigo"></span> em {{ $ambiente }}?</h2>
        <p><strong data-campo="aluno"></strong> · situação <span data-campo="situacao"></span> · <span data-campo="tentativas"></span> tentativa(s)</p>

        <ol class="passos">
            <li>
                <strong>Atualiza a solicitação no banco</strong> (<code>tb_solicitacao</code>):
                <code>cd_situacao_solicitacao = 14</code>, <code>nu_quantidade_tentativas_integracao = 0</code> e <code>ds_erro = NULL</code>.
                <small>Só se ela ainda estiver em 14 ou 16; senão nada é alterado.</small>
            </li>
            <li>
                <strong>Integra com o RM</strong>:
                <code>php artisan {{ \App\Http\Controllers\MatriculaController::COMANDO }} <span data-campo="codigo"></span></code>
                <small>A saída aparece ao vivo na tela de Commands.</small>
            </li>
        </ol>

        <div class="dialogo-acoes">
            <button type="button" class="botao" onclick="this.closest('dialog').close()">Cancelar</button>
            <button type="submit" class="botao botao-primario" id="confirmar-um-enviar">Atualizar e integrar</button>
        </div>
    </form>
</dialog>
