<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\TipoPagamentoMaquininhaEnum;
use App\Models\LancamentoPagamento;
use App\Models\Maquininha;
use App\Models\MaquininhaTaxa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use Illuminate\Support\Collection;

/**
 * Taxa da adquirente (MDR) de um pagamento: maquininha do pagamento (ou a
 * padrão) × bandeira × tipo (débito/crédito/pix). A procura vai da bandeira
 * específica para a taxa sem bandeira daquele tipo. Forma que não passa por
 * maquininha (dinheiro etc.) tem taxa 0; cartão/pix sem taxa cadastrada fica
 * nulo (desconhecido). PIX CNPJ não tem maquininha: usa a tarifa bancária da
 * forma de pagamento (sem tarifa = 0).
 *
 * Vale para pagamento de venda e para recebimento de título a receber (fiado).
 */
class TaxaMaquininhaService
{
    /** @var Collection<int, MaquininhaTaxa>|null */
    private ?Collection $taxas = null;

    private ?Maquininha $padrao = null;

    private bool $padraoCarregada = false;

    private ?float $tarifaPixCnpj = null;

    /** Grava no pagamento (ainda não salvo) o percentual e o valor da taxa. */
    public function gravarRetrato(PagamentosVenda $pagamento): void
    {
        $taxa = $this->calcular($pagamento);

        $pagamento->pg_venda_taxa_maquininha_percentual = $taxa['percentual'];
        $pagamento->pg_venda_taxa_maquininha_valor = $taxa['valor'];
    }

    /** Grava no recebimento de título a receber (ainda não salvo) o percentual e o valor da taxa. */
    public function gravarRetratoRecebimento(LancamentoPagamento $recebimento): void
    {
        $taxa = $this->calcularRecebimento($recebimento);

        $recebimento->taxa_percentual = $taxa['percentual'];
        $recebimento->taxa_valor = $taxa['valor'];
    }

    /**
     * @return array{percentual: ?float, valor: ?float}
     */
    public function calcular(PagamentosVenda $pagamento): array
    {
        $opcao = $pagamento->opcaoPagamento ?? OpcoesPagamento::withTrashed()->find($pagamento->pg_venda_opcaopagamento_id);

        if ($opcao?->ehPixCnpj()) {
            return $this->aplicar((float) ($opcao->opcaopag_tarifa_percentual ?? 0), (float) $pagamento->pg_venda_valor_pagamento);
        }

        $tipo = $opcao?->tipoMaquininha();

        if ($tipo === null) {
            return ['percentual' => 0.0, 'valor' => 0.0];
        }

        return $this->aplicar(
            $this->percentual($this->resolverMaquininhaId($pagamento), $pagamento->pg_venda_cartao_id, $tipo),
            (float) $pagamento->pg_venda_valor_pagamento,
        );
    }

    /**
     * @return array{percentual: ?float, valor: ?float}
     */
    public function calcularRecebimento(LancamentoPagamento $recebimento): array
    {
        $forma = $recebimento->forma_pagamento;

        if ($forma === FormaPagamento::PixCnpj) {
            return $this->aplicar($this->tarifaPixCnpj(), (float) $recebimento->valor);
        }

        $tipo = $forma?->tipoMaquininha();

        if ($tipo === null) {
            return ['percentual' => 0.0, 'valor' => 0.0];
        }

        return $this->aplicar(
            $this->percentual($this->resolverMaquininhaIdRecebimento($recebimento), $recebimento->cartao_id, $tipo),
            (float) $recebimento->valor,
        );
    }

    /**
     * @return array{percentual: ?float, valor: ?float}
     */
    private function aplicar(?float $percentual, float $valor): array
    {
        if ($percentual === null) {
            return ['percentual' => null, 'valor' => null];
        }

        return ['percentual' => $percentual, 'valor' => round($valor * $percentual / 100, 2)];
    }

    /** Tarifa da forma PIX CNPJ cadastrada (a primeira, se houver mais de uma). */
    public function tarifaPixCnpj(): float
    {
        return $this->tarifaPixCnpj ??= (float) (OpcoesPagamento::query()
            ->where('opcaopag_desc_nfe', 'InstantPayment')
            ->where('opcaopag_pix_cnpj', true)
            ->orderBy('id')
            ->value('opcaopag_tarifa_percentual') ?? 0);
    }

    /** Maquininha do recebimento ou, se ele não informa, a padrão. */
    public function resolverMaquininhaIdRecebimento(LancamentoPagamento $recebimento): ?int
    {
        return $recebimento->maquininha_id ?: $this->padrao()?->id;
    }

    public function percentual(?int $maquininhaId, ?int $cartaoId, TipoPagamentoMaquininhaEnum $tipo): ?float
    {
        $taxa = $this->taxa($maquininhaId, $cartaoId, $tipo);

        return $taxa ? (float) $taxa->mt_percentual : null;
    }

    /** Taxa cadastrada da bandeira ou, sem ela, a taxa sem bandeira do tipo. */
    public function taxa(?int $maquininhaId, ?int $cartaoId, TipoPagamentoMaquininhaEnum $tipo): ?MaquininhaTaxa
    {
        if ($maquininhaId === null) {
            return null;
        }

        $daMaquininha = $this->taxas()->where('mt_maquininha_id', $maquininhaId)->where('mt_tipo', $tipo);

        return ($cartaoId ? $daMaquininha->firstWhere('mt_cartao_id', $cartaoId) : null)
            ?? $daMaquininha->first(fn (MaquininhaTaxa $taxa): bool => $taxa->mt_cartao_id === null);
    }

    /** Maquininha do pagamento ou, se ele não informa, a padrão. */
    public function resolverMaquininhaId(PagamentosVenda $pagamento): ?int
    {
        return $pagamento->pg_venda_maquininha_id ?: $this->padrao()?->id;
    }

    /** @return Collection<int, MaquininhaTaxa> */
    private function taxas(): Collection
    {
        return $this->taxas ??= MaquininhaTaxa::all();
    }

    private function padrao(): ?Maquininha
    {
        if (! $this->padraoCarregada) {
            $this->padrao = Maquininha::padrao();
            $this->padraoCarregada = true;
        }

        return $this->padrao;
    }
}
