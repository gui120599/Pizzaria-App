<?php

namespace App\Filament\Support;

use App\Enums\ProdutoTipoEnum;
use App\Models\Produto;
use Closure;
use Filament\Forms\Components\Select;

/**
 * Select do insumo de uma linha da ficha técnica, compartilhado pela aba de
 * custo do produto (FichaItensRelationManager) e pela ficha de preparo
 * (FichaTecnicaResource): mesmo cartão rico, mesma busca e a mesma trava
 * contra ciclo (A usa B que usa A).
 */
class InsumoFichaSelect
{
    /**
     * @param  Closure  $produtoDaFicha  devolve o id do produto dono da ficha;
     *                                   avaliado pelo componente (aceita Get, $record...)
     */
    public static function make(Closure $produtoDaFicha, string $nome = 'fti_insumo_id'): Select
    {
        return Select::make($nome)
            ->label('Insumo / Componente')
            ->placeholder('Digite o nome do produto...')
            ->searchable()
            ->allowHtml()
            ->getSearchResultsUsing(function (Select $component, string $search) use ($produtoDaFicha): array {
                $produtoId = (int) $component->evaluate($produtoDaFicha);

                return Produto::query()
                    ->when($produtoId > 0, fn ($q) => $q->where('id', '!=', $produtoId)) // não pode se referenciar
                    ->where('produto_tipo', '!=', ProdutoTipoEnum::PRODUZIDO->value) // vendido ao cliente, não é insumo de outra ficha
                    ->where('produto_descricao', 'like', "%{$search}%")
                    ->with('categoria')
                    ->orderBy('produto_descricao')
                    ->limit(30)
                    ->get()
                    ->mapWithKeys(fn (Produto $p) => [$p->id => self::renderOpcaoProduto($p)])
                    ->toArray();
            })
            ->getOptionLabelUsing(fn ($value): ?string => ($p = Produto::with('categoria')->find($value))
                ? self::renderOpcaoProduto($p)
                : null)
            ->native(false)
            ->required()
            ->live()
            ->afterStateUpdated(function ($state, $set): void {
                $insumo = $state ? Produto::find($state) : null;
                $set('fti_unidade', $insumo?->produto_unidade_estoque);
            })
            ->helperText('Pode ser um insumo ou um semi-acabado (com ficha própria).')
            ->rules([
                fn (Select $component): Closure => function (string $attribute, $value, Closure $fail) use ($component, $produtoDaFicha): void {
                    $produtoId = (int) $component->evaluate($produtoDaFicha);
                    $insumo = $value ? Produto::find($value) : null;

                    if ($insumo && $produtoId > 0 && $insumo->dependeDe($produtoId)) {
                        $fail("{$insumo->produto_descricao} já depende (direta ou indiretamente) deste produto na ficha técnica — isso criaria um ciclo.");
                    }
                },
            ]);
    }

    /** Mesmo cartão rico (foto, categoria, saldo) usado no select de produto de Compras e Balanço. */
    public static function renderOpcaoProduto(Produto $p): string
    {
        $saldoRaw = (float) $p->produto_saldo_estoque;

        return view('filament.components.select-balanco-produto', [
            'image' => $p->produto_foto ? asset('storage/'.$p->produto_foto) : null,
            'name' => $p->nomeExibicao(),
            'category' => $p->categoria?->categoria_nome ?? '',
            'saldo' => number_format($saldoRaw, 3, ',', '.'),
            'saldo_raw' => $saldoRaw,
            'unidade' => $p->produto_unidade_estoque ?? '',
        ])->render();
    }
}
