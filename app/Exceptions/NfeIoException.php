<?php

namespace App\Exceptions;

use RuntimeException;

class NfeIoException extends RuntimeException
{
    public function __construct(string $message, private readonly ?array $respostaApi = null)
    {
        parent::__construct($message);
    }

    public function respostaApi(): ?array
    {
        return $this->respostaApi;
    }
}
