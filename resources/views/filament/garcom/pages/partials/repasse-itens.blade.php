@php
    use App\Enums\Ponto\TipoItemRepasse;
    use App\Services\Ponto\EspelhoPontoService;

    $dinheiro = fn ($valor): string => 'R$ '.number_format((float) $valor, 2, ',', '.');
    $jornada = $repasse->resumo_jornada ?? [];
@endphp

<div class="space-y-4 text-sm">
    @if ($jornada !== [])
        <div class="grid grid-cols-3 gap-2">
            @foreach ([
                ['Trabalhado', EspelhoPontoService::horas((int) ($jornada['trabalhado'] ?? 0))],
                ['Extras', EspelhoPontoService::horas((int) ($jornada['extra_50'] ?? 0) + (int) ($jornada['extra_100'] ?? 0))],
                ['Faltas', (string) ($jornada['faltas'] ?? 0)],
            ] as [$rotulo, $valor])
                <div class="rounded-lg bg-gray-50 px-2 py-1.5 dark:bg-white/5">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $rotulo }}</div>
                    <div class="font-semibold text-gray-950 dark:text-white">{{ $valor }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="divide-y divide-gray-100 rounded-lg ring-1 ring-gray-950/5 dark:divide-white/5 dark:ring-white/10">
        @foreach ($repasse->itens->sortBy(fn ($item) => $item->tipo === TipoItemRepasse::Desconto ? 1 : 0) as $item)
            <div class="flex items-center justify-between gap-3 px-3 py-2">
                <div class="min-w-0">
                    <div class="text-gray-950 dark:text-white">{{ $item->descricao }}</div>
                    @if ((float) $item->quantidade !== 1.0 && $item->valor_unitario !== null)
                        <div class="text-xs text-gray-500">{{ rtrim(rtrim(number_format((float) $item->quantidade, 2, ',', '.'), '0'), ',') }} × {{ $dinheiro($item->valor_unitario) }}</div>
                    @endif
                </div>
                <div @class(['shrink-0 font-mono', 'text-danger-600' => $item->tipo === TipoItemRepasse::Desconto, 'text-gray-950 dark:text-white' => $item->tipo !== TipoItemRepasse::Desconto])>
                    {{ $item->tipo === TipoItemRepasse::Desconto ? '−' : '' }}{{ $dinheiro($item->valor) }}
                </div>
            </div>
        @endforeach
        <div class="flex items-center justify-between px-3 py-2 font-semibold">
            <span>Líquido</span>
            <span class="font-mono">{{ $dinheiro($repasse->valor_liquido) }}</span>
        </div>
    </div>
</div>
