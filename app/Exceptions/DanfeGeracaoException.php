<?php

namespace App\Exceptions;

use RuntimeException;

/** O XML não é uma NF-e modelo 55 válida, ou a lib de geração do DANFE falhou. */
class DanfeGeracaoException extends RuntimeException {}
