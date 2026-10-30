{{--
    Passos do pedido nos painéis (garçom): lê as etapas de
    App\Support\Pedido\EtapasAcompanhamento. `horizontal` cabe na rodada da mesa;
    `vertical` na retirada, onde os rótulos são mais longos.
--}}
@props(['etapas', 'orientacao' => 'horizontal'])

@php
    $total = count($etapas);
    $circulo = fn (string $estado) => match ($estado) {
        'concluida' => 'bg-emerald-600 text-white',
        'atual' => 'bg-primary-600 text-white ring-2 ring-primary-300 ring-offset-2 ring-offset-white dark:ring-primary-500/60 dark:ring-offset-gray-900',
        default => 'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-300',
    };
@endphp

@if ($orientacao === 'vertical')
    <ol {{ $attributes->class(['space-y-0']) }} aria-label="Andamento do pedido">
        @foreach ($etapas as $etapa)
            <li class="relative flex items-start gap-3 {{ $loop->last ? '' : 'pb-4' }}" @if ($etapa['estado'] === 'atual') aria-current="step" @endif>
                @unless ($loop->last)
                    <span class="absolute left-[13px] top-7 h-[calc(100%-1.25rem)] w-0.5 {{ $etapa['estado'] === 'concluida' ? 'bg-emerald-600' : 'bg-gray-200 dark:bg-white/10' }}" aria-hidden="true"></span>
                @endunless
                <span class="relative z-10 grid size-7 shrink-0 place-items-center rounded-full text-sm font-bold {{ $circulo($etapa['estado']) }}">{{ $etapa['estado'] === 'concluida' ? '✓' : $loop->iteration }}</span>
                <div class="flex min-w-0 flex-1 items-baseline justify-between gap-3 pt-0.5">
                    <span class="text-sm {{ $etapa['estado'] === 'pendente' ? 'text-gray-500 dark:text-gray-400' : 'font-semibold text-gray-900 dark:text-white' }}">{{ $etapa['rotulo'] }}</span>
                    @if ($etapa['hora'])
                        <time class="shrink-0 text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ $etapa['hora']->format('H:i') }}</time>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@else
    <ol {{ $attributes->class(['flex items-start']) }} aria-label="Andamento da rodada">
        @foreach ($etapas as $etapa)
            <li class="relative flex min-w-0 flex-1 flex-col items-center text-center" @if ($etapa['estado'] === 'atual') aria-current="step" @endif>
                @unless ($loop->last)
                    <span class="absolute left-[calc(50%+18px)] right-[calc(-50%+18px)] top-[13px] h-0.5 {{ $etapa['estado'] === 'concluida' ? 'bg-emerald-600' : 'bg-gray-200 dark:bg-white/10' }}" aria-hidden="true"></span>
                @endunless
                <span class="relative z-10 grid size-7 place-items-center rounded-full text-sm font-bold {{ $circulo($etapa['estado']) }}">{{ $etapa['estado'] === 'concluida' ? '✓' : $loop->iteration }}</span>
                <span class="mt-1 text-xs leading-tight {{ $etapa['estado'] === 'pendente' ? 'text-gray-500 dark:text-gray-400' : 'font-semibold text-gray-900 dark:text-white' }}">{{ $etapa['rotulo'] }}</span>
                @if ($etapa['hora'])
                    <time class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $etapa['hora']->format('H:i') }}</time>
                @endif
            </li>
        @endforeach
    </ol>
@endif
