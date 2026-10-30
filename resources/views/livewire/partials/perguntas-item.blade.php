{{--
    Perguntas do item no seletor (modal simples e de sabores): escolha única
    vira "rádio", múltipla vira "checkbox" até o máximo. Obrigatória leva o
    selo; o servidor recusa o item se faltar resposta.
--}}
@foreach ($perguntasDisponiveis as $pergunta)
    @php
        $marcadas = array_map('intval', $respostas[$pergunta['id']] ?? []);
        $obrigatoria = $pergunta['minimo'] > 0;
        $faltando = $obrigatoria && count($marcadas) < $pergunta['minimo'];
    @endphp
    <div wire:key="pergunta-{{ $pergunta['id'] }}">
        <div class="flex items-center justify-between gap-2 mb-1.5">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $pergunta['texto'] }}</span>
            <span @class([
                'text-[10px] font-bold uppercase tracking-wide rounded px-1.5 py-0.5 shrink-0',
                'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $faltando,
                'bg-primary-100 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $obrigatoria && ! $faltando,
                'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $obrigatoria,
            ])>
                {{ $obrigatoria ? 'Obrigatório' : 'Opcional' }}{{ $pergunta['maximo'] > 1 ? ' · até '.$pergunta['maximo'] : '' }}
            </span>
        </div>
        <div class="space-y-1.5">
            @foreach ($pergunta['opcoes'] as $opcao)
                @php $marcada = in_array($opcao['id'], $marcadas, true); @endphp
                <button type="button"
                    wire:key="pergunta-{{ $pergunta['id'] }}-opcao-{{ $opcao['id'] }}"
                    wire:click="alternarResposta({{ $pergunta['id'] }}, {{ $opcao['id'] }})"
                    @class([
                        'w-full flex items-center justify-between gap-3 p-2.5 rounded-xl border text-left transition-colors',
                        'border-primary-400 dark:border-primary-500/50 bg-primary-50 dark:bg-primary-500/10' => $marcada,
                        'border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 hover:border-primary-300 dark:hover:border-primary-500/30' => ! $marcada,
                    ])>
                    <span class="flex items-center gap-2.5 min-w-0">
                        <span @class([
                            'w-4 h-4 shrink-0 border flex items-center justify-center',
                            'rounded-full' => $pergunta['maximo'] <= 1,
                            'rounded' => $pergunta['maximo'] > 1,
                            'border-primary-600 bg-primary-600' => $marcada,
                            'border-gray-300 dark:border-white/20' => ! $marcada,
                        ])>
                            @if ($marcada)
                                <span class="w-1.5 h-1.5 rounded-full bg-white"></span>
                            @endif
                        </span>
                        <span class="text-sm text-gray-700 dark:text-gray-300 truncate">{{ $opcao['nome'] }}</span>
                    </span>
                    @if ($opcao['valor'] > 0)
                        <span class="text-sm font-semibold text-green-600 dark:text-green-400 shrink-0">+ R$ {{ number_format($opcao['valor'], 2, ',', '.') }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>
@endforeach
