<?php

namespace App\Filament\Components;

use App\Filament\Resources\Produtos\Schemas\ProdutoForm;
use App\Models\Produto;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Select rico de produto/insumo usado na compra (imagem, categoria e saldo
 * em estoque — select-balanco-produto.blade.php), com busca por nome ou
 * categoria e cadastro de produto novo sem sair do modal. Compartilhado
 * entre o item da compra e a Caixa de entrada de compras.
 */
class ProdutoCompraSelect extends Select
{
    /** @var array<string, mixed>|Closure|null */
    protected array|Closure|null $dadosNovoProduto = null;

    public static function getDefaultName(): ?string
    {
        return 'produto_id';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Buscar produto / insumo')
            ->placeholder('Digite o nome do produto...')
            ->searchable()
            ->allowHtml()
            ->getSearchResultsUsing(fn (string $search): array => Produto::query()
                ->where(function (Builder $q) use ($search): void {
                    $q->where('produto_descricao', 'like', "%{$search}%")
                        ->orWhereHas('categoria', fn (Builder $c) => $c->where('categoria_nome', 'like', "%{$search}%"));
                })
                ->with('categoria')
                ->orderBy('produto_descricao')
                ->limit(30)
                ->get()
                ->mapWithKeys(fn (Produto $p) => [$p->id => self::renderOpcao($p)])
                ->toArray())
            ->getOptionLabelUsing(fn ($value): ?string => ($p = Produto::with('categoria')->find($value))
                ? self::renderOpcao($p)
                : null)
            ->createOptionForm(fn (Schema $schema) => ProdutoForm::configure($schema))
            ->createOptionAction(
                fn (Action $action) => $action
                    ->modalHeading('Cadastrar novo produto')
                    ->slideOver()
                    ->mountUsing(function (?Schema $schema): void {
                        $schema?->fill();
                        $dados = $this->getDadosNovoProduto();
                        if ($dados !== []) {
                            $schema?->fillPartially($dados, array_keys($dados));
                        }
                    })
            )
            ->createOptionUsing(fn (array $data): int => Produto::create($data)->getKey());
    }

    /**
     * Pré-preenche o cadastro de produto novo (botão "+") — ex.: com a
     * descrição, EAN e NCM do item da nota. Campos vazios são ignorados e
     * os demais ficam com o padrão do cadastro.
     *
     * @param  array<string, mixed>|Closure|null  $dados
     */
    public function preencherNovoProdutoCom(array|Closure|null $dados): static
    {
        $this->dadosNovoProduto = $dados;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getDadosNovoProduto(): array
    {
        return array_filter((array) $this->evaluate($this->dadosNovoProduto), fn (mixed $valor): bool => filled($valor));
    }

    public static function renderOpcao(Produto $p): string
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
