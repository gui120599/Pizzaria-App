<?php

namespace App\Filament\Cozinha\Pages;

use App\Enums\Cozinha\ModoExibicaoCozinha;
use App\Enums\Cozinha\StatusItemProducao;
use App\Exceptions\ProducaoException;
use App\Filament\Cozinha\Concerns\GerenciaOperadorCozinha;
use App\Filament\Cozinha\Concerns\InteractsWithTempoRealCozinha;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Services\Cozinha\FichaTecnicaMontador;
use App\Services\Cozinha\PainelCozinhaService;
use App\Services\Cozinha\ProducaoService;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Tela do tablet (spec §6.4): fila, em preparo e prontos dos setores do
 * tablet, ou um ticket por pedido. Toda transição passa pelo ProducaoService
 * com a versão que o card mostrava (outro tablet pode ter mexido antes).
 */
class Estacao extends Page
{
    use GerenciaOperadorCozinha;
    use InteractsWithTempoRealCozinha;

    protected string $view = 'filament.cozinha.pages.estacao';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static ?string $navigationLabel = 'Estação';

    protected static ?string $title = 'Estação';

    protected static ?string $slug = 'estacao';

    protected static ?int $navigationSort = 1;

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var list<int> */
    #[Locked]
    public array $setorIds = [];

    /** Chip escolhido quando o tablet atende mais de um setor (null = todos). */
    public ?int $setorAtivo = null;

    public string $modo = 'itens';

    /** Modo treinamento: a ficha abre sozinha ao iniciar. */
    public bool $treinamento = false;

    public bool $allDayAberto = true;

    /**
     * Ids já vistos na fila, para tocar o som só quando chega item novo.
     *
     * @var list<int>
     */
    public array $vistos = [];

