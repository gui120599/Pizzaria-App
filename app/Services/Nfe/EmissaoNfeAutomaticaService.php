<?php

namespace App\Services\Nfe;

use App\Exceptions\NfeIoException;
use App\Models\Empresa;
use App\Models\NfEmissao;
use App\Models\Venda;
use App\Services\NfeIoService;

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
        return (bool) Empresa::first()?->nfeIoConfigurado() && $venda->exigeEnvioNfe();
    }

    /**
     * @throws NfeIoException
     */
    public function emitir(Venda $venda): void
    {
        $this->nfeIo->emitir($venda, NfEmissao::DECISAO_AUTOMATICA);
    }
}
