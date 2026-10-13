<?php

namespace App\Exceptions;

use RuntimeException;

/** Operação de checklist inválida para o estado atual (mensagem pronta para o usuário). */
class ChecklistException extends RuntimeException {}
