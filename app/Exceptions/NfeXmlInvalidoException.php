<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O XML enviado não é uma NF-e válida para importação: mal formado, layout
 * não reconhecido, ou nota não autorizada (cancelada/denegada) pela SEFAZ.
 */
class NfeXmlInvalidoException extends RuntimeException {}
