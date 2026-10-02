<div class="space-y-5">
    @if (! $this->pinUsuarioId)
        <div class="grid grid-cols-3 gap-3">
            @foreach ($this->usuariosComPin as $usuario)
                <button type="button" wire:click="escolherUsuario({{ $usuario['id'] }})"
                        class="flex flex-col items-center gap-2 rounded-2xl p-3 ring-1 ring-gray-200 hover:bg-gray-50 active:scale-95 transition dark:ring-white/10 dark:hover:bg-white/5">
                    @if ($usuario['avatar'])
                        <img src="{{ $usuario['avatar'] }}" alt="" class="h-14 w-14 rounded-full object-cover">
                    @else
                        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-500 text-xl font-bold text-white">
                            {{ mb_strtoupper(mb_substr($usuario['nome'], 0, 1)) }}
                        </span>
                    @endif
                    <span class="text-sm font-medium text-gray-800 dark:text-gray-100 truncate max-w-full">{{ $usuario['nome'] }}</span>
                </button>
            @endforeach
        </div>
    @else
        @php $escolhido = collect($this->usuariosComPin)->firstWhere('id', $this->pinUsuarioId); @endphp

        <div x-data="{
                pin: @entangle('pin'),
                digitar(d) { if (this.pin.length < 6) { this.pin += d } },
                apagar() { this.pin = this.pin.slice(0, -1) },
             }"
             class="space-y-4">
            <div class="flex items-center justify-between">
                <span class="text-base font-semibold text-gray-900 dark:text-white">{{ $escolhido['nome'] ?? '' }}</span>
                <button type="button" wire:click="escolherUsuario(0)" class="text-sm text-primary-600 dark:text-primary-400">Trocar</button>
            </div>

            <div class="flex justify-center gap-3" aria-label="PIN digitado">
                <template x-for="i in 6">
                    <span class="h-4 w-4 rounded-full ring-1 ring-gray-400"
                          :class="pin.length >= i ? 'bg-gray-900 dark:bg-white' : ''"></span>
                </template>
            </div>

            <div class="grid grid-cols-3 gap-3">
                @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digito)
                    <button type="button" x-on:click="digitar('{{ $digito }}')"
                            class="h-16 rounded-2xl bg-gray-100 text-2xl font-semibold text-gray-900 active:scale-95 active:bg-gray-200 dark:bg-white/10 dark:text-white">
                        {{ $digito }}
                    </button>
                @endforeach
                <button type="button" x-on:click="apagar()"
                        class="h-16 rounded-2xl text-lg text-gray-600 active:scale-95 dark:text-gray-300">Apagar</button>
                <button type="button" x-on:click="digitar('0')"
                        class="h-16 rounded-2xl bg-gray-100 text-2xl font-semibold text-gray-900 active:scale-95 active:bg-gray-200 dark:bg-white/10 dark:text-white">0</button>
                <button type="button" wire:click="entrarComPin" wire:loading.attr="disabled" x-bind:disabled="pin.length < 4"
                        class="h-16 rounded-2xl bg-primary-600 text-lg font-semibold text-white active:scale-95 disabled:opacity-40">Entrar</button>
            </div>

            @error('pin')
                <p class="text-center text-sm text-danger-600">{{ $message }}</p>
            @enderror
        </div>
    @endif

    <button type="button" wire:click="usarModo('senha')" class="block w-full text-center text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400">
        Entrar com e-mail e senha
    </button>
</div>
