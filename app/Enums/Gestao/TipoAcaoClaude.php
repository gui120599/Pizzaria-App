<?php

namespace App\Enums\Gestao;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/** Ação que o Claude prepara e o usuário confirma pelo conector de gestão (G3). */
enum TipoAcaoClaude: string implements HasIcon, HasLabel
{
    case Despesa = 'despesa';
    case Receita = 'receita';
    case BaixaTitulos = 'baixa_titulos';
    case PausaProduto = 'pausa_produto';
    case Promocao = 'promocao';
    case AprovarSugestoes = 'aprovar_sugestoes';
    case ContagemEstoque = 'contagem_estoque';

    public function getLabel(): string
    {
        return match ($this) {
            self::Despesa => 'Despesa avulsa',
            self::Receita => 'Receita avulsa',
            self::BaixaTitulos => 'Pagamento/recebimento de títulos',
            self::PausaProduto => 'Pausa de produto',
            self::Promocao => 'Promoção relâmpago',
            self::AprovarSugestoes => 'Aprovar sugestões do agente',
            self::ContagemEstoque => 'Contagem de estoque',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Despesa => 'heroicon-o-banknotes',
            self::Receita => 'heroicon-o-arrow-down-tray',
            self::BaixaTitulos => 'heroicon-o-check-circle',
            self::PausaProduto => 'heroicon-o-pause-circle',
            self::Promocao => 'heroicon-o-bolt',
            self::AprovarSugestoes => 'heroicon-o-check-badge',
            self::ContagemEstoque => 'heroicon-o-clipboard-document-check',
        };
    }
}
