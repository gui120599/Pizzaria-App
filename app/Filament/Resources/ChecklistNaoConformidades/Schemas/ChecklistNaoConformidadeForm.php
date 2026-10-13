<?php

namespace App\Filament\Resources\ChecklistNaoConformidades\Schemas;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Filament\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Models\ChecklistNaoConformidade;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ChecklistNaoConformidadeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ocorrência')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('execucaoItem.execucao.modelo_nome')
                        ->label('Checklist')
                        ->url(fn (ChecklistNaoConformidade $record): string => ChecklistExecucaoResource::getUrl('view', ['record' => $record->execucaoItem->checklist_execucao_id])),
                    TextEntry::make('execucaoItem.execucao.data_referencia')
                        ->label('Dia')
                        ->date('d/m/Y'),
                    TextEntry::make('critica')
                        ->label('Crítica')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Não')
                        ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
                    TextEntry::make('descricao')
                        ->label('Descrição')
                        ->columnSpanFull(),
                ]),
            Section::make('Plano de ação')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Select::make('responsavel_id')
                        ->label('Responsável')
                        ->relationship('responsavel', 'name', fn (Builder $query): Builder => $query
                            ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', '!=', 'Cliente')))
                        ->searchable()
                        ->preload(),
                    DatePicker::make('prazo')
                        ->label('Prazo'),
                    Select::make('status')
                        ->label('Status')
                        ->options(NaoConformidadeStatus::class)
                        ->required()
                        ->live(),
                    Textarea::make('acao_corretiva')
                        ->label('Ação corretiva')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                    Textarea::make('observacao_resolucao')
                        ->label('Como foi resolvida')
                        ->rows(3)
                        ->maxLength(5000)
                        ->required(fn (Get $get): bool => self::status($get('status')) === NaoConformidadeStatus::Resolvida)
                        ->visible(fn (Get $get): bool => ! in_array(self::status($get('status')), NaoConformidadeStatus::pendentes(), true))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function status(mixed $estado): ?NaoConformidadeStatus
    {
        return $estado instanceof NaoConformidadeStatus ? $estado : NaoConformidadeStatus::tryFrom((string) $estado);
    }
}
