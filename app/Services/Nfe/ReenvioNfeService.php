<?php

namespace App\Services\Nfe;

use App\Exceptions\NfeIoException;
use App\Models\Empresa;
use App\Models\NfEmissao;
use App\Models\Venda;
use App\Services\NfeIoService;

/**
 * Envio/reenvio manual da NFC-e de uma venda finalizada (tabela de vendas):
 * cobre a venda cujo envio no ato falhou, nunca foi tentado ou teve a nota
 * cancelada. O número reservado é reaproveitado quando a nota nunca foi
 * autorizada (NfNumeracaoService::reservarPara).
 */
class ReenvioNfeService
{
    /** Status da venda em que a nota está viva ou em andamento: não dá para enviar outra. */
    private const STATUS_QUE_BLOQUEIAM = ['Issued', 'Processing', 'Created', 'CancelamentoSolicitado', 'CancelFailed'];

    public function __construct(private readonly NfeIoService $nfe) {}

    public function motivoBloqueio(Venda $venda): ?string
    {
        if ($venda->venda_status !== 'FINALIZADA') {
            return 'Só vendas finalizadas geram NFC-e.';
        }

        if (! Empresa::first()?->nfeIoConfigurado()) {
            return 'A NFe.io não está configurada.';
        }

        if (! $venda->temItemFiscal()) {
            return 'Venda só de cortesia não gera NFC-e.';
        }

        $status = $venda->venda_status_nfe;

        if (in_array($status, self::STATUS_QUE_BLOQUEIAM, true) || $venda->nfEmissaoAtual?->status === NfEmissao::STATUS_AUTORIZADA) {
            return $status === 'Issued' || $venda->nfEmissaoAtual?->status === NfEmissao::STATUS_AUTORIZADA
                ? 'A NFC-e desta venda já está autorizada.'
                : "A NFC-e está em andamento ({$status}). Use \"Atualizar status\".";
        }

        return null;
    }

    /** A venda tem nota na NFe.io cujo resultado ainda não foi confirmado aqui? */
    public function podeAtualizarStatus(Venda $venda): bool
    {
        return filled($venda->venda_id_nfe) && ! in_array($venda->venda_status_nfe, ['Issued', 'Cancelled'], true);
    }

    /**
     * Envia a NFC-e e tenta já trazer o status. Devolve o status resultante.
     *
     * @throws NfeIoException
     */
    public function reenviar(Venda $venda): string
    {
        if ($motivo = $this->motivoBloqueio($venda)) {
            throw new NfeIoException($motivo);
        }

        $this->nfe->emitir($venda, NfEmissao::DECISAO_MANUAL);

        $venda = $venda->fresh();

        // A emissão é assíncrona: a consulta imediata só adianta o resultado e não desfaz o envio se falhar.
        try {
            return $this->nfe->sincronizarStatusLocal($venda);
        } catch (NfeIoException) {
            return $venda->venda_status_nfe ?? 'Processing';
        }
    }

    /**
     * @throws NfeIoException
     */
    public function atualizarStatus(Venda $venda): string
    {
        return $this->nfe->sincronizarStatusLocal($venda);
    }
}
