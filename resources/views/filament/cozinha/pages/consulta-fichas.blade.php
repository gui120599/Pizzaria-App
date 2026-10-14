{{-- Consulta de fichas (spec §6.7). --}}
<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex flex-wrap gap-2">
            <input
                type="search"
                wire:model.live.debounce.400ms="busca"
                placeholder="Buscar produto ou sabor..."
                class="min-h-14 min-w-0 flex-1 rounded-xl border-0 bg-gray-900 px-4 text-lg text-white ring-1 ring-white/10 placeholder:text-gray-500 focus:ring-primary-500"
            >
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="$set('categoriaId', null)" @class(['rounded-full px-4 py-2 text-sm font-bold', 'bg-primary-600 text-white' => $categoriaId === null, 'bg-gray-800 text-gray-300' => $categoriaId !== null])>Todos</button>
            @foreach ($this->categorias as $id => $nome)
                <button type="button" wire:click="$set('categoriaId', {{ $id }})" @class(['rounded-full px-4 py-2 text-sm font-bold', 'bg-primary-600 text-white' => $categoriaId === $id, 'bg-gray-800 text-gray-300' => $categoriaId !== $id])>{{ $nome }}</button>
            @endforeach
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            @forelse ($this->fichas as $ficha)
                <button
                    type="button"
                    wire:key="ficha-{{ $ficha->id }}"
                    wire:click="mountAction('ficha', { produto: {{ $ficha->produto_id }} })"
                    class="flex min-h-20 items-center gap-3 rounded-xl bg-gray-900 p-3 text-left ring-1 ring-white/10 active:scale-[.99]"
                >
                    @if ($ficha->produto?->produto_foto)
                        <img src="{{ asset('storage/'.$ficha->produto->produto_foto) }}" alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover" loading="lazy">
                    @endif
                    <span class="min-w-0">
                        <span class="block truncate text-lg font-extrabold text-white">{{ $ficha->produto?->produto_descricao }}</span>
                        <span class="block truncate text-sm text-gray-400">{{ $ficha->produto?->categoria?->categoria_nome }}</span>
                    </span>
                </button>
            @empty
                <p class="col-span-full py-12 text-center text-lg text-gray-500">Nenhuma ficha encontrada.</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
