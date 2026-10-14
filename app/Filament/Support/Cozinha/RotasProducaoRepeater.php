<?php

namespace App\Filament\Support\Cozinha;

use App\Models\Categoria;
use App\Models\Produto;
use App\Models\SetorProducao;
use App\Services\Cozinha\RotasProducaoService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;

/**
 * Rota de produção (setor de cada etapa) editada no form do produto e no
 * modal da categoria. A ordem dos itens é a etapa. Não usa ->relationship():
 * o save do Filament atualizaria uma linha por vez e colidiria com o unique
 * (dono, etapa) ao trocar duas etapas de lugar — quem grava é o
 * RotasProducaoService.
 */
class RotasProducaoRepeater
{
    public static function make(string $ajuda): Repeater
    {
        return Repeater::make('rotas_producao')
            ->label('Rota de produção')
            ->helperText($ajuda)
            ->dehydrated(false)
            ->loadStateFromRelationshipsUsing(fn (Repeater $component, ?Model $record) => $component->state(
                $record instanceof Produto || $record instanceof Categoria
                    ? app(RotasProducaoService::class)->paraFormulario($record)
                    : [],
            ))
            ->saveRelationshipsUsing(function (Model $record, ?array $state): void {
                if ($record instanceof Produto || $record instanceof Categoria) {
                    app(RotasProducaoService::class)->sincronizar($record, array_values($state ?? []));
                }
            })
            ->schema([
                Hidden::make('id'),
                Select::make('setor_producao_id')
                    ->label('Setor')
                    ->options(fn (): array => SetorProducao::ativos()->ordenados()->pluck('nome', 'id')->all())
                    ->native(false)
                    ->required(),
                TextInput::make('tempo_previsto_min')
                    ->label('Tempo previsto')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(240)
                    ->suffix('min')
                    ->placeholder('Do setor'),
                Toggle::make('ativo')
                    ->label('Ativa')
                    ->default(true)
                    ->inline(false),
            ])
            ->columns(3)
            ->defaultItems(0)
            ->reorderable()
            ->reorderableWithButtons()
            ->addActionLabel('Adicionar etapa')
            ->itemLabel(fn (array $state): string => once(fn (): array => SetorProducao::withTrashed()->pluck('nome', 'id')->all())[$state['setor_producao_id'] ?? 0] ?? 'Nova etapa')
            ->columnSpanFull();
    }
}
