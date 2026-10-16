<x-filament-panels::page>
    @if ($this->manuais->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum manual disponível.</p>
        </x-filament::section>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->manuais as $manual)
                <x-filament::section wire:key="manual-{{ $manual['arquivo'] }}">
                    <div class="flex h-full flex-col gap-3">
                        <div class="flex items-start gap-3">
                            <x-filament::icon
                                :icon="\Filament\Support\Icons\Heroicon::OutlinedDocumentText"
                                class="h-8 w-8 shrink-0 text-primary-600 dark:text-primary-400"
                            />
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $manual['titulo'] }}</h3>
                                @if ($manual['descricao'])
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $manual['descricao'] }}</p>
                                @endif
                                <p class="mt-1 text-xs text-gray-400">PDF · {{ \Illuminate\Support\Number::fileSize($manual['tamanho'], precision: 1) }}</p>
                            </div>
                        </div>

                        <div class="mt-auto flex flex-wrap gap-2">
                            <x-filament::button
                                tag="a"
                                :href="route('filament.admin.manuais.arquivo', ['arquivo' => $manual['arquivo']])"
                                target="_blank"
                                icon="heroicon-o-eye"
                                size="sm"
                            >
                                Abrir
                            </x-filament::button>

                            <x-filament::button
                                tag="a"
                                :href="route('filament.admin.manuais.arquivo', ['arquivo' => $manual['arquivo'], 'baixar' => 1])"
                                icon="heroicon-o-arrow-down-tray"
                                color="gray"
                                size="sm"
                            >
                                Baixar
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
