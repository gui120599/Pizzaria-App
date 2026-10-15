@php
    use App\Services\Ponto\EspelhoPontoService;

    $periodo = $this->periodoAtual;
    $espelho = $this->espelho;
    $horas = fn (int $minutos): string => EspelhoPontoService::horas($minutos);
@endphp

<x-filament-panels::page>
    <div class="mx-auto w-full max-w-2xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Período</div>
                <div class="font-semibold text-gray-950 dark:text-white">{{ $periodo->rotulo() }}
                    <x-filament::badge :color="$periodo->status->getColor()" class="ms-1 inline-flex">{{ $periodo->status->getLabel() }}</x-filament::badge>
                </div>
            </div>
            @if (count($this->opcoesPeriodo) > 1)
                <x-filament::input.wrapper class="w-full sm:w-64">
                    <x-filament::input.select wire:model.live="periodo">
                        <option value="">Período atual</option>
                        @foreach ($this->opcoesPeriodo as $id => $rotulo)
                            <option value="{{ $id }}">{{ $rotulo }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif
        </div>

        @if ($espelho['cienciaPendente'] !== [])
            <div class="rounded-xl bg-warning-50 px-4 py-3 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300">
                O gestor corrigiu {{ count($espelho['cienciaPendente']) }} dia(s) do seu ponto. Toque no dia destacado para ver o antes/depois e dar ciência.
            </div>
        @endif

        <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
            @foreach ([
                ['Trabalhado', $horas($espelho['totais']['trabalhado'])],
                ['Extra 50%', $horas($espelho['totais']['extra_50'])],
                ['Extra 100%', $horas($espelho['totais']['extra_100'])],
                ['Noturno', $horas($espelho['totais']['noturno'])],
                ['Atraso', $horas($espelho['totais']['atraso'])],
                ['Faltas', (string) $espelho['totais']['faltas']],
            ] as [$rotulo, $valor])
                <div class="rounded-xl bg-white px-3 py-2 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $rotulo }}</div>
                    <div class="font-semibold text-gray-950 dark:text-white">{{ $valor }}</div>
                </div>
            @endforeach
        </div>

        <div class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/5 dark:divide-white/5 dark:bg-gray-900 dark:ring-white/10">
            @forelse (array_reverse($espelho['dias']) as $dia)
                @php
                    $chave = $dia->toDateString();
                    $apurado = $espelho['celulas'][$chave] ?? null;
                    $corrigido = isset($espelho['cienciaPendente'][$chave]);
                    $pendentes = $espelho['solicitacoesPendentes'][$chave] ?? 0;
                @endphp
                <button type="button" wire:key="dia-{{ $chave }}" wire:click="mountAction('dia', { dia: '{{ $chave }}' })"
                    @class([
                        'flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-gray-50 dark:hover:bg-white/5',
                        'bg-warning-50/60 dark:bg-warning-500/10' => $corrigido,
                    ])>
                    <div class="min-w-0">
                        <div class="text-sm font-medium capitalize text-gray-950 dark:text-white">{{ $dia->translatedFormat('D, d/m') }}</div>
                        <div class="mt-0.5 flex flex-wrap gap-1">
                            @if ($apurado)
                                <x-filament::badge size="sm" :color="$apurado->situacao->getColor()">{{ $apurado->situacao->getLabel() }}</x-filament::badge>
                                @foreach ($apurado->rotulosInconsistencias() as $inconsistencia)
                                    <x-filament::badge size="sm" color="warning">{{ $inconsistencia }}</x-filament::badge>
                                @endforeach
                            @endif
                            @if ($corrigido)
                                <x-filament::badge size="sm" color="info" icon="heroicon-m-pencil">corrigido pelo gestor</x-filament::badge>
                            @endif
                            @if ($pendentes > 0)
                                <x-filament::badge size="sm" color="gray">pedido em análise</x-filament::badge>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 font-mono text-sm font-semibold text-gray-950 dark:text-white">{{ EspelhoPontoService::rotuloCelula($apurado) }}</div>
                </button>
            @empty
                <div class="px-4 py-6 text-center text-sm text-gray-400">Sem dias neste período ainda.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
