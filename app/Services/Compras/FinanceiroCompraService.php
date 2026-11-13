<?php

namespace App\Services\Compras;

use App\Enums\BaseVencimentoEnum;
use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\VencimentoOrigemEnum;
use App\Exceptions\FinanceiroException;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\FornecedorCondicaoCompra;
use App\Models\Lancamento;
use App\Models\User;
use App\Services\CompraService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contas a pagar das compras importadas pela automação (regras 3 a 8 da spec
 * de compras automatizadas):
 * - procura título já lançado à mão e vincula em vez de duplicar;
 * - gera um título por parcela (duplicatas da nota, prazo do fornecedor ou à
 *   vista na emissão) já na importação, com a compra em rascunho (Q1);
 * - aplica a regra de baixa do fornecedor, sempre pelo fluxo de baixa
 *   existente (Lancamento::registrarPagamento → BaixaFinanceira).
 *
 * Nunca falha a importação por causa da baixa: o que não der certo vira
 * pendência e o título fica aberto.
 */
class FinanceiroCompraService
{
    private const OBSERVACAO_BAIXA = 'Baixa automática (compras)';

    public function __construct(
        private CompraService $compras,
        private SugestaoPagamentoCompra $sugestoes,
        private PendenciaService $pendencias,
    ) {}

    public function processar(Compra $compra): void
    {
        $compra->loadMissing('prestador.condicaoCompra');

        if ($compra->lancamentos()->exists()) {
            return;
        }

        $candidatos = $this->localizarLancamentoExistente($compra);

        if ($candidatos->count() === 1) {
            $candidatos->first()->forceFill(['compra_id' => $compra->id])->save();

            return;
        }

        if ($candidatos->count() > 1) {
            $this->pendencias->abrir(TipoPendenciaCompra::LancamentoAmbiguo, $compra, null, [
                'candidatos' => $candidatos->map(fn (Lancamento $lancamento): array => [
                    'lancamento_id' => $lancamento->id,
                    'descricao' => $lancamento->descricao,
                    'valor' => (float) $lancamento->valor,
                    'vencimento' => $lancamento->vencimento?->toDateString(),
                    'status' => $lancamento->status->value,
                ])->all(),
            ]);

            return;
        }

        $this->gerarContasPagar($compra);
    }

