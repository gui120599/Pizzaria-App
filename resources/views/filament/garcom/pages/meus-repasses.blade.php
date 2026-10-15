@php
    use App\Enums\Ponto\StatusRepasse;

    $dinheiro = fn ($valor): string => 'R$ '.number_format((float) $valor, 2, ',', '.');
@endphp

<x-filament-panels::page>
    <div class="mx-auto w-full max-w-2xl space-y-3">
        @forelse ($this->repasses as $repasse)
            <div wire:key="repasse-{{ $repasse->id }}" class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold text-gray-950 dark:text-white">{{ $repasse->periodo->rotulo() }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Proventos {{ $dinheiro($repasse->total_proventos) }} · Descontos {{ $dinheiro($repasse->total_descontos) }}
                        </div>
                    </div>
                    <x-filament::badge :color="$repasse->status->getColor()">{{ $repasse->status->getLabel() }}</x-filament::badge>
                </div>
                <div class="mt-2 text-2xl font-bold text-gray-950 dark:text-white">{{ $dinheiro($repasse->valor_liquido) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($repasse->status === StatusRepasse::Pago)
                        Pago em {{ $repasse->pago_em?->format('d/m/Y') }}
                    @elseif ($repasse->lancamento?->vencimento)
                        Previsto para {{ $repasse->lancamento->vencimento->format('d/m/Y') }}
                    @endif
                    @if ($repasse->ciente_em)
                        · ciente em {{ $repasse->ciente_em->format('d/m/Y H:i') }}
                    @endif
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-list-bullet" wire:click="mountAction('itens', { repasse: {{ $repasse->id }} })">Itens</x-filament::button>
                    <x-filament::button size="sm" color="gray" icon="heroicon-m-printer" tag="a" :href="route('ponto.repasses.recibo', $repasse)" target="_blank">Recibo</x-filament::button>
                    @if ($repasse->ciente_em === null)
                        <x-filament::button size="sm" icon="heroicon-m-check-badge" wire:click="mountAction('ciencia', { repasse: {{ $repasse->id }} })">Li e confere</x-filament::button>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-xl bg-white px-4 py-8 text-center text-sm text-gray-400 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                Nenhum repasse aprovado ainda.
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
