<x-filament-panels::page>
    <div class="w-full space-y-5">
        @include('filament.garcom.partials.sem-conexao')

        <livewire:checklists.execucao-checklist
            :execucao-id="$execucaoId"
            :url-lista="\App\Filament\Garcom\Pages\ChecklistsDoDia::getUrl()"
            layout="celular"
            :key="'execucao-'.$execucaoId" />
    </div>
</x-filament-panels::page>
