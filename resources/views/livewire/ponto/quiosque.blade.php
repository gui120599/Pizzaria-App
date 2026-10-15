{{--
    Relógio: o horário vem do servidor (agoraMs) e o Alpine só soma o tempo
    decorrido no aparelho, então o relógio errado do tablet não aparece.
    A marcação em si sempre usa now() do servidor.
--}}
<div class="mx-auto flex min-h-screen max-w-md flex-col px-4 py-6"
    x-data="{
        offset: {{ $agoraMs }} - Date.now(),
        agora: new Date({{ $agoraMs }}),
        init() { setInterval(() => this.agora = new Date(Date.now() + this.offset), 1000) },
        hora() { return this.agora.toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo' }) },
        data() { return this.agora.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo', weekday: 'long', day: '2-digit', month: 'long' }) },
    }">
    <header class="mb-6 text-center">
        <div class="font-mono text-5xl font-bold tabular-nums" x-text="hora()">{{ now()->format('H:i:s') }}</div>
        <div class="mt-1 text-sm text-gray-400 first-letter:uppercase" x-text="data()"></div>
    </header>

    @if ($comprovante)
        {{-- Comprovante: volta sozinho ao teclado depois de alguns segundos. --}}
        <div class="flex flex-1 flex-col items-center justify-center text-center" x-init="setTimeout(() => $wire.reiniciar(), 8000)">
            <div class="mb-4 flex h-20 w-20 items-center justify-center rounded-full bg-green-600 text-5xl">✓</div>
            <div class="text-2xl font-bold">{{ $comprovante['tipo'] }} registrada</div>
            <div class="mt-1 text-lg text-gray-300">{{ $comprovante['nome'] }}</div>
            <dl class="mt-6 w-full space-y-1 rounded-xl bg-white/5 p-4 text-left text-sm">
                <div class="flex justify-between"><dt class="text-gray-400">Data e hora</dt><dd class="font-mono">{{ $comprovante['data_hora'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-400">NSR</dt><dd class="font-mono">{{ $comprovante['nsr'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-400">Código</dt><dd class="font-mono">{{ $comprovante['hash'] }}</dd></div>
            </dl>
            <button type="button" wire:click="reiniciar" class="mt-8 w-full rounded-xl bg-white/10 py-4 text-lg font-semibold active:bg-white/20">Concluir</button>
        </div>
    @elseif ($colaborador)
        <div class="flex flex-1 flex-col items-center justify-center text-center"
            x-init="setTimeout(() => $wire.reiniciar('Tempo esgotado. Digite o PIN de novo.'), {{ (int) config('ponto.quiosque.expira_identificacao_s', 30) * 1000 }})">
            <div class="text-sm uppercase tracking-wide text-gray-400">Olá,</div>
            <div class="mt-1 text-3xl font-bold">{{ $colaborador->nome }}</div>
            <div class="mt-8 text-sm text-gray-400">Registrar agora:</div>
            <button type="button" wire:click="confirmar" wire:loading.attr="disabled"
                class="mt-2 w-full rounded-2xl bg-orange-600 py-8 text-3xl font-bold shadow-lg active:bg-orange-700 disabled:opacity-60">
                {{ $proximoTipo->getLabel() }}
            </button>
            <button type="button" wire:click="reiniciar" class="mt-4 w-full rounded-xl bg-white/10 py-4 text-lg active:bg-white/20">Não sou eu</button>
        </div>
    @else
        <div class="flex flex-1 flex-col">
            <div class="mb-2 text-center text-gray-300">Digite seu PIN</div>
            <div class="mb-3 flex justify-center gap-3" aria-live="polite">
                @for ($i = 0; $i < $tamanhoPin; $i++)
                    <span @class(['h-4 w-4 rounded-full', 'bg-white' => $i < strlen($pin), 'bg-white/20' => $i >= strlen($pin)])></span>
                @endfor
            </div>
            <div class="mb-3 min-h-6 text-center text-sm text-red-400" role="alert">{{ $erro }}</div>

            <div class="grid grid-cols-3 gap-3">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9'] as $digito)
                    <button type="button" wire:click="digitar('{{ $digito }}')" class="rounded-2xl bg-white/10 py-6 text-3xl font-semibold active:bg-white/25">{{ $digito }}</button>
                @endforeach
                <button type="button" wire:click="apagar" class="rounded-2xl bg-white/5 py-6 text-lg active:bg-white/20" aria-label="Apagar">⌫</button>
                <button type="button" wire:click="digitar('0')" class="rounded-2xl bg-white/10 py-6 text-3xl font-semibold active:bg-white/25">0</button>
                <button type="button" wire:click="identificar" wire:loading.attr="disabled" @disabled(strlen($pin) < 4)
                    class="rounded-2xl bg-orange-600 py-6 text-lg font-bold active:bg-orange-700 disabled:opacity-40">OK</button>
            </div>
        </div>
    @endif
</div>
