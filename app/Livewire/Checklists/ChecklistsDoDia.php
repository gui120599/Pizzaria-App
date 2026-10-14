<?php

namespace App\Livewire\Checklists;

use App\Exceptions\ChecklistException;
use App\Models\ChecklistModelo;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Checklist\ChecklistsDoDiaService;
use App\Support\Cozinha\Operador;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lista dos checklists do dia operacional, compartilhada pelos painéis
 * garçom e cozinha: em andamento → pendentes na janela → futuros →
 * encerrados; sob demanda em "Outros". O escopo vem do painel
 * (ChecklistModelo::paraPainel) e, na cozinha, do dispositivo.
 */
class ChecklistsDoDia extends Component
{
    #[Locked]
    public string $painel = ChecklistModelo::PAINEL_GARCOM;

    #[Locked]
    public ?Model $dispositivo = null;

    #[Locked]
    public ?Operador $operador = null;

    /**
     * Página (Filament) de execução do painel que hospeda; recebe o
     * parâmetro "execucao".
     *
     * @var class-string<Page>
     */
    #[Locked]
    public string $paginaExecucao;

    #[Locked]
    public ?string $urlHistorico = null;

    /** As props (painel, dispositivo, operador, urlHistorico) o Livewire atribui direto. */
    public function mount(string $paginaExecucao): void
    {
        if (! is_subclass_of($paginaExecucao, Page::class)) {
            throw new InvalidArgumentException("Página de execução inválida: {$paginaExecucao}.");
        }

        $this->paginaExecucao = $paginaExecucao;
    }

    public function render(): View
    {
        return view('livewire.checklists.checklists-do-dia');
    }

    /** @return array{hoje: list<array<string, mixed>>, outros: list<ChecklistModelo>} */
    #[Computed]
    public function checklists(): array
    {
        $user = $this->operador?->usuario() ?? Auth::user();

        return app(ChecklistsDoDiaService::class)->para($user, $this->painel, $this->dispositivo);
    }

    public function urlExecucao(int $execucaoId): string
    {
        return $this->paginaExecucao::getUrl(['execucao' => $execucaoId]);
    }

    public function iniciar(int $modeloId): void
    {
        $modelo = ChecklistModelo::query()->paraPainel($this->painel, $this->dispositivo)->findOrFail($modeloId);

        try {
            $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, Auth::user(), $this->operador);
        } catch (ChecklistException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->redirect($this->urlExecucao($execucao->id), navigate: true);
    }
}
