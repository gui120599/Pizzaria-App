<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O celular não pode pedir nesta mesa: sem identificação, conta encerrada,
 * conta mudou de mesa ou celular bloqueado pelo garçom. O $motivo deixa a
 * rota pública decidir a resposta (pedir identificação, tela de conta
 * encerrada, redirecionar para a mesa nova).
 */
class AcessoMesaNegadoException extends RuntimeException
{
    public const SEM_IDENTIFICACAO = 'sem_identificacao';

    public const CONTA_ENCERRADA = 'conta_encerrada';

    public const MESA_TROCADA = 'mesa_trocada';

    public const BLOQUEADO = 'bloqueado';

    public function __construct(
        public readonly string $motivo,
        string $mensagem,
        public readonly ?string $codigoMesaAtual = null,
    ) {
        parent::__construct($mensagem);
    }

    public static function semIdentificacao(): self
    {
        return new self(self::SEM_IDENTIFICACAO, 'Identifique-se para pedir nesta mesa.');
    }

    public static function contaEncerrada(): self
    {
        return new self(self::CONTA_ENCERRADA, 'A conta desta mesa foi encerrada. Obrigado!');
    }

    public static function mesaTrocada(?string $codigoMesaAtual): self
    {
        return new self(self::MESA_TROCADA, 'Sua conta foi transferida para outra mesa.', $codigoMesaAtual);
    }

    public static function bloqueado(): self
    {
        return new self(self::BLOQUEADO, 'Este celular não pode mais pedir nesta mesa. Chame o garçom.');
    }
}
