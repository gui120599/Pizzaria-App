<?php

namespace App\Console\Commands;

use App\Enums\StatusFechamentoCaixa;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\Maquininha;
use App\Models\MovimentacaoFinanceira;
use App\Models\OpcoesPagamento;
use App\Models\SessaoCaixa;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Backfill da contabilização: leva às contas financeiras os fechamentos
 * confirmados que a importação antiga só lançou no Contas a Receber (títulos
 * sem conta, sem movimentação). Os títulos antigos são apagados e a sessão é
 * contabilizada sem recolhimento. Fechamentos confirmados antes do corte
 * (`--desde`, padrão: a menor data de saldo inicial das contas de destino)
 * ficam de fora: o saldo inicial informado já os contém.
 */
class ContabilizarFechamentos extends Command
{
    protected $signature = 'financeiro:contabilizar-fechamentos
        {--desde= : Só fechamentos confirmados a partir desta data (padrão: menor saldo inicial das contas de destino)}
        {--dry-run : Mostra o que seria lançado e os saldos projetados, sem gravar}';

    protected $description = 'Contabiliza nas contas financeiras os fechamentos confirmados ainda não contabilizados';

    public function handle(ContabilizacaoFechamentoService $contabilizacao): int
    {
        $desde = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : $this->corteDasContas();

        if ($desde === null) {
            $this->error('Nenhuma conta de destino cadastrada (Caixa PDV, maquininhas ou formas de pagamento com conta).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[SIMULAÇÃO] ' : '').'Fechamentos confirmados a partir de '.$desde->format('d/m/Y').'.');

        $fechamentos = FechamentoCaixa::query()
            ->where('status', StatusFechamentoCaixa::Confirmado)
            ->where('confirmado_em', '>=', $desde)
            ->with('sessaoCaixa')
            ->orderBy('confirmado_em')
            ->get();

        $linhas = [];
        $saldos = [];
        $falhas = 0;

        $dryRun && DB::beginTransaction();

        try {
            foreach ($fechamentos as $fechamento) {
                $sessao = $fechamento->sessaoCaixa;

                if ($contabilizacao->contabilizada($sessao) && $this->movimentou($sessao)) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($sessao, $contabilizacao, &$linhas): void {
                        $this->apagarImportacaoAntiga($sessao);

                        if (! $contabilizacao->podeContabilizar($sessao->refresh())) {
                            return;
                        }

                        $linhas[] = $this->resumo($sessao, $contabilizacao->contabilizar($sessao));
                    });
                } catch (ValidationException|FinanceiroException $e) {
                    $falhas++;
                    $this->warn("Sessão #{$sessao->id}: ".($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()));
                }
            }

            $saldos = ContaFinanceira::query()->ordenadas()->get()->map(fn (ContaFinanceira $conta): array => [$conta->nome, number_format((float) $conta->fresh()->saldo_atual, 2, ',', '.')])->all();
        } finally {
            $dryRun && DB::rollBack();
        }

        $this->table(['Sessão', 'Dinheiro (Caixa PDV)', 'Outras contas', 'Recebíveis', 'Taxa prevista', 'Quebra (−)/Sobra (+)'], $linhas);
        $this->table(['Conta', $dryRun ? 'Saldo projetado' : 'Saldo atual'], $saldos);

        if ($dryRun) {
            $this->comment('Nada foi gravado.');
        } else {
            $this->comment('Rode financeiro:recalcular-saldos e financeiro:liquidar-recebiveis em seguida.');
        }

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Menor data de saldo inicial entre as contas de destino da contabilização. */
    private function corteDasContas(): ?Carbon
    {
        $ids = collect([ContaFinanceira::caixaPdv()?->id])
            ->merge(Maquininha::query()->whereNotNull('conta_financeira_id')->pluck('conta_financeira_id'))
            ->merge(OpcoesPagamento::query()->whereNotNull('conta_financeira_id')->pluck('conta_financeira_id'))
            ->filter()
            ->unique();

        $menor = ContaFinanceira::query()->whereKey($ids)->min('data_saldo_inicial');

        return $menor ? Carbon::parse($menor)->startOfDay() : null;
    }

    private function movimentou(SessaoCaixa $sessao): bool
    {
        return MovimentacaoFinanceira::query()->whereIn('lancamento_id', $sessao->lancamentos()->select('id'))->exists();
    }

    /** Importação antiga: títulos sem movimentação nenhuma; saem para a sessão ser contabilizada de novo. */
    private function apagarImportacaoAntiga(SessaoCaixa $sessao): void
    {
        $titulos = $sessao->lancamentos()->get();

        if ($titulos->isEmpty() || $this->movimentou($sessao)) {
            return;
        }

        foreach ($titulos as $titulo) {
            $titulo->estornarPagamento('Reimportação: contabilização nas contas financeiras.');
            $titulo->delete();
        }
    }

    /**
     * @param  Collection<int, Lancamento>  $lancamentos
     * @return array<int, string>
     */
    private function resumo(SessaoCaixa $sessao, $lancamentos): array
    {
        $caixaPdvId = ContaFinanceira::caixaPdv()?->id;
        $moeda = fn (float $valor): string => number_format($valor, 2, ',', '.');

        $movimentacoes = MovimentacaoFinanceira::query()->whereIn('lancamento_id', $lancamentos->pluck('id'))->get();
        $recebiveis = $lancamentos->whereNotNull('maquininha_id');
        $quebra = $lancamentos->whereNull('maquininha_id')->filter(fn (Lancamento $lancamento): bool => str_contains($lancamento->descricao, 'caixa —'));

        return [
            "#{$sessao->id}",
            $moeda($movimentacoes->where('conta_financeira_id', $caixaPdvId)->sum(fn (MovimentacaoFinanceira $m): float => $m->valorComSinal()) - $this->sinalQuebra($quebra)),
            $moeda($movimentacoes->where('conta_financeira_id', '!=', $caixaPdvId)->sum(fn (MovimentacaoFinanceira $m): float => $m->valorComSinal())),
            $moeda((float) $recebiveis->sum('valor')),
            $moeda((float) $recebiveis->sum('taxa_adquirente')),
            $moeda($this->sinalQuebra($quebra)),
        ];
    }

    /** Falta é negativa e sobra positiva. */
    private function sinalQuebra($quebra): float
    {
        return (float) $quebra->sum(fn (Lancamento $lancamento): float => $lancamento->tipo->value === 'pagar' ? -(float) $lancamento->valor : (float) $lancamento->valor);
    }
}
