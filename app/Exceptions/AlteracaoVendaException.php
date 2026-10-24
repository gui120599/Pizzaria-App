<?php

namespace App\Exceptions;

use RuntimeException;

/** Alteração completa de venda finalizada não permitida (ver AlteracaoVendaService). */
class AlteracaoVendaException extends RuntimeException {}
