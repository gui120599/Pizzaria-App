<?php

namespace App\Filament\Resources\ChecklistExecucoes\Schemas;

use App\Models\ChecklistExecucao;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChecklistExecucaoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resumo')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge(),
                    TextEntry::make('data_referencia')
                        ->label('Dia operacional')
                        ->date('d/m/Y'),
                    TextEntry::make('tipo')
                        ->label('Tipo')
                        ->badge(),
                    TextEntry::make('setor')
                        ->label('Setor')
                        ->badge(),
                    TextEntry::make('iniciadoPor.name')
                        ->label('Iniciado por')
                        ->placeholder('—')
                        ->helperText(fn (ChecklistExecucao $record): ?string => $record->iniciado_em?->format('d/m/Y H:i')),
                    TextEntry::make('concluidoPor.name')
                        ->label('Concluído por')
                        ->placeholder('—')
                        ->helperText(fn (ChecklistExecucao $record): ?string => $record->concluido_em?->format('d/m/Y H:i')),
                    TextEntry::make('aprovadoPor.name')
                        ->label('Revisado por')
                        ->placeholder('—')
                        ->helperText(fn (ChecklistExecucao $record): ?string => $record->aprovado_em?->format('d/m/Y H:i')),
                    TextEntry::make('percentual_conformidade')
                        ->label('Conformidade')
                        ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : number_format((float) $state, 0, ',', '.').'%')
                        ->placeholder('—')
                        ->helperText(fn (ChecklistExecucao $record): string => "{$record->itens_respondidos} de {$record->total_itens} respondidos · {$record->itens_nao_conformes} não conforme(s)"),
                    TextEntry::make('motivo_reprovacao')
                        ->label('Motivo da reprovação')
                        ->color('danger')
                        ->visible(fn (ChecklistExecucao $record): bool => filled($record->motivo_reprovacao))
                        ->columnSpanFull(),
                    TextEntry::make('observacao_geral')
                        ->label('Observação geral')
                        ->visible(fn (ChecklistExecucao $record): bool => filled($record->observacao_geral))
                        ->columnSpanFull(),
                    TextEntry::make('modelo_versao')
                        ->label('Versão do modelo')
                        ->prefix('v'),
                ]),
            Section::make('Itens')
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('itens')
                        ->hiddenLabel()
                        ->view('filament.checklists.execucao-itens'),
                ]),
        ]);
    }
}
