<?php

namespace App\Filament\Cozinha\Pages;

use App\Enums\Cozinha\MotivoRefacao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\Cozinha\StatusProducaoPedido;
use App\Enums\TipoAtendimentoEnum;
use App\Exceptions\ProducaoException;
use App\Filament\Cozinha\Concerns\GerenciaOperadorCozinha;
use App\Filament\Cozinha\Concerns\InteractsWithTempoRealCozinha;
use App\Filament\Support\ProducaoPedidoActions;
use App\Models\DispositivoCozinha;
use App\Models\Pedido;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Services\Cozinha\OperadorCozinhaService;
use App\Services\Cozinha\PainelCozinhaService;
use App\Services\Cozinha\ProducaoService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * O passe (spec §6.6): um card por pedido com produção ativa, progresso por
 * setor, e o DESPACHAR quando tudo ficou pronto. Oculta quando a operação
 * não usa expedição (cozinha.usa_expedicao).
 */
class Expedicao extends Page
{
    use GerenciaOperadorCozinha;
    use InteractsWithTempoRealCozinha;

    protected string $view = 'filament.cozinha.pages.expedicao';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static ?string $navigationLabel = 'Expedição';

    protected static ?string $title = 'Expedição';

    protected static ?string $slug = 'expedicao';

    protected static ?int $navigationSort = 2;

    protected Width|string|null $maxContentWidth = Width::Full;

    /** 'ativos' | 'despachados' */
    public string $aba = 'ativos';

    /**
     * Quem despacha (papel Expedição/Gerente, ou Cozinha com cozinha_despacha)
     * ou o tablet pareado ao setor de expedição.
     */
    public static function canAccess(): bool
    {
        if (! config('cozinha.usa_expedicao')) {
            return false;
        }

        $usuario = app(OperadorCozinhaService::class)->atual()?->usuario() ?? auth()->user();

        return ($usuario !== null && Gate::forUser($usuario)->allows('despachar', ProducaoItem::class))
            || (DispositivoCozinha::atual()?->setoresProducao()->contains(fn (SetorProducao $s) => $s->ehExpedicao()) ?? false);
    }

    public function getHeading(): string
    {
        return '';
    }

    protected function setoresTempoReal(): array
    {
        return [];
    }

    protected function aoMudarTempoReal(): void
    {
        unset($this->pedidos, $this->despachados);
    }

    /** @return Collection<int, Pedido> */
    #[Computed]
    public function pedidos(): Collection
    {
        return app(PainelCozinhaService::class)->expedicao();
    }

    /** @return Collection<int, Pedido> */
    #[Computed]
    public function despachados(): Collection
    {
        return $this->aba === 'despachados' ? app(PainelCozinhaService::class)->despachados() : new Collection;
    }

    /**
     * Progresso por setor: "🍕 Pizzaria 2/3". Só a última etapa de cada item conta.
     *
     * @return list<array{setor: string, cor: string, prontos: int, total: int}>
     */
    public function progresso(Pedido $pedido): array
    {
        return $pedido->producaoItens
            ->filter(fn (ProducaoItem $i) => $i->ehUltimaEtapa())
            ->groupBy('setor_producao_id')
            ->map(fn ($itens) => [
                'setor' => $itens->first()->setor?->nome ?? '—',
                'cor' => $itens->first()->setor?->cor ?? '#6b7280',
                'prontos' => $itens->whereIn('status', [StatusItemProducao::Pronto, StatusItemProducao::Expedido])->count(),
                'total' => $itens->count(),
            ])
            ->values()
            ->all();
    }

    public function itensSemProducao(Pedido $pedido): \Illuminate\Support\Collection
    {
        return app(PainelCozinhaService::class)->itensSemProducao($pedido);
    }

    public function rotuloDespacho(Pedido $pedido): string
    {
        return match ($pedido->tipoAtendimento()) {
            TipoAtendimentoEnum::MESA => 'LEVAR À MESA',
            TipoAtendimentoEnum::DELIVERY => 'PRONTO PARA ENTREGA',
            default => 'CHAMAR CLIENTE',
        };
    }

    public function despachar(int $pedidoId): void
    {
        if (! static::canAccess()) {
            Notification::make()->danger()->title('Sem permissão para despachar')->send();

            return;
        }

        try {
            app(ProducaoService::class)->expedirPedido(Pedido::findOrFail($pedidoId), $this->executor());
        } catch (ProducaoException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();
        }

        unset($this->pedidos, $this->despachados);
        $this->sincronizarVersao();
    }

    public function refazerAction(): Action
    {
        return Action::make('refazer')
            ->label('Refazer item')
            ->color('danger')
            ->authorize(fn (): bool => Gate::forUser($this->executor())->allows('refazer', ProducaoItem::class))
            ->modalHeading(fn (array $arguments): string => 'Refazer: '.(ProducaoItem::find($arguments['item'] ?? 0)?->snapshot['produto'] ?? 'item'))
            ->modalDescription('O item volta para o topo da fila do setor, como urgente.')
            ->schema([
                Select::make('motivo')
                    ->label('Motivo')
                    ->options(MotivoRefacao::class)
                    ->required(),
                Textarea::make('observacao')
                    ->label('Observação')
                    ->rows(2)
                    ->maxLength(255),
            ])
            ->action(function (array $arguments, array $data): void {
                $motivo = $data['motivo'] instanceof MotivoRefacao ? $data['motivo'] : MotivoRefacao::from($data['motivo']);

                try {
                    app(ProducaoService::class)->refazer(ProducaoItem::findOrFail($arguments['item'] ?? 0), $motivo, $this->executor(), $data['observacao'] ?? null);
                } catch (ProducaoException $e) {
                    Notification::make()->warning()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Item enviado de novo para a cozinha')->send();
                unset($this->pedidos);
                $this->sincronizarVersao();
            });
    }

    public function priorizarAction(): Action
    {
        return ProducaoPedidoActions::priorizar();
    }

    public function estaPronto(Pedido $pedido): bool
    {
        return $pedido->pedido_producao_status === StatusProducaoPedido::Pronto;
    }
}
