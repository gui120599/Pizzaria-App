<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Certificado digital inválido: senha errada, arquivo corrompido/não é um
 * .pfx válido, ou certificado vencido.
 */
class SefazAutenticacaoException extends RuntimeException {}
