<?php

namespace App\Filament\Garcom\Pages;

use App\Exceptions\ChecklistException;
use App\Models\ChecklistModelo;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Checklist\ChecklistsDoDiaService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Checklists do dia operacional para os papéis do usuário: em andamento →
 * pendentes na janela → futuros → encerrados; sob demanda em "Outros".
 */
class ChecklistsDoDia extends Page
{
    protected string $view = 'filament.garcom.pages.checklists-do-dia';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Checklists';

    protected static ?string $title = 'Checklists de hoje';

    protected static ?string $slug = 'checklists';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        $pendentes = app(ChecklistsDoDiaService::class)->pendentesNaJanela($user);

        return $pendentes > 0 ? (string) $pendentes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return array{hoje: list<array<string, mixed>>, outros: list<ChecklistModelo>} */
    #[Computed]
    public function checklists(): array
    {
        return app(ChecklistsDoDiaService::class)->para(Auth::user());
    }

    public function iniciar(int $modeloId): void
    {
        $modelo = ChecklistModelo::findOrFail($modeloId);

        try {
            $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, Auth::user());
        } catch (ChecklistException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->redirect(ExecutarChecklist::getUrl(['execucao' => $execucao->id]), navigate: true);
    }
}
