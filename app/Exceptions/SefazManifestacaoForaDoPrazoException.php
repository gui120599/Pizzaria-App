<?php

namespace App\Exceptions;

/**
 * SEFAZ rejeitou o evento de manifestação por prazo (cStat 596). A Ciência
 * da Operação só é aceita até 10 dias da autorização da NF-e; depois disso
 * o XML só é liberado pela Confirmação da Operação (até 90 dias). Estende
 * SefazIndisponivelException pra que os catch genéricos existentes
 * (polling, comandos, lote) continuem tratando o caso sem mudança.
 */
class SefazManifestacaoForaDoPrazoException extends SefazIndisponivelException {}
