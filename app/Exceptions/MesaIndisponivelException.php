<?php

namespace App\Exceptions;

use RuntimeException;

/** A mesa/comanda já está ocupada por outra sessão ou está inativa. */
class MesaIndisponivelException extends RuntimeException {}
