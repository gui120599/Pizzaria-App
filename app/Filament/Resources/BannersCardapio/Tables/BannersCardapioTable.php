<?php

namespace App\Filament\Resources\BannersCardapio\Tables;

use App\Models\BannerCardapio;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class BannersCardapioTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // A ordem da tabela é a do carrossel; "Ordenar" permite arrastar.
            ->defaultSort('banner_ordem')
            ->reorderable('banner_ordem')
            ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering): Action => $action->label($isReordering ? 'Concluir ordem' : 'Ordenar'))
            ->emptyStateHeading('Nenhum banner cadastrado')
            ->emptyStateDescription('Sem banner no ar, o topo do cardápio mostra só o nome e as informações da loja.')
            ->columns([
                ImageColumn::make('banner_imagem')
                    ->label('')
                    ->disk('public')
                    ->imageHeight(48)
                    ->extraImgAttributes(['class' => 'rounded-lg', 'loading' => 'lazy']),

                TextColumn::make('banner_titulo')
                    ->label('Banner')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (BannerCardapio $record): string => $record->banner_acao->getLabel()
                        .match (true) {
                            $record->banner_exibir_delivery && $record->banner_exibir_mesa => ' · delivery e mesa',
                            $record->banner_exibir_delivery => ' · só delivery',
                            $record->banner_exibir_mesa => ' · só mesa',
                            default => ' · não aparece em nenhum cardápio',
                        }),

                TextColumn::make('situacao')
                    ->label('Situação')
                    ->state(fn (BannerCardapio $record): string => $record->situacao())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'No ar' => 'success',
                        'Agendado', 'Fora do horário' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('vigencia')
                    ->label('Quando')
                    ->state(function (BannerCardapio $record): string {
                        if (! $record->banner_recorrente) {
                            return match (true) {
                                $record->banner_inicio && $record->banner_fim => $record->banner_inicio->format('d/m/Y H:i').' até '.$record->banner_fim->format('d/m/Y H:i'),
                                (bool) $record->banner_fim => 'Até '.$record->banner_fim->format('d/m/Y H:i'),
                                (bool) $record->banner_inicio => 'A partir de '.$record->banner_inicio->format('d/m/Y H:i'),
                                default => 'Sempre',
                            };
                        }

                        $nomesDias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                        $dias = $record->banner_dias_semana;
                        $diasLabel = empty($dias)
                            ? 'todo dia'
                            : collect($dias)->sort()->map(fn ($d) => $nomesDias[(int) $d])->implode('/');

                        $horario = $record->banner_hora_inicio && $record->banner_hora_fim
                            ? $record->banner_hora_inicio->format('H:i').'–'.$record->banner_hora_fim->format('H:i')
                            : 'dia inteiro';

                        return $diasLabel.', '.$horario;
                    })
                    ->wrap(),

                ToggleColumn::make('banner_ativo')
                    ->label('Ligado'),
            ])
            ->filters([
                TrashedFilter::make()->label('Excluídos'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
