<?php

namespace App\Exceptions;

/**
 * A SEFAZ respondeu cStat 137 (lote vazio) para consChNFe — cenário típico
 * de nota ainda não manifestada por este CNPJ, distinto de uma falha de
 * infraestrutura genérica. Estende SefazIndisponivelException pra manter
 * compatível quem já captura o tipo pai.
 */
class SefazDocumentoNaoLocalizadoException extends SefazIndisponivelException {}
