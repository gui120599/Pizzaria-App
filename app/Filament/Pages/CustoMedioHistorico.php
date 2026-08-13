<?php

namespace App\Filament\Pages;

use App\Models\MovimentacaoProduto;
use App\Models\Produto;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
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

    public ?string $resultado = null;

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
                    ->required(),

                DatePicker::make('data')
                    ->label('Data')
                    ->native(false)
                    ->required(),

                Placeholder::make('resultado')
                    ->label('Custo médio na data')
                    ->content(fn (): HtmlString => new HtmlString($this->resultado ?? 'Escolha o produto e a data, depois clique em Consultar.')),
            ])
            ->statePath('data');
    }

    /**
     * Sem view Blade própria: a página base só renderiza o que content()
     * devolver (por padrão, vazio) — embute o form() e o botão aqui.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('consultar')
                ->footer([
                    Actions::make([
                        Action::make('consultar')
                            ->label('Consultar')
                            ->submit('consultar'),
                    ])->alignment(Alignment::Start),
                ]),
        ]);
    }

    public function consultar(): void
    {
        $state = $this->form->getState();
        $this->resultado = $this->calcular($state['produto_id'] ?? null, $state['data'] ?? null);
    }

    private function calcular(?int $produtoId, ?string $data): string
    {
        if (! $produtoId || ! $data) {
            return 'Escolha o produto e a data.';
        }

        // Filtra pela data de negócio (mov_data), mas desempata pela ordem
        // real de processamento (id) — ver CorrecaoEstoqueService::historico().
        $custoMedio = MovimentacaoProduto::where('mov_produto_id', $produtoId)
            ->where('mov_data', '<=', Carbon::parse($data)->endOfDay())
            ->orderByDesc('id')
            ->value('mov_custo_medio_apos');

        if ($custoMedio === null) {
            return 'Produto ainda não tinha movimentação até essa data.';
        }

        return 'R$ '.number_format((float) $custoMedio, 4, ',', '.');
    }
}
