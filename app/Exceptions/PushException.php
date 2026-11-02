<?php

namespace App\Exceptions;

use RuntimeException;

/** Inscrição ou envio de push recusado (endpoint inválido, usuário sem acesso, limite de teste). */
class PushException extends RuntimeException {}
