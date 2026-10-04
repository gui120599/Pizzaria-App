<?php

namespace App\Services;

use App\Enums\TipoPagamentoMaquininhaEnum;
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
 * nulo (desconhecido).
 */
class TaxaMaquininhaService
{
    /** @var Collection<int, MaquininhaTaxa>|null */
    private ?Collection $taxas = null;

    private ?Maquininha $padrao = null;

    private bool $padraoCarregada = false;

    /** Grava no pagamento (ainda não salvo) o percentual e o valor da taxa. */
    public function gravarRetrato(PagamentosVenda $pagamento): void
    {
        $taxa = $this->calcular($pagamento);

        $pagamento->pg_venda_taxa_maquininha_percentual = $taxa['percentual'];
        $pagamento->pg_venda_taxa_maquininha_valor = $taxa['valor'];
    }

    /**
     * @return array{percentual: ?float, valor: ?float}
     */
    public function calcular(PagamentosVenda $pagamento): array
    {
        $opcao = $pagamento->opcaoPagamento ?? OpcoesPagamento::find($pagamento->pg_venda_opcaopagamento_id);
        $tipo = $opcao?->tipoMaquininha();

        if ($tipo === null) {
            return ['percentual' => 0.0, 'valor' => 0.0];
        }

        $percentual = $this->percentual(
            $pagamento->pg_venda_maquininha_id ?: $this->padrao()?->id,
            $pagamento->pg_venda_cartao_id,
            $tipo,
        );

        if ($percentual === null) {
            return ['percentual' => null, 'valor' => null];
        }

        return [
            'percentual' => $percentual,
            'valor' => round((float) $pagamento->pg_venda_valor_pagamento * $percentual / 100, 2),
        ];
    }

    public function percentual(?int $maquininhaId, ?int $cartaoId, TipoPagamentoMaquininhaEnum $tipo): ?float
    {
        if ($maquininhaId === null) {
            return null;
        }

        $daMaquininha = $this->taxas()->where('mt_maquininha_id', $maquininhaId)->where('mt_tipo', $tipo);

        $taxa = ($cartaoId ? $daMaquininha->firstWhere('mt_cartao_id', $cartaoId) : null)
            ?? $daMaquininha->first(fn (MaquininhaTaxa $taxa): bool => $taxa->mt_cartao_id === null);

        return $taxa ? (float) $taxa->mt_percentual : null;
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
