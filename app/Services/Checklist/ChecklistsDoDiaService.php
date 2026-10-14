<?php

namespace App\Services\Checklist;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExecucaoStatus;
use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\ChecklistNaoConformidade;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Leitura do /garcom: os checklists do dia operacional para o usuário e as
 * pendências que o último fechamento deixou para a abertura.
 */
class ChecklistsDoDiaService
{
    public const EM_ANDAMENTO = 'em_andamento';

    public const NA_JANELA = 'na_janela';

    public const FUTURO = 'futuro';

    public const ENCERRADO = 'encerrado';

    private const ORDEM = [self::EM_ANDAMENTO => 0, self::NA_JANELA => 1, self::FUTURO => 2, self::ENCERRADO => 3];

    /**
     * Cartões do dia (execuções de hoje + modelos agendados que ainda não têm
     * execução) e os modelos que podem ser iniciados a qualquer momento (sob
     * demanda ou com várias execuções por dia), no escopo do painel
     * (ChecklistModelo::paraPainel).
     *
     * @return array{hoje: list<array{chave: string, modelo_id: int, execucao_id: ?int, nome: string, tipo: ChecklistTipo, setor: ChecklistSetor, janela: ?string, status: ExecucaoStatus, respondidos: int, total: int, situacao: string}>, outros: list<ChecklistModelo>}
     */
    public function para(User $user, string $painel = ChecklistModelo::PAINEL_GARCOM, ?Model $dispositivo = null, ?CarbonInterface $agora = null): array
    {
        $agora = CarbonImmutable::instance($agora ?? now());
        $dia = ChecklistExecucao::dataOperacional($agora);

        $modelos = ChecklistModelo::query()
            ->ativos()
            ->paraPainel($painel, $dispositivo)
            ->disponiveisPara($user)
            ->withCount('itens')
            ->with(['execucoes' => fn ($execucoes) => $execucoes->doDia($dia)->orderBy('id')])
            ->orderBy('nome')
            ->get();

        $hoje = [];
        $outros = [];

        foreach ($modelos as $modelo) {
            foreach ($modelo->execucoes as $execucao) {
                $hoje[] = $this->cartao($modelo, $execucao, $dia, $agora);
            }

            if ($modelo->execucoes->isEmpty() && $modelo->agendadoPara($dia)) {
                $hoje[] = $this->cartao($modelo, null, $dia, $agora);
            }

            if ($modelo->sobDemanda() || $modelo->permite_multiplas_execucoes_dia) {
                $outros[] = $modelo;
            }
        }

        usort($hoje, fn (array $a, array $b): int => [self::ORDEM[$a['situacao']], $a['janela'] ?? '99', $a['nome']]
            <=> [self::ORDEM[$b['situacao']], $b['janela'] ?? '99', $b['nome']]);

        return ['hoje' => $hoje, 'outros' => $outros];
    }

    /** Checklists pendentes cuja janela está aberta agora (badge da navegação). */
    public function pendentesNaJanela(User $user, string $painel = ChecklistModelo::PAINEL_GARCOM, ?Model $dispositivo = null, ?CarbonInterface $agora = null): int
    {
        return collect($this->para($user, $painel, $dispositivo, $agora)['hoje'])
            ->where('situacao', self::NA_JANELA)
            ->count();
    }

    /**
     * O que o último fechamento finalizado do mesmo setor deixou para a
     * abertura: não conformidades ainda pendentes e recados (observação geral
     * e itens de texto respondidos).
     *
     * @return array{fechamento: ?ChecklistExecucao, naoConformidades: Collection<int, ChecklistNaoConformidade>, recados: list<string>}
     */
    public function pendenciasDoUltimoFechamento(ChecklistExecucao $abertura): array
    {
        $vazio = ['fechamento' => null, 'naoConformidades' => new Collection, 'recados' => []];

        if ($abertura->tipo !== ChecklistTipo::Abertura) {
            return $vazio;
        }

        $fechamento = ChecklistExecucao::query()
            ->where('tipo', ChecklistTipo::Fechamento)
            ->where('setor', $abertura->setor)
            ->whereNotNull('concluido_em')
            ->whereDate('data_referencia', '<', $abertura->data_referencia->format('Y-m-d'))
            ->latest('data_referencia')
            ->latest('id')
            ->first();

        if ($fechamento === null) {
            return $vazio;
        }

        $naoConformidades = ChecklistNaoConformidade::pendentes()
            ->whereHas('execucaoItem', fn ($itens) => $itens->where('checklist_execucao_id', $fechamento->id))
            ->with('execucaoItem')
            ->get();

        $recados = $fechamento->itens()
            ->where('tipo_resposta', TipoResposta::Texto)
            ->whereNotNull('valor_texto')
            ->pluck('valor_texto')
            ->prepend($fechamento->observacao_geral)
            ->filter()
            ->values()
            ->all();

        return ['fechamento' => $fechamento, 'naoConformidades' => $naoConformidades, 'recados' => $recados];
    }

    /** @return array{chave: string, modelo_id: int, execucao_id: ?int, nome: string, tipo: ChecklistTipo, setor: ChecklistSetor, janela: ?string, status: ExecucaoStatus, respondidos: int, total: int, situacao: string} */
    private function cartao(ChecklistModelo $modelo, ?ChecklistExecucao $execucao, CarbonImmutable $dia, CarbonImmutable $agora): array
    {
        $status = $execucao?->status ?? ExecucaoStatus::Pendente;

        return [
            'chave' => $execucao ? "execucao-{$execucao->id}" : "modelo-{$modelo->id}",
            'modelo_id' => $modelo->id,
            'execucao_id' => $execucao?->id,
            'nome' => $execucao->modelo_nome ?? $modelo->nome,
            'tipo' => $execucao->tipo ?? $modelo->tipo,
            'setor' => $execucao->setor ?? $modelo->setor,
            'janela' => $modelo->janelaDescricao(),
            'status' => $status,
            'respondidos' => $execucao->itens_respondidos ?? 0,
            'total' => $execucao->total_itens ?? $modelo->itens_count,
            'situacao' => $this->situacao($modelo, $status, $dia, $agora),
        ];
    }

    private function situacao(ChecklistModelo $modelo, ExecucaoStatus $status, CarbonImmutable $dia, CarbonImmutable $agora): string
    {
        if (in_array($status, [ExecucaoStatus::EmAndamento, ExecucaoStatus::Reprovado], true)) {
            return self::EM_ANDAMENTO;
        }

        if ($status !== ExecucaoStatus::Pendente) {
            return self::ENCERRADO;
        }

        $inicio = $modelo->inicioDaJanela($dia);
        $expira = $modelo->prazoExpiracao($dia);

        return match (true) {
            $inicio !== null && $agora->lessThan($inicio) => self::FUTURO,
            $expira !== null && $agora->greaterThan($expira) => self::ENCERRADO,
            default => self::NA_JANELA,
        };
    }
}
