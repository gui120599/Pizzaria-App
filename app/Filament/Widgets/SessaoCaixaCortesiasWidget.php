<?php

namespace App\Filament\Widgets;

use App\Services\RelatorioSessaoCaixaService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Cortesias das vendas do recorte: saíram do estoque sem cobrança e ficaram
 * fora da NFC-e, então não entram no vendido nem no esperado do caixa.
 */
class SessaoCaixaCortesiasWidget extends BaseWidget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Cortesias')
            ->description('Itens dados sem cobrança nas vendas do recorte, a preço de tabela. Não entram no vendido, no recebido nem na NFC-e; o estoque baixa normalmente.')
            ->records(fn (): array => (new RelatorioSessaoCaixaService($this->pageFilters ?? []))->cortesias()->keyBy('key')->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhuma cortesia no recorte')
            ->columns([
                TextColumn::make('finalizada_em')->label('Data')->dateTime('d/m H:i'),
                TextColumn::make('venda_id')->label('Venda')->prefix('#'),
                TextColumn::make('produto')->label('Produto'),
                TextColumn::make('quantidade')->label('Qtd.')->numeric(decimalPlaces: 0, thousandsSeparator: '.'),
                TextColumn::make('valor_tabela')->label('Tabela')->money('BRL')->weight('bold'),
                TextColumn::make('custo')->label('Custo')->money('BRL'),
                TextColumn::make('motivo')->label('Motivo')->placeholder('—'),
                TextColumn::make('autorizador')->label('Autorizou')->placeholder('—'),
            ]);
    }
}
