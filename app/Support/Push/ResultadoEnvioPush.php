<?php

namespace App\Support\Push;

/**
 * Retorno de um envio ao serviço de push. 201 = aceito (não garante entrega);
 * 404/410 = inscrição morta; 429/5xx/timeout = vale tentar de novo.
 */
final readonly class ResultadoEnvioPush
{
    public function __construct(
        public bool $sucesso,
        public ?int $httpStatus = null,
        public ?string $erro = null,
    ) {}

    public static function enviado(int $httpStatus = 201): self
    {
        return new self(true, $httpStatus);
    }

    public static function falha(?int $httpStatus, ?string $erro): self
    {
        return new self(false, $httpStatus, $erro);
    }

    /** A inscrição não existe mais no serviço de push. */
    public function inscricaoExpirada(): bool
    {
        return in_array($this->httpStatus, [404, 410], true);
    }

    /** Falha transitória: timeout, 429 ou 5xx. */
    public function reenviavel(): bool
    {
        if ($this->sucesso || $this->inscricaoExpirada()) {
            return false;
        }

        return $this->httpStatus === null || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
