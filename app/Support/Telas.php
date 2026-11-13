<?php

namespace App\Support;

/**
 * Telas do painel: alimenta o menu lateral e os atalhos da tela Início.
 * Para uma tela nova aparecer nos dois lugares, basta incluir aqui.
 */
class Telas
{
    public const INICIO = [
        'rota' => 'inicio', 'texto' => 'Início', 'carregando' => 'Abrindo o início…',
        'descricao' => 'Atalhos para o que você mais usa.',
        'icone' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
    ];

    /** @return array<string, array> rota => tela (sem o Início) */
    public static function lista(): array
    {
        return [
            'commands' => [
                'rota' => 'commands', 'texto' => 'Commands', 'carregando' => 'Abrindo commands…',
                'descricao' => 'Executar as rotinas do matrícula e acompanhar a saída e os logs.',
                'icone' => '<path d="M5 8l5 4-5 4M13 16h6"/>',
            ],
            'historico' => [
                'rota' => 'historico', 'texto' => 'Histórico de commands', 'carregando' => 'Abrindo o histórico…',
                'descricao' => 'Execuções feitas pelo painel, com a saída de cada uma.',
                'icone' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
            ],
            'alunos' => [
                'rota' => 'alunos', 'texto' => 'Consultar aluno', 'carregando' => 'Abrindo a consulta…',
                'descricao' => 'Buscar por nome, CPF, RA ou solicitação e editar a matrícula.',
                'icone' => '<circle cx="10" cy="8" r="4"/><path d="M3 20c0-3.3 2.7-6 6-6h2"/><circle cx="17" cy="17" r="3"/><path d="m21 21-1.8-1.8"/>',
            ],
            'pabs' => [
                'rota' => 'pabs', 'texto' => 'PABs parados', 'carregando' => 'Consultando PABs parados…',
                'descricao' => 'Inscrições sem boleto/contrato ou com erro de integração.',
                'icone' => '<path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
            ],
            'matriculas' => [
                'rota' => 'matriculas', 'texto' => 'Matrículas paradas', 'carregando' => 'Consultando matrículas paradas…',
                'descricao' => 'Solicitações aguardando integração ou com erro no RM.',
                'icone' => '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>',
            ],
            'reservas' => [
                'rota' => 'reservas', 'texto' => 'Reservas paradas', 'carregando' => 'Consultando reservas paradas…',
                'descricao' => 'Reservas com erro, sem boleto ou aguardando pagamento.',
                'icone' => '<path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
            ],
        ];
    }

    /** Itens do menu lateral, com o Início primeiro. */
    public static function menu(): array
    {
        return array_values(array_merge([self::INICIO], self::lista()));
    }
}
