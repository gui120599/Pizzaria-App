<?php

namespace App\Services\Financeiro;

use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\Financeiro\StatusConciliacaoExtrato;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Models\ExtratoBancarioItem;
use App\Models\Lancamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\User;
use App\Services\Compras\PendenciaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conciliação de boletos pelo extrato bancário (regra 17):
 * - 1 parcela em aberto de fornecedor "por conciliação" nesta conta, com o
 *   valor exato e vencimento na janela → baixa na data do extrato;
 * - 2+ parcelas → débito "ambíguo" e pendência "pagamento a confirmar";
 * - nenhuma, mas uma saída já registrada à mão bate → só concilia;
 * - nada (inclusive valor com juros/multa) → "sem correspondência", para
 *   conciliar à mão registrando o valor pago real.
 */
class ConciliacaoBoletoService
{
    private const OBSERVACAO = 'Conciliado com o extrato bancário';

    public function __construct(private PendenciaService $pendencias) {}

    public function conciliar(ExtratoBancarioItem $item): StatusConciliacaoExtrato
    {
        if (! $item->ehDebito() || $item->status_conciliacao !== StatusConciliacaoExtrato::Pendente) {
            return $item->status_conciliacao;
        }

        $parcelas = $this->parcelasCandidatas($item);

        if ($parcelas->count() === 1) {
            $this->baixar($item, $parcelas->first());

            return StatusConciliacaoExtrato::Conciliado;
        }

        if ($parcelas->count() > 1) {
            $this->marcarAmbiguo($item, $parcelas);

            return StatusConciliacaoExtrato::Ambiguo;
        }

        $movimentacoes = $this->movimentacoesJaRegistradas($item);
        if ($movimentacoes->count() === 1) {
            $this->vincularMovimentacao($item, $movimentacoes->first());

            return StatusConciliacaoExtrato::Conciliado;
        }

        $item->forceFill(['status_conciliacao' => StatusConciliacaoExtrato::SemCorrespondencia])->save();

        return StatusConciliacaoExtrato::SemCorrespondencia;
    }

    /**
     * Conciliação manual: baixa o título com o valor do débito (que pode ter
     * juros/multa) na data do extrato.
     *
     * @throws ValidationException
     */
    public function conciliarManual(ExtratoBancarioItem $item, Lancamento $titulo, ?User $usuario = null): void
    {
        if (! $item->ehDebito() || $item->status_conciliacao === StatusConciliacaoExtrato::Conciliado) {
            throw ValidationException::withMessages(['extrato' => 'Esta linha do extrato já está conciliada ou não é um débito.']);
        }

        if (! in_array($titulo->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)) {
            throw ValidationException::withMessages(['lancamento' => 'Este título não está em aberto.']);
        }

        $this->baixar($item, $titulo, $usuario);
    }

    /** Débito que corresponde a uma saída já registrada (baixa feita à mão antes do extrato). */
    public function vincularMovimentacao(ExtratoBancarioItem $item, MovimentacaoFinanceira $movimentacao): void
    {
        DB::transaction(function () use ($item, $movimentacao): void {
            $item->forceFill([
                'status_conciliacao' => StatusConciliacaoExtrato::Conciliado,
                'movimentacao_financeira_id' => $movimentacao->id,
            ])->save();

            $movimentacao->pagamento?->forceFill(['conciliado_em' => now()])->save();
        });
    }

    public function ignorar(ExtratoBancarioItem $item): void
    {
        $item->forceFill(['status_conciliacao' => StatusConciliacaoExtrato::Ignorado])->save();
    }

