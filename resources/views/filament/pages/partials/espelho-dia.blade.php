@php
    use App\Enums\Ponto\StatusAjuste;
    use App\Enums\Ponto\TipoAjuste;
    use App\Services\Ponto\EspelhoPontoService;

    $apurado = $detalhe['apurado'];
    $escala = $detalhe['escala'];
    $horas = fn (?int $minutos): string => EspelhoPontoService::horas((int) $minutos);
    $argumentosDia = ['colaborador' => $colaborador->id, 'dia' => $dia->toDateString()];
@endphp

<div class="space-y-5 text-sm">
    <div class="flex flex-wrap items-center gap-2">
        @if ($apurado)
            <x-filament::badge :color="$apurado->situacao->getColor()" :icon="$apurado->situacao->getIcon()">{{ $apurado->situacao->getLabel() }}</x-filament::badge>
            @foreach ($apurado->rotulosInconsistencias() as $inconsistencia)
                <x-filament::badge color="warning">{{ $inconsistencia }}</x-filament::badge>
            @endforeach
        @else
            <x-filament::badge color="gray">Sem apuração</x-filament::badge>
        @endif
        <span class="text-gray-500 dark:text-gray-400">
            Escala:
            @if ($escala === null)
                sem escala
            @elseif ($escala->folga)
                folga
            @else
                {{ substr((string) $escala->entrada, 0, 5) }}–{{ substr((string) $escala->saida, 0, 5) }} ({{ $escala->intervalo_minutos }} min de intervalo)
            @endif
        </span>
    </div>

    <div>
        <h4 class="mb-2 font-semibold text-gray-950 dark:text-white">Marcações que valem</h4>
        <div class="divide-y divide-gray-100 rounded-lg ring-1 ring-gray-950/5 dark:divide-white/5 dark:ring-white/10">
            @forelse ($detalhe['efetivas'] as $efetiva)
                <div class="flex items-center justify-between gap-3 px-3 py-2">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-base font-semibold text-gray-950 dark:text-white">{{ $efetiva->momento->format('H:i') }}</span>
                        @if ($efetiva->momento->toDateString() !== $dia->toDateString())
                            <span class="text-xs text-gray-400">{{ $efetiva->momento->format('d/m') }}</span>
                        @endif
                        @if ($efetiva->corrigida())
                            <x-filament::badge color="info" icon="heroicon-m-pencil" :tooltip="'Incluída por '.($efetiva->inclusao->aprovadoPor?->name ?? '—').' em '.$efetiva->inclusao->decidido_em?->format('d/m H:i').' — '.$efetiva->inclusao->motivo->getLabel()">corrigida</x-filament::badge>
                        @else
                            <span class="text-xs text-gray-400">NSR {{ $efetiva->marcacao->nsr }} · {{ $efetiva->marcacao->origem->getLabel() }}</span>
                            @if ($efetiva->foraDaCerca())
                                <x-filament::badge color="warning" size="sm">fora da cerca</x-filament::badge>
                            @endif
                            @if ($efetiva->marcacao->latitude !== null)
                                <a href="https://www.google.com/maps?q={{ $efetiva->marcacao->latitude }},{{ $efetiva->marcacao->longitude }}" target="_blank" class="text-xs text-primary-600 hover:underline">mapa</a>
                            @endif
                        @endif
                    </div>
                    @if ($podeCorrigir && ! $efetiva->corrigida())
                        <div class="flex gap-2">
                            <x-filament::link tag="button" size="sm" wire:click="mountAction('corrigirHorario', { marcacao: {{ $efetiva->marcacao->id }} })">Corrigir horário</x-filament::link>
                            <x-filament::link tag="button" size="sm" color="danger" wire:click="mountAction('desconsiderar', { marcacao: {{ $efetiva->marcacao->id }} })">Desconsiderar</x-filament::link>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-3 py-4 text-center text-gray-400">Nenhuma marcação.</div>
            @endforelse
        </div>
        @foreach ($detalhe['desconsideradas'] as $original)
            <div class="mt-1 px-3 text-xs text-gray-400"><span class="line-through">{{ $original->registrada_em->format('H:i') }}</span> (NSR {{ $original->nsr }}) desconsiderada</div>
        @endforeach
    </div>

    @if ($apurado)
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
            @foreach ([
                ['Previsto', $horas($apurado->minutos_previstos)],
                ['Trabalhado', $horas($apurado->minutos_trabalhados)],
                ['Intervalo', $horas($apurado->minutos_intervalo)],
                ['Noturno', $horas($apurado->minutos_noturnos)],
                ['Extra', $horas($apurado->minutos_extra_50 + $apurado->minutos_extra_100)],
                ['Atraso', $horas($apurado->minutos_atraso)],
            ] as [$rotulo, $valor])
                <div class="rounded-lg bg-gray-50 px-2 py-1.5 dark:bg-white/5">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $rotulo }}</div>
                    <div class="font-semibold text-gray-950 dark:text-white">{{ $valor }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($detalhe['correcoes']->isNotEmpty())
        <div>
            <h4 class="mb-2 font-semibold text-gray-950 dark:text-white">Correções</h4>
            <div class="space-y-2">
                @foreach ($detalhe['correcoes'] as $lote => $ajustes)
                    @php($primeiro = $ajustes->first())
                    <div @class(['rounded-lg px-3 py-2 ring-1 ring-gray-950/5 dark:ring-white/10', 'opacity-60' => $primeiro->status === StatusAjuste::Desfeito])>
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <span class="font-medium text-gray-950 dark:text-white">{{ $ajustes->map(fn ($a) => $a->tipo->getLabel().($a->data_hora ? ' '.$a->data_hora->format('H:i') : '').($a->marcacao ? ' '.$a->marcacao->registrada_em->format('H:i') : ''))->join(' · ') }}</span>
                                <span class="text-xs text-gray-500">— {{ $primeiro->motivo->getLabel() }}, por {{ $primeiro->aprovadoPor?->name ?? '—' }} em {{ $primeiro->decidido_em?->format('d/m H:i') }}</span>
                            </div>
                            @if ($primeiro->status === StatusAjuste::Desfeito)
                                <x-filament::badge color="gray">desfeita por {{ $primeiro->desfeitoPor?->name }}</x-filament::badge>
                            @elseif ($podeCorrigir && str_contains($lote, '-') && ! str_starts_with($lote, 'ajuste-'))
                                <x-filament::link tag="button" size="sm" color="warning" wire:click="mountAction('desfazerCorrecao', { lote: '{{ $lote }}' })">Desfazer</x-filament::link>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $primeiro->justificativa }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($podeCorrigir)
        <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-white/10">
            <x-filament::button size="sm" icon="heroicon-m-plus" wire:click="mountAction('incluirMarcacao', {{ \Illuminate\Support\Js::from($argumentosDia) }})">Incluir marcação</x-filament::button>
            <x-filament::button size="sm" color="gray" icon="heroicon-m-calendar-days" wire:click="mountAction('lancarDia', {{ \Illuminate\Support\Js::from($argumentosDia) }})">Lançar dia completo</x-filament::button>
            <x-filament::button size="sm" color="gray" icon="heroicon-m-shield-check" wire:click="mountAction('abonar', {{ \Illuminate\Support\Js::from($argumentosDia) }})">Abonar</x-filament::button>
        </div>
    @else
        <p class="border-t border-gray-100 pt-3 text-xs text-gray-400 dark:border-white/10">Correções indisponíveis: período fechado, sem permissão ou é o seu próprio ponto.</p>
    @endif
</div>
