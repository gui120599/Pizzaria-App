<?php

namespace App\Filament\Pages;

use App\Models\MovimentacaoProduto;
use App\Models\Produto;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Consulta pontual de "qual era o custo médio de um produto numa data
 * passada" — lê mov_custo_medio_apos (gravado a cada movimentação por
 * EstoqueService/CorrecaoEstoqueService) da última movimentação até a data
 * escolhida. Só consulta, não grava nada.
 */
class CustoMedioHistorico extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Custo médio histórico';

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $title = 'Custo médio histórico';

    protected static ?int $navigationSort = 31;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('produto_id')
                    ->label('Produto')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Produto::query()
                        ->where('produto_descricao', 'like', "%{$search}%")
                        ->limit(50)
                        ->pluck('produto_descricao', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Produto::find($value)?->produto_descricao)
                    ->live(),

                DatePicker::make('data')
                    ->label('Data')
                    ->native(false)
                    ->live(),

                Placeholder::make('resultado')
                    ->label('Custo médio na data')
                    ->content(fn ($get): HtmlString => $this->resultado($get('produto_id'), $get('data'))),
            ])
            ->statePath('data');
    }

    /**
     * Sem view Blade própria: a página base só renderiza o que content()
     * devolver (por padrão, vazio) — embute o form() aqui pra ele aparecer.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
        ]);
    }

    private function resultado(?int $produtoId, ?string $data): HtmlString
    {
        if (! $produtoId || ! $data) {
            return new HtmlString('Selecione o produto e a data.');
        }

        // Filtra pela data de negócio (mov_data), mas desempata pela ordem
        // real de processamento (id) — ver CorrecaoEstoqueService::historico().
        $custoMedio = MovimentacaoProduto::where('mov_produto_id', $produtoId)
            ->where('mov_data', '<=', Carbon::parse($data)->endOfDay())
            ->orderByDesc('id')
            ->value('mov_custo_medio_apos');

        if ($custoMedio === null) {
            return new HtmlString('Produto ainda não tinha movimentação até essa data.');
        }

        return new HtmlString('R$ '.number_format((float) $custoMedio, 4, ',', '.'));
    }
}
