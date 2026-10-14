<?php

namespace App\Filament\Cozinha\Pages;

use App\Models\Categoria;
use App\Models\DispositivoCozinha;
use App\Models\FichaTecnica;
use App\Models\Produto;
use App\Models\SetorProducao;
use App\Services\Cozinha\FichaTecnicaMontador;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Consulta de fichas fora do pedido (spec §6.7): busca por produto/sabor e
 * filtro por tamanho (categoria). Abre o mesmo modal da Estação.
 */
class ConsultaFichas extends Page
{
    protected string $view = 'filament.cozinha.pages.consulta-fichas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Fichas';

    protected static ?string $title = 'Fichas técnicas';

    protected static ?string $slug = 'fichas';

    protected static ?int $navigationSort = 4;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public string $busca = '';

    #[Url]
    public ?int $categoriaId = null;

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', FichaTecnica::class);
    }

    /** @return Collection<int, FichaTecnica> */
    #[Computed]
    public function fichas(): Collection
    {
        $termo = trim($this->busca);

        return FichaTecnica::ativas()
            ->with('produto:id,produto_descricao,produto_categoria_id,produto_foto', 'produto.categoria:id,categoria_nome')
            ->whereHas('produto', fn ($q) => $q
                ->when($termo !== '', fn ($p) => $p->where('produto_descricao', 'like', "%{$termo}%"))
                ->when($this->categoriaId, fn ($p) => $p->where('produto_categoria_id', $this->categoriaId)))
            ->get()
            ->sortBy(fn (FichaTecnica $f) => $f->produto?->produto_descricao)
            ->values();
    }

    /** @return array<int, string> */
    #[Computed]
    public function categorias(): array
    {
        return Categoria::query()
            ->whereIn('id', Produto::whereHas('fichaTecnica', fn ($q) => $q->ativas())->select('produto_categoria_id'))
            ->orderBy('categoria_nome')
            ->pluck('categoria_nome', 'id')
            ->all();
    }

    public function fichaAction(): Action
    {
        return Action::make('ficha')
            ->modalHeading(fn (array $arguments): string => Produto::find($arguments['produto'] ?? 0)?->produto_descricao ?? 'Ficha')
            ->modalWidth(Width::Screen)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (array $arguments) {
                $setorId = DispositivoCozinha::atual()?->setorIds()[0] ?? null;

                return view('filament.cozinha.ficha-modal', [
                    'ficha' => app(FichaTecnicaMontador::class)->paraProduto(
                        Produto::with('categoria')->findOrFail($arguments['produto'] ?? 0),
                        $setorId ? SetorProducao::find($setorId) : null,
                    ),
                ]);
            });
    }
}
