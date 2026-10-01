<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Combinação de sabores recusada pelo servidor: sabores repetidos, de
 * categorias diferentes, categoria sem sabores ou quantidade de sabores sem
 * opção cadastrada na categoria (ver PrecificadorService::opcaoDoCombo()).
 */
class ComboSaboresInvalidoException extends RuntimeException {}
