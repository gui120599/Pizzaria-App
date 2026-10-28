<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Saldo disponível</x-slot>

        <p class="text-3xl font-bold {{ $this->saldo() < 0 ? 'text-danger-600' : 'text-success-600' }}">
            R$ {{ number_format($this->saldo(), 2, ',', '.') }}
        </p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Para receber em Pix, fale com a equipe. Para consumir em pizza, gere um código e informe no caixa.
        </p>

        <div class="mt-4">
            {{ $this->gerarCodigoAction }}
        </div>

        @if ($codigoGerado)
            <div class="mt-4 rounded-lg border border-success-300 bg-success-50 p-4 dark:border-success-500/30 dark:bg-success-500/10">
                <p class="text-sm text-gray-600 dark:text-gray-300">Seu código de resgate (vale até {{ $codigoExpiraEm }}, uso único):</p>
                <p class="mt-1 text-4xl font-mono font-bold tracking-widest">{{ $codigoGerado }}</p>
            </div>
        @endif
    </x-filament::section>

    {{ $this->table }}

    <x-filament-actions::modals />
</x-filament-panels::page>
