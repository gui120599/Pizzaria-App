<x-filament-panels::page>
    <div class="w-full space-y-6">
        @include('filament.garcom.partials.sem-conexao')

        <livewire:checklists.checklists-do-dia
            painel="garcom"
            :pagina-execucao="\App\Filament\Garcom\Pages\ExecutarChecklist::class"
            :url-historico="\App\Filament\Garcom\Resources\ChecklistExecucoes\ChecklistExecucaoResource::getUrl('index')" />
    </div>
</x-filament-panels::page>
