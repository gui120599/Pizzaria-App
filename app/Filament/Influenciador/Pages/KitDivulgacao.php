<?php

namespace App\Filament\Influenciador\Pages;

use App\Models\Cupom;
use App\Models\MaterialDivulgacao;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class KitDivulgacao extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Kit de Divulgação';

    protected static ?string $title = 'Kit de Divulgação';

    protected string $view = 'filament.influenciador.pages.kit-divulgacao';

    public ?int $cupomSelecionadoId = null;

    public function mount(): void
    {
        $this->cupomSelecionadoId = $this->cupons()->first()?->id;
    }

    public function materiais(): Collection
    {
        return MaterialDivulgacao::ativos()->get();
    }

    public function cupons(): Collection
    {
        return Cupom::where('cupom_influenciador_id', Auth::guard('influenciador')->id())
            ->where('cupom_ativo', true)
            ->get();
    }

    public function legendaPara(MaterialDivulgacao $material): string
    {
        if (! $material->material_conteudo_texto) {
            return '';
        }

        $codigo = $this->cupons()->firstWhere('id', $this->cupomSelecionadoId)?->cupom_codigo ?? '';

        return str_replace('{codigo}', $codigo, $material->material_conteudo_texto);
    }
}