    /**
     * Títulos a pagar do mesmo fornecedor, sem compra, com o valor da nota
     * (± tolerância) e vencimento a até N dias da emissão, pagos ou não.
     *
     * @return Collection<int, Lancamento>
     */
    public function localizarLancamentoExistente(Compra $compra): Collection
    {
        if ($compra->compra_prestador_id === null) {
            return new Collection;
        }

        $valor = $this->valorDaNota($compra);
        $tolerancia = (float) config('compras.lancamento_existente.tolerancia_valor', 0.05);
        $janela = (int) config('compras.lancamento_existente.janela_dias', 10);
        $emissao = ($compra->compra_data_emissao ?? $compra->created_at ?? now())->copy()->startOfDay();

        return Lancamento::query()
            ->pagar()
            ->whereNull('compra_id')
            ->where('favorecido_id', $compra->compra_prestador_id)
            ->where('status', '!=', StatusLancamento::Cancelado)
            ->whereBetween('valor', [round($valor - $tolerancia, 2), round($valor + $tolerancia, 2)])
            ->whereBetween('vencimento', [$emissao->copy()->subDays($janela)->toDateString(), $emissao->copy()->addDays($janela)->toDateString()])
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Lancamento> */
    public function gerarContasPagar(Compra $compra): Collection
    {
        $condicao = $compra->prestador?->condicaoCompra;
        [$parcelas, $prazoId, $origem, $divergencia] = $this->montarParcelas($compra, $condicao);

        $titulos = $this->compras->criarTitulos($compra, $parcelas, $prazoId, $condicao?->fcc_plano_despesa_id);
        $compra->forceFill(['compra_vencimento_origem' => $origem])->save();

        if ($divergencia !== null) {
            $this->pendencias->abrir(TipoPendenciaCompra::DivergenciaValor, $compra, null, $divergencia);

            return $titulos;
        }

        if ($condicao?->regraBaixa() === RegraBaixaCompra::AutomaticaNaEmissao) {
            $data = ($compra->compra_data_emissao ?? today())->copy()->startOfDay();
            $this->baixar($compra, $titulos, $condicao, fn (Lancamento $titulo): Carbon => $data);
        }

        return $titulos;
    }

    /**
     * Regra automatica_no_vencimento: baixa as parcelas em aberto das compras
     * automatizadas que vencem até hoje, na data do vencimento.
     */
    public function baixarVencidos(?Carbon $hoje = null): int
    {
        $hoje = ($hoje ?? today())->copy()->startOfDay();

        $titulos = Lancamento::query()
            ->pagar()
            ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereDate('vencimento', '<=', $hoje)
            ->whereHas('compra', fn ($query) => $query
                ->where('compra_importacao_automatica', true)
                ->whereHas('prestador.condicaoCompra', fn ($condicao) => $condicao->where('fcc_regra_baixa', RegraBaixaCompra::AutomaticaNoVencimento)))
            ->with('compra.prestador.condicaoCompra')
            ->orderBy('vencimento')
            ->get();

        $baixados = 0;
        foreach ($titulos->groupBy('compra_id') as $daCompra) {
            $compra = $daCompra->first()->compra;
            $baixados += $this->baixar($compra, $daCompra, $compra->prestador->condicaoCompra, fn (Lancamento $titulo): Carbon => $titulo->vencimento->copy());
        }

        return $baixados;
    }

    /**
     * Confirmação humana de pagamento (página "Pagamentos a confirmar"): baixa
     * normal, com validação de saldo e sessão, e resolve as pendências de
     * baixa do título. Sem valor, paga o que falta; com valor maior (juros,
     * multa), registra o que saiu de fato.
     *
     * @throws FinanceiroException
     * @throws ValidationException
     */
    public function confirmarPagamento(
        Lancamento $titulo,
        ?float $valor,
        Carbon $data,
        ?FormaPagamento $forma,
        int $contaFinanceiraId,
        ?int $sessaoCaixaId = null,
        ?User $usuario = null,
    ): Lancamento {
        return DB::transaction(function () use ($titulo, $valor, $data, $forma, $contaFinanceiraId, $sessaoCaixaId, $usuario): Lancamento {
            $titulo = Lancamento::query()->lockForUpdate()->findOrFail($titulo->getKey());

            if (! in_array($titulo->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)) {
                throw ValidationException::withMessages(['lancamento' => 'Este título já foi pago ou cancelado.']);
            }

            $titulo->registrarPagamento(
                valor: round($valor ?? (float) $titulo->valorRestante, 2),
                data: $data,
                forma: $forma,
                observacoes: 'Pagamento confirmado (compras)',
                sessaoCaixaId: $sessaoCaixaId,
                contaFinanceiraId: $contaFinanceiraId,
            );

            $this->pendencias->resolverDoLancamento($titulo, $usuario);

            return $titulo->refresh();
        });
    }

    public function valorDaNota(Compra $compra): float
    {
        return round((float) ($compra->compra_valor_nf ?? $compra->compra_valor_total), 2);
    }

    /**
     * @param  Collection<int, Lancamento>  $titulos
     * @param  callable(Lancamento): Carbon  $dataDoPagamento
     * @return int títulos baixados
     */
    private function baixar(Compra $compra, Collection $titulos, FornecedorCondicaoCompra $condicao, callable $dataDoPagamento): int
    {
        $conta = $condicao->fcc_conta_financeira_id !== null ? ContaFinanceira::query()->find($condicao->fcc_conta_financeira_id) : null;

        if ($conta === null) {
            $this->pendencias->abrir(TipoPendenciaCompra::PagamentoAConfirmar, $compra, null, [
                'motivo' => 'O fornecedor não tem conta de origem cadastrada (ou ela foi desativada).',
                'lancamentos' => $titulos->pluck('id')->all(),
            ]);

            return 0;
        }

        $baixados = 0;
        foreach ($titulos as $titulo) {
            $titulo->refresh();
            if (! in_array($titulo->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)) {
                continue;
            }

            try {
                $titulo->registrarPagamento(
                    valor: (float) $titulo->valorRestante,
                    data: $dataDoPagamento($titulo),
                    forma: $condicao->fcc_forma_pagamento ?? $titulo->forma_pagamento,
                    observacoes: self::OBSERVACAO_BAIXA,
                    contaFinanceiraId: $conta->id,
                    ignorarSaldo: true,
                );
                $baixados++;
            } catch (FinanceiroException $e) {
                $tipo = $conta->ehCaixaPdv() ? TipoPendenciaCompra::CaixaPdvFechado : TipoPendenciaCompra::PagamentoAConfirmar;
                $this->pendencias->abrir($tipo, $compra, null, [
                    'motivo' => $e->getMessage(),
                    'lancamento_id' => $titulo->id,
                    'conta_financeira_id' => $conta->id,
                ]);
            }
        }

        return $baixados;
    }

    /**
     * Parcelas na ordem: duplicatas da nota, prazo do fornecedor, à vista na
     * emissão. Soma das duplicatas diferente do valor da nota devolve a
     * divergência (vira pendência e trava a baixa automática).
     *
     * @return array{0: list<array{vencimento: string, valor: float, forma_pagamento: ?string}>, 1: ?int, 2: VencimentoOrigemEnum, 3: ?array<string, float>}
     */
    private function montarParcelas(Compra $compra, ?FornecedorCondicaoCompra $condicao): array
    {
        $valorNota = $this->valorDaNota($compra);
        $emissao = ($compra->compra_data_emissao ?? today())->copy()->startOfDay();
        $forma = ($condicao?->fcc_forma_pagamento ?? $this->sugestoes->formaDaNota($compra))?->value;

        $duplicatas = collect($compra->compra_duplicatas_nf ?? [])
            ->filter(fn (array $dup): bool => filled($dup['dVenc'] ?? null) && (float) ($dup['vDup'] ?? 0) > 0)
            ->map(fn (array $dup): array => [
                'vencimento' => Carbon::parse($dup['dVenc'])->toDateString(),
                'valor' => round((float) $dup['vDup'], 2),
                'forma_pagamento' => $forma,
            ])
            ->values()
            ->all();

        if ($duplicatas !== []) {
            $soma = round(array_sum(array_column($duplicatas, 'valor')), 2);
            $tolerancia = (float) config('compras.lancamento_existente.tolerancia_valor', 0.05);
            $divergencia = abs($soma - $valorNota) > $tolerancia ? ['soma_duplicatas' => $soma, 'valor_nota' => $valorNota] : null;

            return [$duplicatas, null, VencimentoOrigemEnum::DuplicatasNf, $divergencia];
        }

        $prazo = $this->sugestoes->prazoValido($condicao);
        if ($prazo !== null) {
            $doEmissao = $condicao->fcc_prazo_base === BaseVencimentoEnum::Emissao;
            $base = $doEmissao ? $emissao : ($compra->compra_data_entrada ?? $emissao)->copy()->startOfDay();
            $parcelas = array_map(
                fn (array $linha): array => [
                    'vencimento' => $linha['vencimento'],
                    'valor' => (float) $linha['valor'],
                    'forma_pagamento' => $linha['forma_pagamento'],
                ],
                array_values($this->sugestoes->parcelasDoPrazo($prazo, $base, $valorNota, $condicao, FormaPagamento::tryFrom((string) $forma))),
            );

            return [$parcelas, $prazo->id, $doEmissao ? VencimentoOrigemEnum::PrazoEmissao : VencimentoOrigemEnum::PrazoEntrada, null];
        }

        return [[['vencimento' => $emissao->toDateString(), 'valor' => $valorNota, 'forma_pagamento' => $forma]], null, VencimentoOrigemEnum::PrazoEmissao, null];
    }
}
