<?php

namespace App\Services\Nfe;

use App\Exceptions\NfeIoException;
use App\Models\Empresa;
use App\Models\NfEmissao;
use App\Models\Venda;
use App\Services\NfeIoService;
use Illuminate\Support\Facades\Cache;

/**
 * Regra da NFC-e automática por forma de pagamento, compartilhada pelo PDV
 * (OperarVenda) e pela venda que o webhook Stone finaliza sozinho (conta de
 * mesa paga na maquininha, pedido pago na entrega).
 */
class EmissaoNfeAutomaticaService
{
    public function __construct(private NfeIoService $nfeIo) {}

    /**
     * A NFe.io está configurada e algum pagamento da venda usa forma com envio
     * automático? Não exige a permission emitir:nfe — é regra de negócio, não
     * ação do operador.
     */
    public function deveEmitir(Venda $venda): bool
    {
        return $this->motivoNaoEmitir($venda) === null;
    }

    /**
     * Por que a NFC-e automática não sai para esta venda; null quando deve sair.
     * Única fonte das condições de deveEmitir(), para o log explicar o silêncio.
     */
    public function motivoNaoEmitir(Venda $venda): ?string
    {
        return match (true) {
            ! Empresa::first()?->nfeIoConfigurado() => 'NFe.io não configurada na empresa',
            ! $venda->exigeEnvioNfe() => 'nenhum pagamento da venda usa forma com envio automático de NFC-e',
            ! $venda->temItemFiscal() => 'a venda não tem item fiscal (somente cortesia)',
            default => null,
        };
    }

    /**
     * Emite a NFC-e sem duplicar: o job pós-resposta e a rede de segurança do
     * stone:conciliar-pedidos podem chegar à mesma venda ao mesmo tempo. O lock
     * é de cache (não de transação) porque a chamada à NFe.io é HTTP e não pode
     * ficar dentro de transação de banco.
     *
     * @return bool false quando outro processo está emitindo ou a venda já tem nota
     *
     * @throws NfeIoException
     */
    public function emitir(Venda $venda): bool
    {
        $lock = Cache::lock("nfe-automatica:venda:{$venda->id}", 120);

        if (! $lock->get()) {
            return false;
        }

        try {
            if (filled(Venda::whereKey($venda->id)->value('venda_id_nfe'))) {
                return false;
            }

            $this->nfeIo->emitir($venda, NfEmissao::DECISAO_AUTOMATICA);

            return true;
        } finally {
            $lock->release();
        }
    }
}
