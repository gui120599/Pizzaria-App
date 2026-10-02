<?php

namespace App\Exceptions;

use RuntimeException;

/** PIN de gerente ausente, incorreto, bloqueado ou de usuário sem a permissão. */
class AutorizacaoNegadaException extends RuntimeException {}
