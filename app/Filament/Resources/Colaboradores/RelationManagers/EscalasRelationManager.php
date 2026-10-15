<?php

namespace App\Filament\Resources\Colaboradores\RelationManagers;

use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\ColaboradorEscala;
use App\Models\Escala;
use App\Services\Ponto\CadastroColaboradorService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/** Vigências de escala do colaborador; a nova encerra a anterior na véspera. */
class EscalasRelationManager extends RelationManager
{
    protected static string $relationship = 'escalas';

    protected static ?string $title = 'Escalas';

    protected static ?string $modelLabel = 'escala';

    protected static ?string $pluralModelLabel = 'escalas';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('escala_id')
                ->label('Escala')
                ->options(fn (): array => Escala::query()->orderBy('nome')->pluck('nome', 'id')->all())
                ->searchable()
                ->required(),
            DatePicker::make('vigente_desde')
                ->label('Vigente desde')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->default(today())
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('escala'))
            ->defaultSort('vigente_desde', 'desc')
            ->columns([
                TextColumn::make('escala.nome')->label('Escala'),
                TextColumn::make('escala.tipo')->label('Tipo')->badge(),
                TextColumn::make('vigente_desde')->label('Desde')->date('d/m/Y'),
                TextColumn::make('vigente_ate')->label('Até')->date('d/m/Y')->placeholder('Em vigor'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nova vigência')
                    ->modalHeading('Nova escala')
                    ->modalDescription('A escala em aberto é encerrada na véspera da nova.')
                    ->using(function (array $data, CreateAction $action): ColaboradorEscala {
                        /** @var Colaborador $colaborador */
                        $colaborador = $this->getOwnerRecord();

                        try {
                            return app(CadastroColaboradorService::class)->novaEscala(
                                $colaborador,
                                Escala::query()->findOrFail($data['escala_id']),
                                Carbon::parse($data['vigente_desde']),
                            );
                        } catch (PontoException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}
