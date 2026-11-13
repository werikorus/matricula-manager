<?php

namespace App\Http\Controllers;

use App\Services\Acessos;
use App\Services\EditorDeSolicitacao;
use App\Services\IpDaVpn;
use App\Support\Erros;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/** Tela de edição de um registro da tb_solicitacao. */
class SolicitacaoController extends Controller
{
    public function editar(string $codigo, EditorDeSolicitacao $editor, IpDaVpn $ip, Acessos $acessos): View
    {
        $codigo = (int) $codigo;
        $erro = null;
        $registro = null;
        $grupos = [];
        $situacoes = [];

        try {
            $grupos = $editor->agrupadas();
            $situacoes = $editor->situacoes();
            $registro = $editor->buscar($codigo);
            if ($registro === null) {
                $erro = "A solicitação {$codigo} não existe.";
            } else {
                $acessos->registrarSolicitacao($codigo, $registro['nm_aluno'] ?? null, $registro['cd_situacao_solicitacao'] ?? null);
            }
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        } catch (\PDOException $e) {
            $erro = 'Falha ao ler o banco do matrícula: '.$e->getMessage();
        }

        return view('solicitacoes.editar', [
            'codigo' => $codigo,
            'registro' => $registro,
            'grupos' => $grupos,
            'situacoes' => $situacoes,
            'erroResumo' => $registro ? Erros::resumir((string) ($registro['ds_erro'] ?? '')) : '',
            'erro' => $erro,
            'ambiente' => config('painel.matricula.ambiente'),
            'voltar' => $this->voltar(),
            'automaticos' => [
                'dh_alteracao' => 'agora, horário de Brasília (ao gravar)',
                'nu_ip_alteracao' => $ip->conhecido() ?: 'IP da VPN (descoberto ao gravar)',
            ],
        ]);
    }

    public function salvar(Request $request, string $codigo, EditorDeSolicitacao $editor): RedirectResponse
    {
        $codigo = (int) $codigo;
        $alteracoes = json_decode((string) $request->input('alteracoes'), true);

        if (! is_array($alteracoes) || $alteracoes === []) {
            return back()->with('erro', 'Nenhuma alteração recebida.');
        }

        // Cada item: {"valor": string|null, "nulo": bool, "original": string|null}
        foreach ($alteracoes as $campo => $item) {
            if (! is_string($campo) || ! is_array($item)
                || ! array_key_exists('original', $item)
                || (isset($item['valor']) && ! is_string($item['valor']))
                || (isset($item['original']) && ! is_string($item['original']))) {
                return back()->with('erro', 'Alterações em formato inválido.');
            }
        }

        try {
            $diferencas = $editor->salvar($codigo, $alteracoes, (string) $request->session()->get('usuario.email'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->with('rascunho', $request->input('alteracoes'));
        } catch (RuntimeException $e) {
            return back()->with('erro', $e->getMessage())->with('rascunho', $request->input('alteracoes'));
        }

        return redirect()
            ->route('solicitacoes.editar', ['codigo' => $codigo, 'voltar' => $request->query('voltar')])
            ->with('salvo', array_column($diferencas, 'campo'));
    }

    /** Volta para a lista de onde veio, se for uma tela do próprio painel. */
    private function voltar(): string
    {
        $voltar = (string) request()->query('voltar', '');

        return preg_match('#^/(?!/)[a-z0-9/_-]*(\?[^\s]*)?$#i', $voltar) ? $voltar : route('matriculas', [], false);
    }
}
