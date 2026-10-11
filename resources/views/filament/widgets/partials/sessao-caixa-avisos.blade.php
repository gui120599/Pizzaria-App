@php
    use App\Services\RelatorioSessaoCaixaService;

    /** @var array<string, mixed> $venda */
    $venda = $getRecord();
    $cores = [
        RelatorioSessaoCaixaService::AVISO_FORMA_DIVERGENTE => 'danger',
        RelatorioSessaoCaixaService::AVISO_TROCO_FORA_DINHEIRO => 'warning',
        RelatorioSessaoCaixaService::AVISO_FIADO_COM_FORMA_NO_PEDIDO => 'warning',
    ];
@endphp

<div class="flex w-full min-w-64 flex-col gap-1 px-3 py-2">
    @foreach ($venda['avisos'] as $aviso)
        <x-filament::badge :color="$cores[$aviso['tipo']] ?? 'gray'" size="sm" class="w-fit whitespace-normal text-left">
            {{ $aviso['texto'] }}
        </x-filament::badge>
    @endforeach

    @if (filled($venda['status_nfe']) && $venda['status_nfe'] !== 'Issued')
        <x-filament::badge color="gray" size="sm" class="w-fit">NFC-e: {{ $venda['status_nfe'] }}</x-filament::badge>
    @endif

    @foreach ($venda['correcoes'] as $correcao)
        <x-filament::badge color="info" size="sm" icon="heroicon-m-wrench-screwdriver" class="w-fit whitespace-normal text-left">
            {{ $correcao }}
        </x-filament::badge>
    @endforeach

    @if ($venda['avisos'] === [] && $venda['correcoes'] === [])
        <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
    @endif
</div>
