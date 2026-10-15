<?php

namespace App\Filament\Garcom\Pages;

use App\Models\ChecklistModelo;
use App\Services\Checklist\ChecklistsDoDiaService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Checklists do dia no /garcom. A lista é o componente compartilhado
 * App\Livewire\Checklists\ChecklistsDoDia, no escopo do painel garçom.
 */
class ChecklistsDoDia extends Page
{
    protected string $view = 'filament.garcom.pages.checklists-do-dia';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Checklists';

    protected static ?string $title = 'Checklists de hoje';

    protected static ?string $slug = 'checklists';

    protected static ?int $navigationSort = 2;

    /** Quem entra só para bater ponto não executa checklists. */
    public static function canAccess(): bool
    {
        return auth()->user()?->soBatePonto() === false;
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        $pendentes = app(ChecklistsDoDiaService::class)->pendentesNaJanela($user, ChecklistModelo::PAINEL_GARCOM);

        return $pendentes > 0 ? (string) $pendentes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
