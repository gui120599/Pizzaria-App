@props([
    'code',
    'icon',
    'title',
    'message',
])

<x-filament-panels::layout.simple>
    <div class="fi-simple-layout-content flex flex-col items-center gap-y-4 text-center">
        <span class="flex h-16 w-16 items-center justify-center rounded-full bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-400">
            <x-filament::icon
                :icon="$icon"
                class="h-9 w-9"
            />
        </span>

        <div class="space-y-1">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ $code }}
            </p>

            <h1 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ $title }}
            </h1>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $message }}
            </p>
        </div>

        <x-filament::button
            :href="filament()->getHomeUrl()"
            tag="a"
            color="gray"
            icon="heroicon-o-arrow-uturn-left"
        >
            Voltar ao painel
        </x-filament::button>
    </div>
</x-filament-panels::layout.simple>
