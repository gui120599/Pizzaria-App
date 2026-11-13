<?php

namespace App\Services\Compras;

use App\Enums\CompraStatusEnum;
use App\Enums\ConfiancaOperacaoEnum;
use App\Enums\OperacaoEntradaEnum;
use App\Models\Compra;
use App\Models\CompraPendencia;
use App\Services\CompraService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Entrada automática no estoque de uma compra importada pela automação
 * (decisão Q2 de 2026-11-13). Confirma sozinho só quando:
 * - o fornecedor tem "Entrada automática" ligada;
 * - a data (emissão + N dias do fornecedor) já chegou;
 * - todo item tem produto e não há pendência que bloqueie;
 * - a operação é COMPRA com confiança ALTA.
 *
 * Fora disso a compra fica em rascunho para o "Confirmar" manual. A
 * confirmação é a mesma do fluxo manual (CompraService::confirmar).
 */
class ConferenciaCompraService
{
    public function __construct(private CompraService $compras) {}

    public function tentarConferir(Compra $compra, ?Carbon $hoje = null): bool
    {
        $compra = $compra->fresh(['itens', 'prestador.condicaoCompra']);
        $data = $compra === null ? null : $this->dataDeEntrada($compra);

        if ($data === null || $data->gt(($hoje ?? today())->copy()->startOfDay()) || ! $this->podeConferir($compra)) {
            return false;
        }

        try {
            $this->compras->confirmar($compra, $data);
        } catch (ValidationException $e) {
            Log::warning("Compras: entrada automática da compra #{$compra->id} recusada — ".collect($e->errors())->flatten()->first());

            return false;
        }

        return true;
    }

    /** Data em que a compra entra no estoque sozinha, ou null se não entra. */
    public function dataDeEntrada(Compra $compra): ?Carbon
    {
        if (! $compra->compra_importacao_automatica || $compra->compra_status !== CompraStatusEnum::RASCUNHO) {
            return null;
        }

        return $compra->prestador?->condicaoCompra?->dataEntradaAutomatica($compra->compra_data_emissao);
    }

    private function podeConferir(Compra $compra): bool
    {
        if ($compra->compra_operacao !== OperacaoEntradaEnum::COMPRA->value
            || $compra->compra_operacao_confianca !== ConfiancaOperacaoEnum::ALTA) {
            return false;
        }

        if ($compra->itens->isEmpty() || $compra->itens->contains(fn ($item): bool => $item->ci_produto_id === null)) {
            return false;
        }

        return ! $compra->pendencias()->emAberto()->get()
            ->contains(fn (CompraPendencia $pendencia): bool => $pendencia->tipo->bloqueiaConferencia());
    }
}
