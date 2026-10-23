<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * O destinatário da NF-e não é a empresa (CNPJ/CPF diferente do cadastro).
 * É uma ValidationException para que os pontos de importação existentes
 * já exibam a mensagem; o polling a distingue para ignorar a nota.
 */
class NfeDestinatarioDivergenteException extends ValidationException {}
