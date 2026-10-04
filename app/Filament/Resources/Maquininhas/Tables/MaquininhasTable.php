<?php

namespace App\Filament\Resources\Maquininhas\Tables;

use App\Enums\OperadoraMaquininha;
use App\Models\Maquininha;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class MaquininhasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('operadora')
                    ->label('Operadora')
                    ->badge(),
                TextColumn::make('numero_serie')
                    ->label('Número de série')
                    ->searchable()
                    ->placeholder('—'),
                IconColumn::make('maquininha_padrao')
                    ->label('Padrão')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('operadora')
                    ->label('Operadora')
                    ->options(OperadoraMaquininha::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('copiarTaxas')
                        ->label('Copiar taxas de outra maquininha')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->authorize(fn (): bool => auth()->user()?->can('update:maquininha') ?? false)
                        ->modalDescription('As taxas (tipo, bandeira e percentual) da maquininha de origem são copiadas para as maquininhas selecionadas.')
                        ->schema([
                            Select::make('origem_id')
                                ->label('Copiar taxas de')
                                ->options(fn (): array => Maquininha::has('taxas')->orderBy('nome')->pluck('nome', 'id')->all())
                                ->required()
                                ->searchable(),
                            Toggle::make('substituir')
                                ->label('Substituir as taxas atuais')
                                ->helperText('Desligado, mantém as taxas já cadastradas e só acrescenta as que faltam.')
                                ->default(true),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $origem = Maquininha::findOrFail($data['origem_id']);
                            $destinos = $records->reject(fn (Maquininha $maquininha): bool => $maquininha->is($origem));

                            $copiadas = $destinos->sum(fn (Maquininha $maquininha): int => $maquininha->copiarTaxasDe($origem, (bool) $data['substituir']));

                            Notification::make()
                                ->title("Taxas de {$origem->nome} copiadas para {$destinos->count()} maquininha(s).")
                                ->body("{$copiadas} taxa(s) gravada(s).")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
