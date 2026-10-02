<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\TipoMesaEnum;
use App\Exceptions\MesaIndisponivelException;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Services\Garcom\MapaMesasService;
use App\Services\Garcom\RetiradaService;
use App\Services\SessaoMesaService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Tela inicial do salão: mesas/comandas por área, coloridas pelo status das
 * rodadas. Tocar numa livre abre a conta; numa ocupada, entra no atendimento.
 */
class MapaMesas extends Page
{
    protected string $view = 'filament.garcom.pages.mapa-mesas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Mesas';

    protected static ?string $title = 'Mesas';

    #[Url]
    public string $tipo = 'MESA';

    /**
     * Rodadas prontas já avisadas neste aparelho — o poll só alerta as novas.
     *
     * @var array<int, int>
     */
    public array $prontasAvisadas = [];

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function mount(): void
    {
        $this->prontasAvisadas = array_column(app(MapaMesasService::class)->prontasDoGarcom((int) Auth::id()), 'id');
    }

    /** @return Collection<string, Collection<int, array<string, mixed>>> */
    public function mesasPorArea(): Collection
    {
        if ($this->tipo === 'RETIRADA') {
            return collect();
        }

        return app(MapaMesasService::class)
            ->mapa(TipoMesaEnum::tryFrom($this->tipo) ?? TipoMesaEnum::MESA)
            ->groupBy('area');
    }

    public function temComandas(): bool
    {
        return Mesa::where('mesa_tipo', TipoMesaEnum::COMANDA->value)->exists();
    }

    /** @return Collection<int, Pedido> */
    public function retiradas(): Collection
    {
        return app(RetiradaService::class)->retiradasDoTurno();
    }

    /** Chamado pelo wire:poll: avisa rodadas que ficaram prontas desde o último ciclo. */
    public function verificarProntas(): void
    {
        $prontas = app(MapaMesasService::class)->prontasDoGarcom((int) Auth::id());
        $novas = array_filter($prontas, fn (array $p) => ! in_array($p['id'], $this->prontasAvisadas, true));

        $this->prontasAvisadas = array_column($prontas, 'id');

        if ($novas === []) {
            return;
        }

        $mesas = implode(', ', array_unique(array_column($novas, 'mesa')));

        Notification::make()
            ->title('Pedido pronto!')
            ->body("Retirar para: {$mesas}")
            ->success()
            ->persistent()
            ->send();

        $this->dispatch('garcom-pedido-pronto');
    }

    public function tocarMesa(int $mesaId): void
    {
        $mesa = Mesa::find($mesaId);

        if (! $mesa || $mesa->mesa_status === 'INATIVA') {
            return;
        }

        if ($mesa->mesa_status === 'OCUPADA' && $mesa->mesa_sessao_atual_id) {
            $this->redirect(AtenderMesa::getUrl(['sessao' => $mesa->mesa_sessao_atual_id]), navigate: true);

            return;
        }

        $this->mountAction('abrirMesa', ['mesa' => $mesaId]);
    }

    public function abrirMesaAction(): Action
    {
        return Action::make('abrirMesa')
            ->modalHeading(fn (array $arguments): string => 'Abrir '.(Mesa::find($arguments['mesa'] ?? null)?->mesa_nome ?? 'mesa'))
            ->modalSubmitActionLabel('Abrir conta')
            ->modalWidth('sm')
            ->schema([
                TextInput::make('pessoas')
                    ->label('Pessoas na mesa')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99)
                    ->default(2)
                    ->required()
                    ->extraInputAttributes(['inputmode' => 'numeric', 'class' => 'text-center text-2xl']),
                Toggle::make('taxa_servico')
                    ->label('Cobrar taxa de serviço ('.number_format((float) config('pizzaria.salao.taxa_servico_percentual'), 0).'%)')
                    ->default((bool) config('pizzaria.salao.taxa_servico_padrao_ligada'))
                    // Abrir sem taxa equivale a removê-la: só quem tem a permissão.
                    ->visible(fn (): bool => (bool) Auth::user()?->can('remover_taxa:sessao_mesa')),
            ])
            ->action(function (array $data, array $arguments): void {
                try {
                    $sessao = app(SessaoMesaService::class)->abrir(
                        mesaId: (int) $arguments['mesa'],
                        usuarioId: (int) Auth::id(),
                        pessoas: (int) $data['pessoas'],
                        taxaServicoPercentual: match ($data['taxa_servico'] ?? null) {
                            null => null,
                            true => (float) config('pizzaria.salao.taxa_servico_percentual'),
                            false => 0.0,
                        },
                    );
                } catch (MesaIndisponivelException $e) {
                    Notification::make()->title($e->getMessage())->warning()->send();

                    return;
                }

                $this->redirect(AtenderMesa::getUrl(['sessao' => $sessao->id]), navigate: true);
            });
    }
}
