<?php

namespace App\Enums;

/** Confiança da classificação sugerida para a operação de uma NF-e de entrada. */
enum ConfiancaOperacaoEnum: string
{
    case ALTA = 'alta';
    case MEDIA = 'media';
    case BAIXA = 'baixa';
    case NAO_PROCESSAVEL = 'nao_processavel';

    public function label(): string
    {
        return match ($this) {
            self::ALTA => 'Alta',
            self::MEDIA => 'Média',
            self::BAIXA => 'Baixa',
            self::NAO_PROCESSAVEL => 'Não processável',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::ALTA => 'success',
            self::MEDIA => 'warning',
            self::BAIXA => 'danger',
            self::NAO_PROCESSAVEL => 'gray',
        };
    }

    /** Um nível abaixo (ALTA → MÉDIA → BAIXA); NAO_PROCESSAVEL não rebaixa. */
    public function rebaixar(): self
    {
        return match ($this) {
            self::ALTA => self::MEDIA,
            self::MEDIA, self::BAIXA => self::BAIXA,
            self::NAO_PROCESSAVEL => self::NAO_PROCESSAVEL,
        };
    }

    /** Ordem crescente de risco, para escolher a menor confiança entre itens. */
    public function peso(): int
    {
        return match ($this) {
            self::ALTA => 3,
            self::MEDIA => 2,
            self::BAIXA => 1,
            self::NAO_PROCESSAVEL => 0,
        };
    }
}
