<?php

namespace App\Services\Compras;

use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\CompraStatusEnum;
use App\Enums\SefazNotaRecebidaStatusEnum;
use App\Enums\StatusLancamento;
use App\Models\Compra;
use App\Models\Lancamento;
use App\Models\SefazNotaRecebida;
use Illuminate\Support\Facades\DB;

/**
 * Evento de cancelamento da NF-e (110111) recebido pela Distribuição DF-e
 * (regra 15): compra ainda em rascunho, sem nenhum pagamento, é cancelada
 * junto com os títulos. Compra confirmada ou com título pago vira pendência
 * prioritária — nada é estornado sozinho.
 */
class CancelamentoNfeService
{
    public const CANCELADA = 'cancelada';

    public const PENDENCIA = 'pendencia';

    public const SEM_COMPRA = 'sem_compra';

    public const JA_CANCELADA = 'ja_cancelada';

    public function __construct(private PendenciaService $pendencias) {}

    public function processar(string $chave): string
    {
        $compra = Compra::query()->where('compra_chave_nfe', $chave)->first();

        if ($compra === null) {
            SefazNotaRecebida::query()
                ->where('snr_chave_acesso', $chave)
                ->where('snr_status', SefazNotaRecebidaStatusEnum::PENDENTE)
                ->update(['snr_status' => SefazNotaRecebidaStatusEnum::IGNORADA]);

            return self::SEM_COMPRA;
        }

        if (in_array($compra->compra_status, [CompraStatusEnum::CANCELADA, CompraStatusEnum::SEM_EFEITO], true)) {
            return self::JA_CANCELADA;
        }

        $titulos = $compra->lancamentos()->where('status', '!=', StatusLancamento::Cancelado)->get();
        $temPagamento = $titulos->contains(fn (Lancamento $titulo): bool => $titulo->pagamentos()->exists());

        if ($compra->compra_status !== CompraStatusEnum::RASCUNHO || $temPagamento) {
            $this->pendencias->abrir(TipoPendenciaCompra::NfeCancelada, $compra, null, [
                'status_compra' => $compra->compra_status->value,
                'titulos_com_pagamento' => $titulos->filter(fn (Lancamento $titulo): bool => $titulo->pagamentos()->exists())->pluck('id')->values()->all(),
                'motivo' => 'O emitente cancelou a NF-e. A compra já deu entrada ou tem pagamento: estorne manualmente.',
            ]);

            return self::PENDENCIA;
        }

        DB::transaction(function () use ($compra, $titulos): void {
            foreach ($titulos as $titulo) {
                $titulo->forceFill(['status' => StatusLancamento::Cancelado])->save();
            }

            $compra->forceFill([
                'compra_status' => CompraStatusEnum::CANCELADA,
                'compra_observacao' => trim(($compra->compra_observacao ?? '')."\nNF-e cancelada pelo emitente (evento 110111), registrada em ".now()->format('d/m/Y H:i').'.'),
            ])->save();

            $this->pendencias->descartarDaCompra($compra, 'NF-e cancelada pelo emitente.');
        });

        return self::CANCELADA;
    }
}
