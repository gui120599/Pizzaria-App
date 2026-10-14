<x-filament-panels::page>
    <div class="w-full space-y-4">
        <div class="flex items-center justify-end">@include('filament.cozinha.partials.operador')</div>

        @if ($this->precisaEscolherOperador && $this->operador === null)
            <div class="rounded-xl bg-warning-500 px-4 py-3 text-base font-bold text-gray-950">
                Escolha quem está respondendo: sem operador, os checklists abrem só para leitura.
            </div>
        @endif

        <livewire:checklists.checklists-do-dia
            painel="cozinha"
            :dispositivo="\App\Models\DispositivoCozinha::atual()"
            :operador="$this->operador"
            :pagina-execucao="\App\Filament\Cozinha\Pages\ExecutarChecklistCozinha::class"
            :key="'checklists-cozinha-'.($this->operador?->userId ?? 'ninguem')" />
    </div>
</x-filament-panels::page>
