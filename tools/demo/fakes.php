<?php
// Dados FICTÍCIOS da instância de demonstração (veja router.php). Nada aqui
// vem do banco real; os métodos que escrevem ou executam lançam exceção.
namespace Demo;

use App\Services\BancoDoMatricula;
use App\Services\EditorDeSolicitacao;
use RuntimeException;

class Dados
{
    const NOMES = [
        'Ana Clara Ribeiro Prado', 'Bruno Henrique Tavares', 'Carolina Mendes Farias', 'Davi Lucas Moreira',
        'Eduarda Fonseca Lima', 'Felipe Augusto Rocha', 'Gabriela Nunes Teixeira', 'Heitor Campos Vidal',
        'Isabela Duarte Couto', 'João Pedro Salgado', 'Larissa Pires Antunes', 'Miguel Arantes Braga',
        'Natália Queiroz Lopes', 'Otávio Brandão Reis', 'Pietra Guimarães Sá', 'Rafael Cardoso Viana',
    ];

    const PROCESSOS = [
        [9101, 'Matrícula 2027 · Unidade Centro'],
        [9102, 'Matrícula 2027 · Unidade Jardim'],
        [9087, 'Rematrícula 2026 · Unidade Centro'],
        [9090, 'Matrícula 2026 · Ensino Médio'],
    ];

    const ERROS = [
        "System.Exception: *** ERROS *** - Aluno já possui matrícula ativa no período letivo informado. Mais detalhes no log do RM.",
        "System.Exception: *** ERROS *** - Plano de pagamento não encontrado para a turma selecionada. Mais detalhes no log do RM.",
        "System.Exception: Tempo esgotado aguardando resposta do serviço de integração.",
        '',
    ];

    public static function data(int $diasAtras, int $hora = 9): string
    {
        return date('Y-m-d', strtotime("-{$diasAtras} days")).sprintf(' %02d:%02d:00.000', $hora + $diasAtras % 8, (7 * $diasAtras) % 60);
    }
}

class BancoFalso extends BancoDoMatricula
{
    public function pdo(): \PDO
    {
        throw new RuntimeException('Demonstração: sem banco.');
    }

    public function ultimoAnoLetivo(): ?int
    {
        return (int) date('Y') + 1;
    }

    public function matriculasParadas(int $ano): array
    {
        $linhas = [];
        foreach (array_slice(Dados::NOMES, 0, 11) as $i => $nome) {
            [$cdProcesso, $processo] = Dados::PROCESSOS[$i % 2 + ($ano < (int) date('Y') + 1 ? 2 : 0)];
            $status = $i % 3 === 0 ? 14 : 16;
            $dias = 1 + $i * 3;
            $linhas[] = [
                'cd_solicitacao' => 482310 + $i * 17, 'cd_contrato' => $i % 4 ? 75120 + $i : null, 'nu_ra' => sprintf('%06d', 31207 + $i * 11),
                'aluno' => $nome, 'cd_processo' => $cdProcesso, 'processo' => $processo, 'ano' => $ano,
                'status' => $status, 'tentativas' => $status === 14 ? 0 : 1 + $i % 3,
                'data_inclusao' => Dados::data($dias), 'dias_corridos' => $dias,
                'descricao_status' => $status === 14 ? 'Aguardando integrar RM' : 'COM ERRO - integração',
                'ds_erro' => $status === 14 ? '' : Dados::ERROS[$i % 3],
                'cd_coligada' => 1 + $i % 2, 'cd_filial' => 3 + $i % 4,
            ];
        }

        return $linhas;
    }

    public function pendentesDeIntegracaoPorProcesso(): array
    {
        $ano = (int) date('Y');

        return [
            ['cd_processo' => 9101, 'processo' => Dados::PROCESSOS[0][1], 'ano' => $ano + 1, 'total' => 6],
            ['cd_processo' => 9102, 'processo' => Dados::PROCESSOS[1][1], 'ano' => $ano + 1, 'total' => 5],
            ['cd_processo' => 9087, 'processo' => Dados::PROCESSOS[2][1], 'ano' => $ano, 'total' => 7],
            ['cd_processo' => 9090, 'processo' => Dados::PROCESSOS[3][1], 'ano' => $ano, 'total' => 4],
        ];
    }

    public function pabsParados(int $ano): array
    {
        $linhas = [];
        foreach (array_slice(Dados::NOMES, 5, 8) as $i => $nome) {
            $situacao = $i % 3 === 1 ? 11 : 3;
            $dias = 2 + $i * 4;
            $linhas[] = [
                'cd_admissao' => 65410 + $i * 23, 'cd_contrato' => null, 'nu_ra' => sprintf('%06d', 40812 + $i * 7),
                'cd_coligada' => 1, 'cd_filial' => 2 + $i % 3, 'nm_aluno' => $nome,
                'dh_inicio' => ($ano - 1).'-08-01 00:00:00.000', 'dh_termino' => $ano.'-03-31 23:59:00.000',
                'cd_situacao' => $situacao,
                'ds_situacao_admissao' => $situacao === 11 ? 'Erro na integração' : 'Processado',
                'nu_quantidade_tentativas' => $situacao === 11 ? 1 + $i % 3 : null,
                'dh_inclusao' => Dados::data($dias), 'nu_ano_letivo' => $ano, 'dias_corridos' => $dias,
            ];
        }

        return $linhas;
    }

