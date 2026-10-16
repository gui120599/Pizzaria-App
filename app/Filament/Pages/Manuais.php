<?php

namespace App\Filament\Pages;

use App\Support\CatalogoManuais;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Manuais em PDF (docs/) para consulta da equipe. Aberta a todo usuário do
 * painel — excluída do Shield em config/filament-shield.php.
 */
class Manuais extends Page
{
    protected static ?string $title = 'Manuais';

    protected static ?string $navigationLabel = 'Manuais';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static UnitEnum|string|null $navigationGroup = 'Ajuda';

    protected string $view = 'filament.pages.manuais';

    /**
     * @return Collection<int, array{arquivo: string, titulo: string, descricao: string, tamanho: int}>
     */
    #[Computed]
    public function manuais(): Collection
    {
        return CatalogoManuais::todos();
    }
}
