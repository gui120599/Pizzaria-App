<?php

namespace App\Enums\Checklist;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum TipoResposta: string implements HasIcon, HasLabel
{
    case Check = 'check';
    case SimNao = 'sim_nao';
    case Numero = 'numero';
    case Temperatura = 'temperatura';
    case Texto = 'texto';
    case Selecao = 'selecao';
    case Foto = 'foto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Check => 'Feito / não feito',
            self::SimNao => 'Sim / não',
            self::Numero => 'Número',
            self::Temperatura => 'Temperatura',
            self::Texto => 'Texto livre',
            self::Selecao => 'Seleção',
            self::Foto => 'Foto',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Check => Heroicon::OutlinedCheckCircle,
            self::SimNao => Heroicon::OutlinedHandThumbUp,
            self::Numero => Heroicon::OutlinedHashtag,
            self::Temperatura => Heroicon::OutlinedBeaker,
            self::Texto => Heroicon::OutlinedPencilSquare,
            self::Selecao => Heroicon::OutlinedListBullet,
            self::Foto => Heroicon::OutlinedCamera,
        };
    }

    /**
     * Coluna de checklist_execucao_itens onde a resposta deste tipo é gravada
     * (null = a resposta é o próprio anexo).
     */
    public function colunaValor(): ?string
    {
        return match ($this) {
            self::Check, self::SimNao => 'valor_booleano',
            self::Numero, self::Temperatura => 'valor_numero',
            self::Texto => 'valor_texto',
            self::Selecao => 'valor_opcao',
            self::Foto => null,
        };
    }

    public function usaFaixa(): bool
    {
        return in_array($this, [self::Numero, self::Temperatura], true);
    }
}
