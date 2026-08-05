<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A nota foi manifestada (Ciência da Operação), mas a SEFAZ ainda não
 * propagou o XML completo — o solicitante precisa tentar de novo mais tarde.
 */
class SefazDocumentoAindaNaoDisponivelException extends RuntimeException {}
