<x-filament-panels::page>
    <div wire:poll.{{ $this->intervaloPolling() }}="verificarVersao" class="w-full space-y-4">
        <div class="sticky top-0 z-20 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900/95 px-3 py-2 ring-1 ring-white/10 backdrop-blur">
            @include('filament.cozinha.partials.operador')

            <a
                href="{{ \App\Filament\Cozinha\Pages\Estacao::getUrl() }}"
                wire:navigate
                @class([
                    'ml-auto flex min-h-14 items-center rounded-xl px-5 text-base font-extrabold',
                    'bg-danger-600 text-white animate-pulse' => $this->novosNaFila > 0,
                    'bg-gray-800 text-gray-200 ring-1 ring-white/10' => $this->novosNaFila === 0,
                ])
            >
                Voltar à fila{{ $this->novosNaFila > 0 ? " ({$this->novosNaFila} novos)" : '' }}
            </a>
        </div>

        <livewire:checklists.execucao-checklist
            :execucao-id="$execucaoId"
            :url-lista="\App\Filament\Cozinha\Pages\ChecklistsCozinha::getUrl()"
            layout="tablet"
            :operador="$this->operador"
            :key="'execucao-'.$execucaoId.'-'.($this->operador?->userId ?? 'ninguem')" />
    </div>
</x-filament-panels::page>
