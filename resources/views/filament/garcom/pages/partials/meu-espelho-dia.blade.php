@php
    use App\Enums\Ponto\OrigemAjuste;
    use App\Enums\Ponto\StatusAjuste;
    use App\Services\Ponto\EspelhoPontoService;

    $apurado = $detalhe['apurado'];
    $escala = $detalhe['escala'];
    $horas = fn (?int $minutos): string => EspelhoPontoService::horas((int) $minutos);
    $correcoesDoGestor = $detalhe['correcoes']->filter(fn ($ajustes) => $ajustes->first()->origem === OrigemAjuste::Gestor);
@endphp

<div class="space-y-4 text-sm">
    <div class="flex flex-wrap items-center gap-2">
        @if ($apurado)
            <x-filament::badge :color="$apurado->situacao->getColor()">{{ $apurado->situacao->getLabel() }}</x-filament::badge>
        @endif
        <span class="text-gray-500 dark:text-gray-400">
            Escala:
            @if ($escala === null)
                sem escala
            @elseif ($escala->folga)
                folga
            @else
                {{ substr((string) $escala->entrada, 0, 5) }}–{{ substr((string) $escala->saida, 0, 5) }}
            @endif
        </span>
    </div>

    <div>
        <h4 class="mb-2 font-semibold text-gray-950 dark:text-white">Marcações que valem</h4>
        <div class="divide-y divide-gray-100 rounded-lg ring-1 ring-gray-950/5 dark:divide-white/5 dark:ring-white/10">
            @forelse ($detalhe['efetivas'] as $efetiva)
                <div class="flex items-center justify-between gap-2 px-3 py-2">
                    <span class="font-mono text-base font-semibold text-gray-950 dark:text-white">{{ $efetiva->momento->format('H:i') }}</span>
                    @if ($efetiva->corrigida())
                        <x-filament::badge size="sm" color="info" icon="heroicon-m-pencil">incluída por {{ $efetiva->inclusao->aprovadoPor?->name ?? 'gestor' }}</x-filament::badge>
                    @else
                        <span class="text-xs text-gray-400">NSR {{ $efetiva->marcacao->nsr }}</span>
                    @endif
                </div>
            @empty
                <div class="px-3 py-3 text-center text-gray-400">Nenhuma marcação.</div>
            @endforelse
        </div>
        @foreach ($detalhe['desconsideradas'] as $original)
            <div class="mt-1 px-3 text-xs text-gray-500"><span class="line-through">{{ $original->registrada_em->format('H:i') }}</span> desconsiderada</div>
        @endforeach
    </div>

    @if ($apurado)
        <div class="grid grid-cols-3 gap-2">
            @foreach ([['Trabalhado', $apurado->minutos_trabalhados], ['Extra', $apurado->minutos_extra_50 + $apurado->minutos_extra_100], ['Atraso', $apurado->minutos_atraso]] as [$rotulo, $minutos])
                <div class="rounded-lg bg-gray-50 px-2 py-1.5 dark:bg-white/5">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $rotulo }}</div>
                    <div class="font-semibold text-gray-950 dark:text-white">{{ $horas($minutos) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($correcoesDoGestor->isNotEmpty())
        <div>
            <h4 class="mb-2 font-semibold text-gray-950 dark:text-white">Correções do gestor</h4>
            <div class="space-y-2">
                @foreach ($correcoesDoGestor as $ajustes)
                    @php($primeiro = $ajustes->first())
                    <div @class(['rounded-lg px-3 py-2 ring-1 ring-gray-950/5 dark:ring-white/10', 'opacity-60' => $primeiro->status === StatusAjuste::Desfeito])>
                        <div class="font-medium text-gray-950 dark:text-white">
                            {{ $ajustes->map(fn ($a) => $a->tipo->getLabel().($a->data_hora ? ' '.$a->data_hora->format('H:i') : '').($a->marcacao ? ' '.$a->marcacao->registrada_em->format('H:i') : ''))->join(' · ') }}
                        </div>
                        <div class="text-xs text-gray-500">{{ $primeiro->motivo->getLabel() }} — {{ $primeiro->aprovadoPor?->name ?? '—' }}, {{ $primeiro->decidido_em?->format('d/m H:i') }}
                            @if ($primeiro->status === StatusAjuste::Desfeito) · desfeita @endif
                            @if ($primeiro->colaborador_ciente_em) · ciente em {{ $primeiro->colaborador_ciente_em->format('d/m H:i') }} @endif
                        </div>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $primeiro->justificativa }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($solicitacoes->isNotEmpty())
        <div>
            <h4 class="mb-2 font-semibold text-gray-950 dark:text-white">Meus pedidos</h4>
            <div class="space-y-1">
                @foreach ($solicitacoes as $solicitacao)
                    <div class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 ring-1 ring-gray-950/5 dark:ring-white/10">
                        <span>{{ $solicitacao->tipo->getLabel() }}{{ $solicitacao->data_hora ? ' '.$solicitacao->data_hora->format('H:i') : '' }}
                            @if ($solicitacao->motivo_rejeicao)<span class="block text-xs text-danger-600">{{ $solicitacao->motivo_rejeicao }}</span>@endif
                        </span>
                        <x-filament::badge size="sm" :color="$solicitacao->status->getColor()">{{ $solicitacao->status->getLabel() }}</x-filament::badge>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-white/10">
        @if ($cienciaPendente)
            <x-filament::button size="sm" icon="heroicon-m-check" wire:click="mountAction('ciente', { dia: '{{ $dia->toDateString() }}' })">Ciente</x-filament::button>
        @endif
        @if ($podeSolicitar)
            @if ($correcoesDoGestor->isNotEmpty())
                <x-filament::button size="sm" color="warning" wire:click="mountAction('solicitarAjuste', { dia: '{{ $dia->toDateString() }}', contestar: true })">Contestar</x-filament::button>
            @endif
            <x-filament::button size="sm" color="gray" icon="heroicon-m-pencil-square" wire:click="mountAction('solicitarAjuste', { dia: '{{ $dia->toDateString() }}' })">Solicitar ajuste</x-filament::button>
        @else
            <p class="text-xs text-gray-400">Período em conferência ou fechado: fale com o gestor.</p>
        @endif
    </div>
</div>
