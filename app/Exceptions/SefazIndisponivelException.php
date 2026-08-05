<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Falha de infraestrutura ao falar com a SEFAZ: timeout, SOAP fault de rede,
 * ou cStat indicando serviço paralisado/em contingência.
 */
class SefazIndisponivelException extends RuntimeException {}
