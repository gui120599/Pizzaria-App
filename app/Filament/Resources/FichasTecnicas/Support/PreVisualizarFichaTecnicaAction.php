<?php

namespace App\Filament\Resources\FichasTecnicas\Support;

use App\Models\FichaTecnica;
use App\Models\Produto;
use App\Models\SetorProducao;
use App\Services\Cozinha\FichaTecnicaMontador;
use App\Support\Cozinha\FichaTecnicaView;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * "Pré-visualizar no tablet" (spec §7.1): a ficha montada pelo mesmo
 * FichaTecnicaMontador do tablet, escolhendo o setor, um segundo sabor para
 * ver o meio a meio e um "SEM …" de exemplo.
 */
class PreVisualizarFichaTecnicaAction
{
    public static function make(): Action
    {
        return Action::make('preVisualizar')
            ->label('Pré-visualizar no tablet')
            ->icon(Heroicon::OutlinedDeviceTablet)
            ->color('gray')
            ->modalHeading(fn (FichaTecnica $record): string => 'Ficha no tablet — '.$record->produto?->produto_descricao)
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->schema(fn (FichaTecnica $record): array => [
                Grid::make(3)->schema([
                    Select::make('setor')
                        ->label('Setor do tablet')
                        ->options(SetorProducao::ativos()->ordenados()->pluck('nome', 'id')->all())
                        ->placeholder('Todos os passos')
                        ->live(),
                    Select::make('segundo_sabor')
                        ->label('Meio a meio com')
                        ->options(Produto::query()
                            ->where('produto_categoria_id', $record->produto?->produto_categoria_id)
                            ->whereKeyNot($record->produto_id)
                            ->orderBy('produto_descricao')
                            ->pluck('produto_descricao', 'id')
                            ->all())
                        ->placeholder('Sabor único')
                        ->searchable()
                        ->live(),
                    TextInput::make('remocoes')
                        ->label('"SEM" de exemplo')
                        ->placeholder('Ex.: cebola, azeitona')
                        ->live(onBlur: true),
                ]),
                View::make('filament.cozinha.ficha-preview')
                    ->viewData(fn (Get $get): array => ['ficha' => self::montar($record, $get('setor'), $get('segundo_sabor'), $get('remocoes'))]),
            ]);
    }

    private static function montar(FichaTecnica $ficha, mixed $setorId, mixed $segundoSaborId, ?string $remocoes): FichaTecnicaView
    {
        $sabores = array_values(array_filter([
            $ficha->produto,
            filled($segundoSaborId) ? Produto::find($segundoSaborId) : null,
        ]));

        $remocoes = array_values(array_filter(array_map(
            fn (string $r) => trim(Str::of($r)->lower()->replaceStart('sem ', '')->toString()),
            explode(',', (string) $remocoes),
        )));

        return app(FichaTecnicaMontador::class)->paraSabores(
            $sabores,
            filled($setorId) ? SetorProducao::find($setorId) : null,
            $remocoes,
        );
    }
}
