@php
    use App\Enums\Checklist\ExigenciaFoto;
    use App\Enums\Checklist\TipoResposta;

    /** @var \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, \App\Models\ChecklistModeloItem>> $itensPorSecao */
@endphp

<div class="space-y-6">
    @forelse ($itensPorSecao as $secao => $itens)
        <section class="space-y-2">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $secao }}</h3>

            <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($itens as $item)
                    <li class="space-y-1 p-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $item->titulo }}</span>
                            <x-filament::badge color="gray" size="sm">{{ $item->tipo_resposta->getLabel() }}</x-filament::badge>
                            @if ($item->critico)
                                <x-filament::badge color="danger" size="sm">Crítico</x-filament::badge>
                            @endif
                            @unless ($item->obrigatorio)
                                <x-filament::badge color="gray" size="sm">Opcional</x-filament::badge>
                            @endunless
                            @if ($item->tipo_resposta !== TipoResposta::Foto && $item->exige_foto !== ExigenciaFoto::Nunca)
                                <x-filament::badge color="info" size="sm">Foto: {{ mb_strtolower($item->exige_foto->getLabel()) }}</x-filament::badge>
                            @endif
                        </div>

                        @if ($item->instrucao)
                            <x-checklist-instrucao :texto="$item->instrucao" />
                        @endif

                        @if ($item->tipo_resposta->usaFaixa() && ($item->valor_minimo !== null || $item->valor_maximo !== null))
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                Faixa aceitável:
                                {{ $item->valor_minimo !== null ? number_format((float) $item->valor_minimo, 1, ',', '.') : '—' }}
                                a
                                {{ $item->valor_maximo !== null ? number_format((float) $item->valor_maximo, 1, ',', '.') : '—' }}
                                {{ $item->unidade }}
                            </p>
                        @endif

                        @if ($item->tipo_resposta === TipoResposta::SimNao && $item->resposta_esperada)
                            <p class="text-sm text-gray-600 dark:text-gray-300">Resposta esperada: {{ $item->resposta_esperada === 'sim' ? 'Sim' : 'Não' }}</p>
                        @endif

                        @if ($item->dias_semana)
                            <p class="text-sm text-gray-600 dark:text-gray-300">Só em: {{ collect($item->dias_semana)->map(fn ($dia) => \App\Models\ChecklistModelo::DIAS_SEMANA[(int) $dia] ?? $dia)->implode(', ') }}</p>
                        @endif

                        @if ($item->tipo_resposta === TipoResposta::Producao)
                            @if ($item->criterio)
                                <p class="text-sm text-gray-600 dark:text-gray-300"><span class="font-semibold">Fazer quando:</span> {{ $item->criterio }}</p>
                            @endif
                            <div class="flex flex-wrap gap-1">
                                @foreach (\App\Enums\Checklist\RespostaProducao::cases() as $resposta)
                                    <x-filament::badge :color="$resposta->getColor()" :icon="$resposta->getIcon()" size="sm">{{ $resposta->getLabel() }}</x-filament::badge>
                                @endforeach
                            </div>
                            @if ($item->exige_foto_estoque)
                                <x-filament::badge color="info" size="sm">Foto do estoque</x-filament::badge>
                            @endif
                        @endif

                        @if ($item->tipo_resposta === TipoResposta::Selecao)
                            <div class="flex flex-wrap gap-1">
                                @foreach ($item->opcoes ?? [] as $opcao)
                                    <x-filament::badge :color="($opcao['conforme'] ?? false) ? 'success' : 'danger'" size="sm">{{ $opcao['rotulo'] }}</x-filament::badge>
                                @endforeach
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="text-sm text-gray-500">Este modelo ainda não tem itens.</p>
    @endforelse
</div>
