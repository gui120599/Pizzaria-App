{{--
    Linha do tempo do pedido (historicoStatus já carregado). Mostra o tempo
    decorrido desde a etapa anterior, conforme registrado no sistema.
--}}
@php
    use App\Enums\StatusPedidoEnum;
    use Illuminate\Support\Carbon;

    $pedido = $getRecord();
    $anterior = null;
@endphp

@if ($pedido->historicoStatus->isEmpty())
    <p class="text-sm text-gray-400">Sem histórico de transições registrado.</p>
@else
    <ol class="relative ml-2 border-l-2 border-gray-200 text-sm dark:border-white/10">
        @foreach ($pedido->historicoStatus as $entrada)
            @php
                $decorrido = $anterior ? Carbon::parse($anterior)->diffForHumans($entrada->created_at, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2, 'short' => true]) : null;
                $anterior = $entrada->created_at;
            @endphp
            <li class="relative mb-4 ml-4 last:mb-0">
                <span class="absolute -left-[21px] h-2.5 w-2.5 rounded-full border-2 border-white bg-primary-500 dark:border-gray-900"></span>
                <p class="font-medium text-gray-950 dark:text-white">
                    {{ StatusPedidoEnum::tryFrom($entrada->hsp_status_para)?->label() ?? $entrada->hsp_status_para }}
                </p>
                <p class="text-xs text-gray-400">
                    {{ $entrada->created_at?->format('d/m/Y H:i') }} — {{ $entrada->usuario->name_first }}
                    @if ($decorrido)
                        &middot; {{ $decorrido }} após a etapa anterior
                    @endif
                </p>
            </li>
        @endforeach
    </ol>
@endif
<p class="mt-3 text-xs text-gray-400">Tempos conforme registrado no sistema (cliques dos operadores).</p>
