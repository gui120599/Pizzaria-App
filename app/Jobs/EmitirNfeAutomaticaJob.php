<?php

namespace App\Jobs;

use App\Exceptions\NfeIoException;
use App\Models\Venda;
use App\Services\Nfe\EmissaoNfeAutomaticaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Emite a NFC-e de uma venda finalizada fora do PDV (webhook Stone), quando a
 * forma de pagamento exige. Em fila porque o webhook precisa responder rápido
 * e a NFe.io pode demorar. Falha fica registrada em nf_emissoes
 * (FALHA_ENVIO) e no log — o reenvio é manual, pela tela da venda.
 */
class EmitirNfeAutomaticaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $vendaId) {}

    public function handle(EmissaoNfeAutomaticaService $emissao): void
    {
        $venda = Venda::find($this->vendaId);

        if (! $venda || $venda->venda_status !== 'FINALIZADA' || filled($venda->venda_id_nfe) || ! $emissao->deveEmitir($venda)) {
            return;
        }

        try {
            $emissao->emitir($venda);
        } catch (NfeIoException $e) {
            Log::error('NFC-e automática da venda finalizada pela Stone falhou', [
                'venda_id' => $venda->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
