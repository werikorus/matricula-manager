<?php

// Catálogo FICTÍCIO para a instância de demonstração (tools/demo). Imita o
// formato da tela "Dev > Commands" do matrícula, lido como texto pelo
// App\Services\CatalogoDoMatricula. Não é o arquivo real do matrícula.

namespace App\Http\Controllers\Dev;

class CommandsController
{
    private static function commands(): array
    {
        return [
            [
                'command'     => 'alteraStatusReservaPaga',
                'label'       => 'Altera Status Reserva Paga',
                'description' => 'Consulta o RM e marca como pagas as reservas com boleto quitado',
                'params'      => [],
            ],
            [
                'command'     => 'GeraParcelaCommand',
                'label'       => 'Gera Parcela',
                'description' => 'Gera as parcelas de uma solicitação',
                'params'      => [
                    ['name' => 'codigoSolicitacao', 'label' => 'Código da Solicitação', 'type' => 'argument', 'required' => true],
                ],
            ],
            [
                'command'     => 'integradorCommand',
                'label'       => 'Integrador',
                'description' => 'Integra matrículas com status aguardando integração',
                'params'      => [
                    ['name' => 'codigoSolicitacao', 'label' => 'Código da Solicitação (opcional)', 'type' => 'argument', 'required' => false],
                ],
            ],
            [
                'command'     => 'integradorLoteCommand',
                'label'       => 'Integrador em Lote',
                'description' => 'Reseta tentativas e reintegra em lote as solicitações 14/16 de um ano letivo ou de um processo',
                'params'      => [
                    ['name' => 'tipoFiltro', 'label' => 'Filtrar por', 'type' => 'argument', 'required' => true, 'options' => [
                        'anoLetivo' => 'Ano letivo',
                        'processo'  => 'Código do processo',
                    ]],
                    ['name' => 'valor', 'label' => 'Ano letivo ou código do processo', 'type' => 'argument', 'required' => true],
                ],
            ],
            [
                'command'     => 'ProcessaAdmissaoRMCommand',
                'label'       => 'Processa Admissão (RM)',
                'description' => 'Processa no RM os PABs sem boleto/contrato ou com erro de integração',
                'params'      => [
                    ['name' => 'codigoProcessoAdmissao', 'label' => 'Código da Admissão (opcional)', 'type' => 'argument', 'required' => false],
                ],
            ],
            [
                'command'     => 'ProcessaReservaRMCommand',
                'label'       => 'Processa Reserva (RM)',
                'description' => 'Integra a reserva de vaga com o RM e gera o boleto',
                'params'      => [
                    ['name' => 'codigoReserva', 'label' => 'Código da Reserva (opcional)', 'type' => 'argument', 'required' => false],
                ],
            ],
            [
                'command'     => 'EnviaEmailSolicitacaoCommand',
                'label'       => 'Envia E-mail Solicitação',
                'description' => 'Reenvia os e-mails pendentes das solicitações',
                'params'      => [
                    ['name' => 'dry-run', 'label' => 'Só simular', 'type' => 'flag'],
                ],
            ],
        ];
    }
}
