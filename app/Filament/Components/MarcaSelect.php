<?php

namespace App\Filament\Components;

use App\Models\Marca;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Select rico (imagem + nome) para escolher a marca de um insumo, no mesmo
 * padrão visual do select de produto (select-balanco-produto.blade.php).
 * Guarda o marca_id — a marca é só rastreabilidade da compra/lote, o
 * insumo no estoque continua único (ver LotesRelationManager).
 */
class MarcaSelect extends Select
{
    public static function getDefaultName(): ?string
    {
        return 'marca_id';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Marca')
            ->placeholder('Digite o nome da marca...')
            ->searchable()
            ->allowHtml()
            ->native(false)
            ->getSearchResultsUsing(fn (string $search): array => Marca::query()
                ->where('marca_nome', 'like', "%{$search}%")
                ->orderBy('marca_nome')
                ->limit(30)
                ->get()
                ->mapWithKeys(fn (Marca $marca) => [$marca->id => self::renderOpcao($marca)])
                ->toArray())
            ->getOptionLabelUsing(fn ($value): ?string => ($marca = Marca::find($value))
                ? self::renderOpcao($marca)
                : null)
            ->createOptionForm([
                TextInput::make('marca_nome')
                    ->label('Nome da marca')
                    ->required()
                    ->maxLength(100),

                FileUpload::make('marca_imagem')
                    ->label('Imagem')
                    ->disk('public')
                    ->directory('marcas')
                    ->image()
                    ->imageEditor()
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('1:1')
                    ->imageResizeTargetWidth(300)
                    ->imageResizeTargetHeight(300),
            ])
            ->createOptionAction(fn (Action $action) => $action->modalHeading('Cadastrar nova marca'))
            ->createOptionUsing(fn (array $data): int => Marca::create($data)->getKey());
    }

    private static function renderOpcao(Marca $marca): string
    {
        return view('filament.components.select-marca', [
            'image' => $marca->marca_imagem ? asset('storage/'.$marca->marca_imagem) : null,
            'nome' => $marca->marca_nome,
        ])->render();
    }
}
