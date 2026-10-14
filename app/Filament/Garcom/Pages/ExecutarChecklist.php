<?php

namespace App\Filament\Garcom\Pages;

use App\Models\ChecklistExecucao;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Execução do checklist no celular. A tela em si é o componente
 * compartilhado App\Livewire\Checklists\ExecucaoChecklist (layout
 * "celular"); esta página só autoriza e hospeda.
 */
class ExecutarChecklist extends Page
{
    protected string $view = 'filament.garcom.pages.executar-checklist';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'checklists/{execucao}';

    #[Locked]
    public int $execucaoId;

    #[Locked]
    public string $titulo;

    public function mount(int|string $execucao): void
    {
        $registro = ChecklistExecucao::findOrFail($execucao);

        abort_unless(Gate::allows('executar', $registro) || Gate::allows('view', $registro), 403);

        $this->execucaoId = $registro->id;
        $this->titulo = $registro->modelo_nome;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->titulo;
    }
}
