<x-filament-panels::page>
    @php $regulamento = $this->regulamento(); @endphp

    <x-filament::section>
        <x-slot name="heading">Regulamento do programa de influenciadores (versão {{ $regulamento?->ri_versao }})</x-slot>

        <div class="prose max-w-none whitespace-pre-line dark:prose-invert">{{ $regulamento?->ri_texto }}</div>

        <label class="mt-6 flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="aceito" class="rounded border-gray-300" />
            Li e aceito o regulamento.
        </label>

        <div class="mt-4">
            <x-filament::button wire:click="aceitar">Aceitar e continuar</x-filament::button>
        </div>
    </x-filament::section>
</x-filament-panels::page>
