<?php

namespace App\Filament\Cozinha\Pages;

use App\Filament\Cozinha\Concerns\GerenciaOperadorCozinha;
use App\Models\ChecklistModelo;
use App\Models\DispositivoCozinha;
use App\Services\Checklist\ChecklistsDoDiaService;
use App\Services\Cozinha\OperadorCozinhaService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Checklists do dia no tablet (spec §6.8). A lista é o componente
 * compartilhado App\Livewire\Checklists\ChecklistsDoDia, no escopo do painel
 * cozinha e do tablet; os papéis que valem são os do operador.
 */
class ChecklistsCozinha extends Page
{
    use GerenciaOperadorCozinha;

    protected string $view = 'filament.cozinha.pages.checklists';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Checklists';

    protected static ?string $title = 'Checklists de hoje';

    protected static ?string $slug = 'checklists';

    protected static ?int $navigationSort = 3;

    protected Width|string|null $maxContentWidth = Width::Full;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getNavigationBadge(): ?string
    {
        $usuario = app(OperadorCozinhaService::class)->atual()?->usuario() ?? auth()->user();

        if ($usuario === null) {
            return null;
        }

        $pendentes = app(ChecklistsDoDiaService::class)->pendentesNaJanela($usuario, ChecklistModelo::PAINEL_COZINHA, DispositivoCozinha::atual());

        return $pendentes > 0 ? (string) $pendentes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
