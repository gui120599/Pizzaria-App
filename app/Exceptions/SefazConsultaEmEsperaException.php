<?php

namespace App\Exceptions;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Consulta à SEFAZ não foi feita (ou foi recusada) por limite de uso do
 * NFeDistribuicaoDFe — só volta a ser permitida a partir de $liberadaEm.
 * Estende SefazIndisponivelException pra que os catch genéricos existentes
 * continuem tratando o caso; quem quiser parar um lote inteiro olha
 * $bloqueioGeral (CNPJ bloqueado) em vez de só uma chave no limite.
 */
class SefazConsultaEmEsperaException extends SefazIndisponivelException
{
    public function __construct(
        string $message,
        public readonly Carbon $liberadaEm,
        public readonly bool $bloqueioGeral,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function bloqueioSefaz(Carbon $liberadaEm, ?Throwable $previous = null): self
    {
        return new self(
            'A SEFAZ bloqueou temporariamente as consultas deste CNPJ por excesso de uso (Consumo Indevido). '
            .'Tente novamente a partir de '.self::horario($liberadaEm).' — qualquer tentativa antes disso reinicia o bloqueio.',
            $liberadaEm,
            bloqueioGeral: true,
            previous: $previous,
        );
    }

    public static function semNovidades(Carbon $liberadaEm): self
    {
        return new self(
            'A última consulta não trouxe notas novas, e a SEFAZ só permite buscar novidades de novo após 1 hora. '
            .'Tente a partir de '.self::horario($liberadaEm).'.',
            $liberadaEm,
            bloqueioGeral: false,
        );
    }

    public static function limiteDaChave(Carbon $liberadaEm): self
    {
        return new self(
            'Esta nota já foi consultada muitas vezes na última hora. Para não provocar o bloqueio da SEFAZ, '
            .'tente novamente a partir de '.self::horario($liberadaEm).'.',
            $liberadaEm,
            bloqueioGeral: false,
        );
    }

    private static function horario(Carbon $momento): string
    {
        return $momento->isToday() ? $momento->format('H:i') : $momento->format('d/m/Y H:i');
    }
}
