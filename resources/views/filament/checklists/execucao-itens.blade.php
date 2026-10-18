@php
    /** @var \App\Models\ChecklistExecucao $execucao */
    $execucao = $getRecord();
    $itensPorSecao = $execucao->itens()
        ->with(['anexos', 'respondidoPor'])
        ->get()
        ->groupBy(fn ($item): string => $item->secao_titulo ?? 'Sem seção');
@endphp

<div class="space-y-6">
    @foreach ($itensPorSecao as $secao => $itens)
        <section class="space-y-2">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $secao }}</h3>

            <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($itens as $item)
                    <li class="flex gap-3 p-3">
                        <div class="pt-0.5">
                            @if ($item->foiDispensado())
                                <x-filament::icon icon="heroicon-s-archive-box" class="h-5 w-5 text-info-600 dark:text-info-400" label="Tinha estoque" />
                            @elseif ($item->conforme === true)
                                <x-filament::icon icon="heroicon-s-check-circle" class="h-5 w-5 text-success-600 dark:text-success-400" label="Conforme" />
                            @elseif ($item->conforme === false)
                                <x-filament::icon icon="heroicon-s-x-circle" class="h-5 w-5 text-danger-600 dark:text-danger-400" label="Não conforme" />
                            @else
                                <x-filament::icon icon="heroicon-o-minus-circle" class="h-5 w-5 text-gray-400" label="Sem resposta" />
                            @endif
                        </div>

                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                <span class="font-medium text-gray-950 dark:text-white">
                                    {{ $item->titulo }}
                                    @if ($item->critico())
                                        <x-filament::badge color="danger" size="sm" class="ms-1 inline-flex">Crítico</x-filament::badge>
                                    @endif
                                </span>
                                <span @class([
                                    'text-sm font-semibold',
                                    'text-danger-600 dark:text-danger-400' => $item->conforme === false,
                                    'text-info-600 dark:text-info-400' => $item->foiDispensado(),
                                    'text-gray-950 dark:text-white' => $item->conforme !== false && ! $item->foiDispensado(),
                                ])>{{ $item->respostaFormatada() ?? 'Sem resposta' }}</span>
                            </div>

                            @if ($item->observacao)
                                <p class="text-sm text-gray-600 dark:text-gray-300">Obs.: {{ $item->observacao }}</p>
                            @endif

                            @if ($item->anexos->isNotEmpty())
                                <div class="flex flex-wrap gap-2 pt-1">
                                    @foreach ($item->anexos as $anexo)
                                        <a href="{{ $anexo->url() }}" target="_blank" rel="noopener" class="block">
                                            <img src="{{ $anexo->url() }}" alt="Foto" class="h-16 w-16 overflow-hidden rounded-md bg-gray-100 object-cover text-[0px] dark:bg-white/5 ring-1 ring-gray-200 dark:ring-white/10" loading="lazy">
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            @if ($item->foiRespondido())
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $item->respondidoPor?->name ?? 'Usuário removido' }} · {{ $item->respondido_em->format('d/m/Y H:i') }}
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
