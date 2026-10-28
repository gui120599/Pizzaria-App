<x-filament-panels::page>
    {{ $this->content }}

    <x-filament::section>
        <x-slot name="heading">Versões publicadas</x-slot>

        @forelse ($this->versoes() as $versao)
            <p class="text-sm">
                <strong>Versão {{ $versao['versao'] }}</strong> — {{ $versao['publicado_em'] }}
                <span class="text-gray-500">({{ $versao['aceites'] }} aceite(s))</span>
            </p>
        @empty
            <p class="text-sm text-gray-500">Nenhuma versão publicada ainda.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
