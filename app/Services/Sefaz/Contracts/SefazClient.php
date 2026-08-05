<?php

namespace App\Services\Sefaz\Contracts;

use App\Exceptions\SefazAutenticacaoException;
use App\Exceptions\SefazIndisponivelException;
use App\Services\Sefaz\Dto\SefazDocumentoCompleto;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;

/**
 * Abstração sobre o webservice NFe Distribuição DFe da SEFAZ — ponto de troca
 * por uma implementação fake nos testes (o SOAP real não é mockável de forma confiável).
 */
interface SefazClient
{
    /**
     * Modo consChNFe: devolve o XML completo se a nota já foi manifestada e
     * liberada, ou só um resumo caso contrário (chame manifestarCiencia() e
     * tente de novo).
     *
     * @throws SefazAutenticacaoException|SefazIndisponivelException
     */
    public function consultarPorChave(string $chave): SefazResumoDocumento|SefazDocumentoCompleto;

    /**
     * Modo distNSU: lote de até 50 resumos/eventos a partir do cursor informado.
     *
     * @throws SefazAutenticacaoException|SefazIndisponivelException
     */
    public function consultarPorNsu(int $ultNsu): SefazLoteDistribuicao;

    /**
     * Evento de Ciência da Operação (210210). Idempotente: se a SEFAZ já
     * registrou esse evento antes, não deve lançar erro.
     *
     * @throws SefazAutenticacaoException|SefazIndisponivelException
     */
    public function manifestarCiencia(string $chave): void;
}