    /**
     * Parcelas em aberto de fornecedores "por conciliação" com esta conta,
     * valor restante exato e vencimento na janela do débito.
     *
     * @return Collection<int, Lancamento>
     */
    public function parcelasCandidatas(ExtratoBancarioItem $item): Collection
    {
        $data = $item->data_lancamento->copy()->startOfDay();

        return Lancamento::query()
            ->pagar()
            ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereBetween('vencimento', [
                $data->copy()->subDays((int) config('bancos.conciliacao.dias_antes', 5))->toDateString(),
                $data->copy()->addDays((int) config('bancos.conciliacao.dias_depois', 3))->toDateString(),
            ])
            ->whereHas('compra.prestador.condicaoCompra', fn (Builder $condicao): Builder => $condicao
                ->where('fcc_regra_baixa', RegraBaixaCompra::PorConciliacao)
                ->where('fcc_conta_financeira_id', $item->conta_financeira_id))
            ->with('pagamentos')
            ->get()
            ->filter(fn (Lancamento $titulo): bool => abs((float) $titulo->valorRestante - (float) $item->valor) < 0.005)
            ->values();
    }

    /** @return Collection<int, MovimentacaoFinanceira> */
    private function movimentacoesJaRegistradas(ExtratoBancarioItem $item): Collection
    {
        $data = $item->data_lancamento->copy()->startOfDay();
        $jaConciliadas = ExtratoBancarioItem::query()->whereNotNull('movimentacao_financeira_id')->select('movimentacao_financeira_id');

        return MovimentacaoFinanceira::query()
            ->efetivas()
            ->where('conta_financeira_id', $item->conta_financeira_id)
            ->where('tipo', TipoMovimentacao::Saida)
            ->where('valor', $item->valor)
            ->whereBetween('data_movimento', [
                $data->copy()->subDays((int) config('bancos.conciliacao.dias_antes', 5))->toDateString(),
                $data->copy()->addDays((int) config('bancos.conciliacao.dias_depois', 3))->toDateString(),
            ])
            ->whereNotIn('id', $jaConciliadas)
            ->get();
    }

    private function baixar(ExtratoBancarioItem $item, Lancamento $titulo, ?User $usuario = null): void
    {
        DB::transaction(function () use ($item, $titulo, $usuario): void {
            $pagamento = $titulo->registrarPagamento(
                valor: (float) $item->valor,
                data: $item->data_lancamento->copy(),
                forma: $titulo->forma_pagamento ?? FormaPagamento::Boleto,
                observacoes: self::OBSERVACAO,
                contaFinanceiraId: $item->conta_financeira_id,
                ignorarSaldo: true,
            );
            $pagamento->forceFill(['conciliado_em' => now()])->save();

            $item->forceFill([
                'status_conciliacao' => StatusConciliacaoExtrato::Conciliado,
                'movimentacao_financeira_id' => MovimentacaoFinanceira::query()->where('lancamento_pagamento_id', $pagamento->id)->value('id'),
            ])->save();

            $this->pendencias->resolverDoLancamento($titulo, $usuario);
            $this->pendencias->resolverDoExtrato($item, $titulo, $usuario);
        });
    }

    /** @param  Collection<int, Lancamento>  $parcelas */
    private function marcarAmbiguo(ExtratoBancarioItem $item, Collection $parcelas): void
    {
        $item->forceFill(['status_conciliacao' => StatusConciliacaoExtrato::Ambiguo])->save();

        $this->pendencias->abrir(TipoPendenciaCompra::PagamentoAConfirmar, $parcelas->first()->compra, null, [
            'motivo' => 'Débito do extrato com mais de uma parcela possível: escolha qual foi paga.',
            'extrato_item_id' => $item->id,
            'valor' => (float) $item->valor,
            'data' => $item->data_lancamento->toDateString(),
            'historico' => $item->historico,
            'candidatos' => $parcelas->map(fn (Lancamento $t): array => [
                'lancamento_id' => $t->id,
                'compra_id' => $t->compra_id,
                'descricao' => $t->descricao,
                'valor' => (float) $t->valorRestante,
                'vencimento' => $t->vencimento?->toDateString(),
                'status' => $t->status->value,
            ])->all(),
        ]);
    }
}
