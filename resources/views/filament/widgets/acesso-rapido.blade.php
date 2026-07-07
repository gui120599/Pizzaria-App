<x-filament-widgets::widget>
    <div class="space-y-6">
        <a
            href="{{ route('dashboard') }}"
            class="flex items-center gap-3 rounded-xl bg-primary-600 px-5 py-4 text-white shadow-sm transition-colors hover:bg-primary-500"
        >
            <x-filament::icon
                icon="heroicon-o-computer-desktop"
                class="h-8 w-8 shrink-0"
            />

            <div>
                <p class="text-base font-semibold">Sistema Operacional</p>
                <p class="text-sm text-white/80">Abrir o PDV para vendas, pedidos e caixa</p>
            </div>
        </a>

        <div class="space-y-6">
            @foreach ($this->getModulos() as $modulo)
                <x-filament::section
                    :heading="$modulo['grupo']"
                    :icon="$modulo['icon']"
                >
                    {{-- Lista na horizontal: cards em uma única linha, com rolagem quando não couber --}}
                    <div class="flex gap-3 overflow-x-auto pb-1">
                        @foreach ($modulo['itens'] as $item)
                            <a
                                href="{{ $item['url'] }}"
                                class="group flex w-56 shrink-0 items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 transition-all hover:border-primary-500/40 hover:shadow-md dark:border-white/10 dark:bg-white/5 dark:hover:border-primary-500/40"
                            >
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 transition-colors group-hover:bg-primary-600 group-hover:text-white dark:bg-primary-500/10 dark:text-primary-400">
                                    <x-filament::icon
                                        :icon="$item['icon']"
                                        class="h-6 w-6"
                                    />
                                </span>

                                <span class="text-sm font-medium leading-tight text-gray-700 dark:text-gray-200">
                                    {{ $item['label'] }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    </div>
</x-filament-widgets::widget>