    public function reservasParadas(int $ano): array
    {
        return [];
    }

    public function contagemDosCommandsDeReserva(int $ano): array
    {
        return ['integrar' => 0, 'pagamento' => 0];
    }

    public function solicitacoesDoLote(?int $ano, ?int $processo): array
    {
        return [482310 => 16, 482327 => 14, 482344 => 16];
    }

    /** Situação "atual" determinística pelo código: ~80% resolvidos, o resto parado ou com erro. */
    public function situacaoAtual(string $tipo, array $codigos): array
    {
        $erros = array_values(array_filter(Dados::ERROS));
        $atuais = [];
        foreach ($codigos as $codigo) {
            $sorte = crc32($tipo.$codigo) % 100;
            $resultado = $sorte < 80 ? 'resolvido' : ($sorte < 91 ? 'parado' : 'voltou');
            $erro = $erros[crc32((string) $codigo) % count($erros)];
            switch ($tipo) {
                case 'matriculas':
                    $atuais[$codigo] = ['situacao' => ['resolvido' => 2, 'parado' => 14, 'voltou' => 16][$resultado], 'erro' => $resultado === 'voltou' ? $erro : null];
                    break;
                case 'pabs':
                    $atuais[$codigo] = ['situacao' => ['resolvido' => 4, 'parado' => 3, 'voltou' => 11][$resultado], 'sem_boleto_contrato' => $resultado === 'parado'];
                    break;
                default:
                    $atuais[$codigo] = ['situacao' => ['resolvido' => 8, 'parado' => 6, 'voltou' => 7][$resultado], 'erro' => $resultado === 'voltou' ? $erro : null, 'tem_boleto' => $resultado === 'resolvido'];
            }
        }

        return $atuais;
    }

    /** Repete a última contagem do seed, para a foto de hoje continuar a linha. */
    public function contagemDeParadas(int $anoMinimo): array
    {
        $fotos = json_decode((string) @file_get_contents(dirname(__FILE__).'/storage/app/paradas/PRD.json'), true) ?: [];
        $ultima = $fotos ? end($fotos) : [];

        return [
            'matriculas' => (int) ($ultima['matriculas'] ?? 30),
            'pabs' => (int) ($ultima['pabs'] ?? 12),
            'reservas' => (int) ($ultima['reservas'] ?? 6),
        ];
    }

    public function prepararReintegracao(int $codigoSolicitacao, string $ipVpn): array
    {
        throw new RuntimeException('Demonstração: nada é alterado.');
    }

    public function buscarSolicitacoesDoAluno(string $termo): array
    {
        $ano = (int) date('Y');
        $linhas = [];
        foreach ([[16, 9101, $ano + 1, 0], [2, 9087, $ano, 120], [2, 9090, $ano - 1, 480]] as $i => [$status, $cd, $anoLetivo, $dias]) {
            $processo = $cd === 9090 ? 'Matrícula '.($ano - 1).' · Ensino Médio' : Dados::PROCESSOS[$cd === 9101 ? 0 : 2][1];
            $linhas[] = [
                'cd_solicitacao' => 482310 + $i * 9137, 'nm_aluno' => 'Ana Clara Ribeiro Prado', 'nu_ra' => '031207',
                'nu_cpf_aluno' => '000.000.000-00', 'dt_nascimento_aluno' => '2010-04-12',
                'status' => $status, 'ds_erro' => $status === 16 ? Dados::ERROS[1] : '',
                'tentativas' => $status === 16 ? 2 : 0, 'cd_contrato' => 75120 + $i, 'cd_turma' => '1EM-'.(10 + $i), 'ds_turno' => 'Manhã',
                'dh_inclusao' => Dados::data($dias + 3), 'dh_alteracao' => Dados::data($dias),
                'cd_processo' => $cd, 'processo' => $processo, 'ano' => $anoLetivo, 'cd_coligada' => 1, 'cd_filial' => 3,
            ];
        }

        return ['tipo' => 'nome', 'linhas' => $linhas, 'cortado' => false];
    }
}

class EditorFalso extends EditorDeSolicitacao
{
    public function situacoes(): array
    {
        return ['2' => 'Concluída', '14' => 'Aguardando integrar RM', '16' => 'COM ERRO - integração'];
    }
}

class ArtisanFalso extends \App\Services\MatriculaArtisan
{
    const EXIBIDO = '/home/usuario/development/matricula';

    private $lendo = false;

    public function caminho(): string
    {
        return $this->lendo ? dirname(__FILE__).'/matricula' : self::EXIBIDO;
    }

    public function catalogo(): array
    {
        $this->lendo = true;
        try {
            return parent::catalogo();
        } finally {
            $this->lendo = false;
        }
    }

    public function processo(array $argumentos, ?float $timeout = null): \Symfony\Component\Process\Process
    {
        throw new RuntimeException('Demonstração: nada é executado.');
    }
}