    /** O topo da tela já mostra os setores: sem o título grande do Filament. */
    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('produzir:producao_item');
    }

    public function mount(): void
    {
        $dispositivo = $this->dispositivo();

        $this->setorIds = $dispositivo?->setorIds()
            ?? SetorProducao::ativos()->deProducao()->ordenados()->pluck('id')->all();
        $this->modo = $dispositivo?->modo_exibicao?->value ?? ModoExibicaoCozinha::Itens->value;
        $this->vistos = $this->colunas['fila']->modelKeys();
    }

    protected function setoresTempoReal(): array
    {
        return $this->setoresVisiveis();
    }

    protected function aoMudarTempoReal(): void
    {
        $this->limparDados();

        $fila = $this->colunas['fila'];
        $novos = array_diff($fila->modelKeys(), $this->vistos);
        $alerta = $this->colunas['preparo']->contains(fn (ProducaoItem $i) => $this->esperaCiencia($i))
            || $fila->contains(fn (ProducaoItem $i) => $this->esperaCiencia($i));

        if ($alerta) {
            $this->dispatch('cozinha-som', tipo: 'alerta');
        } elseif ($novos !== [] && $this->setores->whereIn('id', $fila->whereIn('id', $novos)->pluck('setor_producao_id'))->contains('som_novo_item', true) && ($this->dispositivo()?->som_ativo ?? true)) {
            $this->dispatch('cozinha-som', tipo: 'novo');
        }

        $this->vistos = $fila->modelKeys();
    }

    public function updatedSetorAtivo(): void
    {
        if ($this->setorAtivo !== null && ! in_array($this->setorAtivo, $this->setorIds, true)) {
            $this->setorAtivo = null;
        }

        $this->limparDados();
        $this->sincronizarVersao();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dados
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<int> */
    public function setoresVisiveis(): array
    {
        return $this->setorAtivo !== null ? [$this->setorAtivo] : $this->setorIds;
    }

    /** @return Collection<int, SetorProducao> */
    #[Computed]
    public function setores(): Collection
    {
        return SetorProducao::whereKey($this->setorIds)->ordenados()->get();
    }

    /** @return array{fila: Collection<int, ProducaoItem>, preparo: Collection<int, ProducaoItem>, prontos: Collection<int, ProducaoItem>} */
    #[Computed]
    public function colunas(): array
    {
        return app(PainelCozinhaService::class)->estacao($this->setoresVisiveis());
    }

    /** @return list<array{rotulo: string, quantidade: string, base: bool}> */
    #[Computed]
    public function allDay(): array
    {
        return app(PainelCozinhaService::class)->allDay($this->colunas['fila']->concat($this->colunas['preparo']));
    }

    /** @return array{fila: int, preparo: int, atrasados: int} */
    #[Computed]
    public function contadores(): array
    {
        $ativos = $this->colunas['fila']->concat($this->colunas['preparo'])->filter(fn (ProducaoItem $i) => $i->status->ehAtivoNaEstacao());

        return [
            'fila' => $this->colunas['fila']->where('status', StatusItemProducao::NaFila)->count(),
            'preparo' => $this->colunas['preparo']->where('status', StatusItemProducao::EmPreparo)->count(),
            'atrasados' => $ativos->filter(fn (ProducaoItem $i) => in_array($i->corTimer(), ['vermelho', 'atrasado'], true))->count(),
        ];
    }

    /** @return list<array{execucao_id: ?int, modelo_id: int, nome: string, cor: string, texto: string}> */
    #[Computed]
    public function pendenciasChecklist(): array
    {
        $usuario = $this->executor();

        return $usuario === null ? [] : app(PainelCozinhaService::class)->pendenciasDeChecklist($usuario, $this->dispositivo());
    }

    /**
     * Modo pedidos: um ticket por pedido com os itens do setor.
     *
     * @return \Illuminate\Support\Collection<int, Collection<int, ProducaoItem>>
     */
    #[Computed]
    public function tickets(): \Illuminate\Support\Collection
    {
        return $this->colunas['fila']->concat($this->colunas['preparo'])
            ->groupBy('pedido_id')
            ->sortBy(fn (Collection $itens) => $itens->min('liberado_em'));
    }

    public function esperaCiencia(ProducaoItem $item): bool
    {
        return ($item->status === StatusItemProducao::Cancelado && $item->cancelamento_ciente_em === null)
            || ($item->alterado_apos_inicio_em !== null && $item->alteracao_ciente_em === null);
    }

    /** Sem operador num setor que exige: os botões ficam travados. */
    public function bloqueadoSemOperador(ProducaoItem $item): bool
    {
        return $this->operador === null && (bool) $item->setor?->exige_operador;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ações do card (sempre pelo ProducaoService)
    // ─────────────────────────────────────────────────────────────────────────

    public function iniciar(int $itemId, int $versao): void
    {
        $item = $this->executar($itemId, fn (ProducaoService $s, ProducaoItem $i) => $s->iniciar($i, $this->operador, $versao, $this->dispositivo()), 'Iniciado', 'devolver');

        if ($item !== null && ($this->treinamento || $item->setor?->exibir_ficha_automatica)) {
            $this->mountAction('ficha', ['item' => $item->id]);
        }
    }

    public function concluir(int $itemId, int $versao): void
    {
        $this->executar($itemId, fn (ProducaoService $s, ProducaoItem $i) => $s->concluir($i, $this->operador, $versao, $this->dispositivo()), 'Pronto', 'desfazer');
    }

    /** "Devolver para a fila" do menu e o desfazer do "Iniciar". */
    public function devolver(int $itemId): void
    {
        $this->executar($itemId, fn (ProducaoService $s, ProducaoItem $i) => $s->devolverParaFila($i, $this->operador, dispositivo: $this->dispositivo()));
    }

    /** "Voltar ao preparo" dos prontos e o desfazer do "Pronto". */
    public function desfazer(int $itemId): void
    {
        $this->executar($itemId, fn (ProducaoService $s, ProducaoItem $i) => $s->desfazer($i, $this->operador, dispositivo: $this->dispositivo()));
    }

    public function ciente(int $itemId): void
    {
        $this->executar($itemId, fn (ProducaoService $s, ProducaoItem $i) => $s->confirmarCiencia($i, $this->operador, $this->dispositivo()));
    }

    /** "Iniciar pedido" do modo pedidos: inicia os itens do pedido que estão na fila deste tablet. */
    public function iniciarPedido(int $pedidoId): void
    {
        $naFila = $this->colunas['fila']->where('pedido_id', $pedidoId)->where('status', StatusItemProducao::NaFila);

        foreach ($naFila as $item) {
            $this->executar($item->id, fn (ProducaoService $s, ProducaoItem $i) => $s->iniciar($i, $this->operador, dispositivo: $this->dispositivo()));
        }
    }

    public function fichaAction(): Action
    {
        return Action::make('ficha')
            ->modalHeading(fn (array $arguments): string => '#'.(ProducaoItem::find($arguments['item'] ?? 0)?->pedido_id ?? '').' · '.(ProducaoItem::find($arguments['item'] ?? 0)?->referencia ?? ''))
            ->modalWidth(Width::Screen)
            ->modalContent(fn (array $arguments) => view('filament.cozinha.ficha-modal', [
                'ficha' => app(FichaTecnicaMontador::class)->paraItem(ProducaoItem::findOrFail($arguments['item'] ?? 0)),
            ]))
            ->modalCancelActionLabel('Fechar')
            // No modo treinamento o "Pronto" fica no rodapé da ficha.
            ->modalSubmitActionLabel('PRONTO')
            ->modalSubmitAction(fn (Action $action, array $arguments) => ProducaoItem::find($arguments['item'] ?? 0)?->status === StatusItemProducao::EmPreparo ? $action : false)
            ->action(function (array $arguments): void {
                $item = ProducaoItem::findOrFail($arguments['item'] ?? 0);
                $this->concluir($item->id, $item->versao);
            });
    }

    /**
     * @param  Closure(ProducaoService, ProducaoItem): ProducaoItem  $transicao
     * @param  string|null  $desfazer  método que desfaz a ação (toast de 10 s)
     */
    private function executar(int $itemId, Closure $transicao, ?string $rotulo = null, ?string $desfazer = null): ?ProducaoItem
    {
        $item = ProducaoItem::with('setor')->whereKey($itemId)->doSetor($this->setorIds)->first();

        if ($item === null) {
            Notification::make()->warning()->title('Este item não está mais neste tablet')->send();
            $this->depoisDaAcao();

            return null;
        }

        if (! Gate::forUser($this->executor())->allows('produzir', [$item, $this->dispositivo()])) {
            Notification::make()->danger()->title('Sem permissão para produzir neste setor')->send();

            return null;
        }

        try {
            $atualizado = $transicao(app(ProducaoService::class), $item);
        } catch (ProducaoException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();
            $this->depoisDaAcao();

            return null;
        }

        $this->depoisDaAcao();

        if ($rotulo !== null && $desfazer !== null) {
            $this->dispatch('cozinha-feito', id: $itemId, rotulo: $rotulo.': '.($item->snapshot['produto'] ?? 'item'), desfazer: $desfazer, segundos: (int) config('cozinha.desfazer_segundos', 10));
        }

        return $atualizado;
    }

    private function depoisDaAcao(): void
    {
        $this->limparDados();
        $this->vistos = $this->colunas['fila']->modelKeys();
        $this->sincronizarVersao();
    }

    private function limparDados(): void
    {
        unset($this->colunas, $this->allDay, $this->contadores, $this->tickets, $this->pendenciasChecklist);
    }
}
