<?php

namespace App\Filament\Resources\Balancos\Schemas;

use App\Models\Produto;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BalancoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Balanço físico')
                ->description('Informe a quantidade contada fisicamente. O sistema calculará a diferença e gerará a movimentação de ajuste automaticamente.')
                ->columns(2)
                ->schema([
                    Select::make('mbal_produto_id')
                        ->label('Produto')
                        ->placeholder('Digite o nome do produto...')
                        ->searchable()
                        ->allowHtml()
                        ->getSearchResultsUsing(fn (string $search): array => Produto::query()
                            ->where('produto_controla_estoque', true)
                            ->where('produto_descricao', 'like', "%{$search}%")
                            ->with('categoria')
                            ->orderBy('produto_descricao')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Produto $p) => [$p->id => self::renderOpcaoProduto($p)])
                            ->toArray())
                        ->getOptionLabelUsing(fn ($value): ?string => ($p = Produto::with('categoria')->find($value))
                            ? self::renderOpcaoProduto($p)
                            : null)
                        ->native(false)
                        ->required()
                        ->live()
                        ->columnSpanFull(),

                    Placeholder::make('saldo_sistema')
                        ->label('Saldo atual no sistema')
                        ->content(function ($get): string {
                            $id = $get('mbal_produto_id');
                            if (! $id) {
                                return '—';
                            }
                            $produto = Produto::find($id);
                            if (! $produto) {
                                return '—';
                            }
                            $unidade = $produto->produto_unidade_estoque ?? '';

                            return number_format((float) $produto->produto_saldo_estoque, 3, ',', '.') . ($unidade ? " {$unidade}" : '');
                        }),

                    TextInput::make('mbal_quantidade_balanco')
                        ->label('Quantidade física contada')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001)
                        ->required()
                        ->suffix(function ($get): ?string {
                            $id = $get('mbal_produto_id');
                            if (! $id) {
                                return null;
                            }

                            return Produto::find($id)?->produto_unidade_estoque;
                        }),

                    Textarea::make('mbal_observacao')
                        ->label('Observação')
                        ->placeholder('Motivo do ajuste, responsável pela contagem...')
                        ->rows(3)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    private static function renderOpcaoProduto(Produto $p): string
    {
        $saldoRaw = (float) $p->produto_saldo_estoque;

        return view('filament.components.select-balanco-produto', [
            'image'    => $p->produto_foto ? asset('storage/' . $p->produto_foto) : null,
            'name'     => $p->nomeExibicao(),
            'category' => $p->categoria?->categoria_nome ?? '',
            'saldo'    => number_format($saldoRaw, 3, ',', '.'),
            'saldo_raw'=> $saldoRaw,
            'unidade'  => $p->produto_unidade_estoque ?? '',
        ])->render();
    }
}
