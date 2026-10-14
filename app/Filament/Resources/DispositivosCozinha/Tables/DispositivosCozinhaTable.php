<?php

namespace App\Filament\Resources\DispositivosCozinha\Tables;

use App\Filament\Resources\DispositivosCozinha\Support\ParearDispositivoAction;
use App\Models\DispositivoCozinha;
use App\Models\SetorProducao;
use App\Services\Cozinha\PareamentoDispositivoService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DispositivosCozinhaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('usuario:id,email'))
            ->poll('30s')
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (DispositivoCozinha $record): ?string => $record->usuario?->email),
                TextColumn::make('setores')
                    ->label('Setores')
                    ->badge()
                    ->state(fn (DispositivoCozinha $record): array => array_values(array_intersect_key(
                        self::nomesSetores(),
                        array_flip($record->setorIds()),
                    ))),
                TextColumn::make('situacao')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (DispositivoCozinha $record): string => self::situacao($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Online' => 'success',
                        'Offline' => 'danger',
                        'Revogado' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('ultimo_ping_em')
                    ->label('Último sinal')
                    ->since()
                    ->placeholder('Nunca')
                    ->description(fn (DispositivoCozinha $record): ?string => $record->ultimo_ip),
                TextColumn::make('modo_exibicao')
                    ->label('Modo')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('pareado_em')
                    ->label('Pareado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ParearDispositivoAction::make(),
                Action::make('revogar')
                    ->label('Revogar')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize('parear')
                    ->visible(fn (DispositivoCozinha $record): bool => $record->ativo)
                    ->requiresConfirmation()
                    ->modalDescription('O tablet sai da tela da cozinha na próxima atualização. Para voltar a usar, ative e pareie de novo.')
                    ->action(function (DispositivoCozinha $record): void {
                        app(PareamentoDispositivoService::class)->revogar($record);
                        Notification::make()->success()->title("{$record->nome} revogado")->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function situacao(DispositivoCozinha $dispositivo): string
    {
        return match (true) {
            ! $dispositivo->ativo => 'Revogado',
            $dispositivo->pareado_em === null => 'Não pareado',
            $dispositivo->estaOnline() => 'Online',
            default => 'Offline',
        };
    }

    /** @return array<int, string> */
    private static function nomesSetores(): array
    {
        return once(fn (): array => SetorProducao::withTrashed()->pluck('nome', 'id')->all());
    }
}
