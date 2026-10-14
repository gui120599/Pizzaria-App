<?php

namespace App\Filament\Resources\FichasTecnicas\Support;

use App\Enums\ProdutoTipoEnum;
use App\Exceptions\ProducaoException;
use App\Filament\Resources\FichasTecnicas\FichaTecnicaResource;
use App\Models\FichaTecnica;
use App\Models\Produto;
use App\Services\Cozinha\FichaTecnicaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Duplicar para outro tamanho": como o tamanho é a categoria, copia a ficha
 * para o mesmo sabor em outra categoria (Calabresa Grande → Calabresa Média)
 * escalando as quantidades pelo fator.
 */
class DuplicarFichaTecnicaAction
{
    public static function make(): Action
    {
        return Action::make('duplicar')
            ->label('Duplicar para outro tamanho')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->authorize('create', FichaTecnica::class)
            ->modalDescription('Copia passos, fotos e ingredientes. Se o produto de destino já tiver ficha de custo, os ingredientes dele são mantidos.')
            ->schema(fn (FichaTecnica $record): array => [
                Select::make('produto_id')
                    ->label('Produto de destino')
                    ->options(self::destinos($record))
                    ->default(fn (): ?int => self::sugestao($record))
                    ->searchable()
                    ->required(),
                TextInput::make('fator')
                    ->label('Fator de escala das quantidades')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(10)
                    ->step(0.01)
                    ->default(1)
                    ->required()
                    ->helperText('Ex.: da Grande (35 cm) para a Média (30 cm) ≈ 0,73. 1 = mesmas quantidades.'),
            ])
            ->action(function (FichaTecnica $record, array $data, Action $action): void {
                try {
                    $resultado = app(FichaTecnicaService::class)->duplicar(
                        $record,
                        Produto::findOrFail($data['produto_id']),
                        (float) $data['fator'],
                        (int) auth()->id(),
                    );
                } catch (ProducaoException $e) {
                    Notification::make()->danger()->title('Não foi possível duplicar')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Ficha duplicada')
                    ->body($resultado['ingredientesMantidos']
                        ? 'O destino já tinha ficha de custo: os ingredientes dele foram mantidos. Revise o escopo de cada um.'
                        : "{$resultado['ingredientes']} ingrediente(s) copiado(s) com o fator {$data['fator']}.")
                    ->send();

                $action->redirect(FichaTecnicaResource::getUrl('edit', ['record' => $resultado['ficha']]));
            });
    }

    /** @return array<int, string> */
    private static function destinos(FichaTecnica $ficha): array
    {
        return Produto::query()
            ->whereIn('produto_tipo', [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::INSUMO_PRODUZIDO->value])
            ->whereKeyNot($ficha->produto_id)
            ->whereDoesntHave('fichaTecnica', fn ($q) => $q->withTrashed())
            ->with('categoria:id,categoria_nome')
            ->orderBy('produto_descricao')
            ->get()
            ->mapWithKeys(fn (Produto $p) => [$p->id => trim($p->produto_descricao.' — '.($p->categoria?->categoria_nome ?? ''), ' —')])
            ->all();
    }

    /** Mesmo nome em outra categoria (o mesmo sabor em outro tamanho). */
    private static function sugestao(FichaTecnica $ficha): ?int
    {
        return Produto::query()
            ->where('produto_descricao', $ficha->produto?->produto_descricao)
            ->whereKeyNot($ficha->produto_id)
            ->whereDoesntHave('fichaTecnica', fn ($q) => $q->withTrashed())
            ->value('id');
    }
}
