<?php

namespace App\Services;

use App\Enums\AcaoAutorizadaEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Models\User;
use App\Models\Venda;
use App\Services\Garcom\AutorizacaoGerenteService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tirar/incluir a taxa de serviço das mesas direto na venda (caixa). Mesma
 * autorização do Painel do Garçom (permissão remover_taxa:sessao_mesa ou PIN
 * de gerente, com auditoria), mas o flag fica na venda: a sessão de mesa pode
 * já estar fechada e, na conta dividida, outras vendas da mesa seguem com taxa.
 */
class TaxaServicoVendaService
{
    public function __construct(
        private AutorizacaoGerenteService $autorizacoes,
        private VendaService $vendas,
    ) {}

    /**
     * @throws RuntimeException|AutorizacaoNegadaException
     */
    public function remover(
        Venda $venda,
        User $solicitante,
        ?int $autorizadorId,
        ?string $pin,
        ?string $motivo,
    ): Venda {
        return DB::transaction(function () use ($venda, $solicitante, $autorizadorId, $pin, $motivo) {
            $venda = Venda::lockForUpdate()->findOrFail($venda->id);
            $this->garantirVendaAberta($venda);

            if ($venda->venda_taxa_servico_removida) {
                return $venda;
            }

            $taxaAnterior = (float) $venda->venda_valor_taxa_servico;
            $totalSemTaxa = round((float) $venda->venda_valor_total - $taxaAnterior, 2);

            if (round((float) $venda->venda_valor_pago, 2) > $totalSemTaxa) {
                throw new RuntimeException('O valor já pago passa do total sem a taxa. Estorne o pagamento antes de tirar a taxa.');
            }

            $this->autorizacoes->autorizar(
                AcaoAutorizadaEnum::REMOVER_TAXA_SERVICO,
                $solicitante,
                $venda,
                $autorizadorId,
                $pin,
                $motivo,
                ['valor_anterior' => $taxaAnterior],
            );

            $venda->update(['venda_taxa_servico_removida' => true]);

            return $this->vendas->atualizarValoresdaVenda($venda->id);
        });
    }

    /** Volta a cobrar a taxa das mesas — não precisa de autorização. */
    public function restaurar(Venda $venda): Venda
    {
        return DB::transaction(function () use ($venda) {
            $venda = Venda::lockForUpdate()->findOrFail($venda->id);
            $this->garantirVendaAberta($venda);

            $venda->update(['venda_taxa_servico_removida' => false]);

            return $this->vendas->atualizarValoresdaVenda($venda->id);
        });
    }

    private function garantirVendaAberta(Venda $venda): void
    {
        if ($venda->venda_status !== 'INICIADA') {
            throw new RuntimeException('A taxa de serviço só pode ser alterada em venda aberta.');
        }
    }
}
