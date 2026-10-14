<?php

namespace App\Filament\Resources\FichasTecnicas\Support;

use App\Services\Cozinha\RelatorioProducaoService;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "Produtos sem ficha" (spec §7.1): os produtos produzidos vendidos nos
 * últimos 30 dias que ainda não têm ficha de preparo, do mais vendido para o
 * menos — por onde começar o cadastro.
 */
class ProdutosSemFichaAction
{
    public static function make(): Action
    {
        return Action::make('produtosSemFicha')
            ->label('Produtos sem ficha')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('gray')
            ->modalHeading('Produtos vendidos sem ficha de preparo (30 dias)')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn () => view('filament.cozinha.produtos-sem-ficha', [
                'linhas' => app(RelatorioProducaoService::class)->coberturaFichas(now()->subDays(30)->startOfDay(), now())['sem_ficha'],
            ]));
    }
}
