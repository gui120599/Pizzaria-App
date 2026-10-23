<?php

namespace App\Services\Financeiro;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\Empresa;
use App\Models\Lancamento;
use App\Models\LancamentoPagamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Liquida os recebíveis de adquirente (títulos com `maquininha_id`) na data de
 * repasse: entrada do valor bruto na conta da maquininha e a taxa (MDR) como
 * despesa separada na mesma conta e data. Nada aqui toca sessão de caixa.
 */
class LiquidacaoRecebiveisService
{
    /**
     * @param  ?float  $valorLiquido  quanto a adquirente realmente creditou; sem ele vale a taxa prevista
     *
     * @throws FinanceiroException
     */
    public function liquidar(Lancamento $titulo, ?Carbon $data = null, ?float $valorLiquido = null): LancamentoPagamento
    {
        return DB::transaction(function () use ($titulo, $data, $valorLiquido): LancamentoPagamento {
            $titulo = Lancamento::query()->lockForUpdate()->with('maquininha.contaFinanceira')->findOrFail($titulo->id);

            $this->garantirLiquidavel($titulo);

            $data = ($data ?? $titulo->vencimento)->copy()->startOfDay();

            if ($data->isFuture()) {
                throw new FinanceiroException('Um recebível não pode ser liquidado numa data futura: o dinheiro ainda não caiu.');
            }

            $bruto = round((float) $titulo->valor, 2);
            $taxa = $valorLiquido === null ? round((float) $titulo->taxa_adquirente, 2) : round($bruto - $valorLiquido, 2);

            if ($taxa < 0 || $taxa > $bruto) {
                throw new FinanceiroException('O valor líquido recebido não pode ser maior que o valor do recebível nem negativo.');
            }

            $conta = $titulo->maquininha->contaFinanceira;

            $pagamento = $titulo->registrarPagamento(
                valor: $bruto,
                data: $data,
                forma: FormaPagamento::Transferencia,
                observacoes: 'Liquidação do recebível da adquirente.',
                contaFinanceiraId: $conta->id,
                foraDaSessao: true,
            );

            if ($valorLiquido !== null && abs($taxa - (float) $titulo->taxa_adquirente) >= 0.01) {
                $titulo->forceFill(['observacoes' => trim(($titulo->observacoes ?? '')."\nLíquido informado R$ ".number_format($valorLiquido, 2, ',', '.').' (taxa prevista R$ '.number_format((float) $titulo->taxa_adquirente, 2, ',', '.').').')])->saveQuietly();
            }

            if ($taxa > 0) {
                $this->lancarTaxa($titulo, $taxa, $data, $conta->id);
            }

            return $pagamento;
        });
    }

    /**
     * Liquida todo recebível pendente com vencimento até a data (idempotente:
     * título já liquidado deixa de ser pendente). Uma falha não impede os demais.
     *
     * @return array{liquidados: int, falhas: array<int, string>}
     */
    public function liquidarVencidos(?Carbon $ate = null): array
    {
        $ate ??= today();
        $liquidados = 0;
        $falhas = [];

        Lancamento::query()
            ->receber()
            ->whereNotNull('maquininha_id')
            ->where('status', StatusLancamento::Pendente)
            ->whereDate('vencimento', '<=', $ate->toDateString())
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get()
            ->each(function (Lancamento $titulo) use (&$liquidados, &$falhas): void {
                try {
                    $this->liquidar($titulo);
                    $liquidados++;
                } catch (FinanceiroException $e) {
                    $falhas[$titulo->id] = $e->getMessage();
                }
            });

        return ['liquidados' => $liquidados, 'falhas' => $falhas];
    }

    /**
     * Desfaz a liquidação: estorna a baixa do recebível e a despesa da taxa
     * (que fica Cancelada). Bloqueado se a baixa já foi conciliada.
     *
     * @throws FinanceiroException
     */
    public function estornarLiquidacao(Lancamento $titulo, string $motivo): void
    {
        DB::transaction(function () use ($titulo, $motivo): void {
            if ($titulo->pagamentos()->whereNotNull('conciliado_em')->exists()) {
                throw new FinanceiroException('Esta liquidação já foi conciliada com o extrato. Desmarque a conciliação antes de estornar.');
            }

            Lancamento::query()->where('recebivel_id', $titulo->id)->get()->each(function (Lancamento $taxa) use ($motivo): void {
                $taxa->estornarPagamento($motivo);
                $taxa->update(['status' => StatusLancamento::Cancelado]);
            });

            $titulo->estornarPagamento($motivo);
        });
    }

    /** Marca (ou desmarca) a baixa do recebível como conferida com o extrato da adquirente. */
    public function conciliar(Lancamento $titulo, bool $conciliado = true): void
    {
        $titulo->pagamentos()->update(['conciliado_em' => $conciliado ? now() : null]);
    }

    /** @throws FinanceiroException */
    private function garantirLiquidavel(Lancamento $titulo): void
    {
        if ($titulo->tipo !== TipoLancamento::Receber || $titulo->maquininha_id === null) {
            throw new FinanceiroException('Só recebíveis de maquininha podem ser liquidados.');
        }

        if ($titulo->status !== StatusLancamento::Pendente) {
            throw new FinanceiroException('Este recebível já foi liquidado ou cancelado.');
        }

        if ($titulo->maquininha?->contaFinanceira === null) {
            throw new FinanceiroException("A maquininha {$titulo->maquininha?->nome} está sem conta financeira.");
        }
    }

    /** @throws FinanceiroException */
    private function lancarTaxa(Lancamento $recebivel, float $taxa, Carbon $data, int $contaId): void
    {
        $planoId = Empresa::query()->value('empresa_plano_despesa_taxa_cartao_id')
            ?? throw new FinanceiroException('Configure o plano de despesa da taxa de cartão (Configurações › Certificado SEFAZ).');

        $despesa = Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'plano_despesa_id' => $planoId,
            'recebivel_id' => $recebivel->id,
            'descricao' => "Taxa {$recebivel->maquininha->nome} — repasse {$data->format('d/m/Y')} (recebível #{$recebivel->id})",
            'valor' => $taxa,
            'vencimento' => $data,
            'status' => StatusLancamento::Pendente,
        ]);

        $despesa->registrarPagamento(
            valor: $taxa,
            data: $data,
            forma: FormaPagamento::Transferencia,
            observacoes: 'Taxa da adquirente descontada na liquidação.',
            contaFinanceiraId: $contaId,
            foraDaSessao: true,
        );
    }
}
