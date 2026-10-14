<?php

namespace App\Filament\Cozinha\Pages;

use App\Enums\Cozinha\StatusItemProducao;
use App\Filament\Cozinha\Concerns\GerenciaOperadorCozinha;
use App\Filament\Cozinha\Concerns\InteractsWithTempoRealCozinha;
use App\Models\ChecklistExecucao;
use App\Models\ProducaoItem;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Execução do checklist no tablet (layout "tablet" do componente
 * compartilhado App\Livewire\Checklists\ExecucaoChecklist). Checklist nunca
 * segura pedido (spec §5.10.6): item novo no setor toca e aparece em
 * "Voltar à fila (N novos)".
 */
class ExecutarChecklistCozinha extends Page
{
    use GerenciaOperadorCozinha;
    use InteractsWithTempoRealCozinha;

    protected string $view = 'filament.cozinha.pages.executar-checklist';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'checklists/{execucao}';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Locked]
    public int $execucaoId;

    #[Locked]
    public string $titulo;

    /** Início da tela: o que entrou na fila depois disso é "novo". */
    #[Locked]
    public string $abertoEm;

    /** Quantos novos já tocaram (o som só toca quando aumenta). */
    public int $novosAvisados = 0;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(int|string $execucao): void
    {
        $registro = ChecklistExecucao::findOrFail($execucao);

        abort_unless(Gate::forUser($this->executor())->allows('executar', $registro) || Gate::allows('view', $registro) || $this->precisaEscolherOperador, 403);

        $this->execucaoId = $registro->id;
        $this->titulo = $registro->modelo_nome;
        $this->abertoEm = now()->toDateTimeString();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->titulo;
    }

    protected function setoresTempoReal(): array
    {
        return $this->dispositivo()?->setorIds() ?? [];
    }

    protected function aoMudarTempoReal(): void
    {
        unset($this->novosNaFila);

        if ($this->novosNaFila > $this->novosAvisados) {
            $this->dispatch('cozinha-som', tipo: 'novo');
        }

        $this->novosAvisados = $this->novosNaFila;
    }

    #[Computed]
    public function novosNaFila(): int
    {
        $setores = $this->dispositivo()?->setorIds();

        if ($setores === null) {
            return 0;
        }

        return ProducaoItem::doSetor($setores)
            ->where('status', StatusItemProducao::NaFila)
            ->where('liberado_em', '>=', $this->abertoEm)
            ->count();
    }

    /** A execução recarrega com a nova pessoa: cada resposta fica com quem estava ativo. */
    protected function aoTrocarOperador(): void
    {
        $this->redirect(static::getUrl(['execucao' => $this->execucaoId]), navigate: true);
    }
}
