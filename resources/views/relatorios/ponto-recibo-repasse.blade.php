@php
    use App\Enums\Ponto\TipoItemRepasse;

    $brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
    $colaborador = $repasse->colaborador;
    $proventos = $repasse->itens->where('tipo', TipoItemRepasse::Provento);
    $descontos = $repasse->itens->where('tipo', TipoItemRepasse::Desconto);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>Recibo de repasse — {{ $colaborador->nome }} — {{ $repasse->periodo->rotulo() }}</title>

    @vite(['resources/css/app.css'])

    <style>
        @media print {
            @page { size: A4; margin: 1.5cm; }
            .no-print { display: none !important; }
            .secao, tr { break-inside: avoid; }
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-800 text-xs">
    <button onclick="window.print()"
        class="no-print fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-lg hover:bg-gray-700">
        Imprimir
    </button>

    <div class="mx-auto max-w-3xl bg-white p-8 shadow print:shadow-none print:p-0">
        <div class="flex items-center justify-between border-b-2 border-gray-800 pb-3 mb-6">
            <div class="flex items-center gap-4">
                <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="Logo" class="h-14">
                <div>
                    <h1 class="text-base font-bold">Recibo de repasse</h1>
                    <p class="text-gray-600">Período {{ $repasse->periodo->rotulo() }} ({{ mb_strtolower($repasse->periodo->periodicidade->getLabel()) }})</p>
                </div>
            </div>
            <div class="text-right text-gray-600">
                <p>Situação: <strong>{{ $repasse->status->getLabel() }}</strong></p>
                @if ($repasse->aprovado_em)
                    <p>Aprovado em {{ $repasse->aprovado_em->format('d/m/Y H:i') }} por {{ $repasse->aprovadoPor?->name }}</p>
                @endif
                @if ($repasse->pago_em)
                    <p>Pago em {{ $repasse->pago_em->format('d/m/Y') }}</p>
                @endif
            </div>
        </div>

        <div class="secao mb-6 grid grid-cols-3 gap-3">
            <div><p class="text-[10px] uppercase text-gray-500">Colaborador</p><p class="font-semibold">{{ $colaborador->nome }}</p></div>
            <div><p class="text-[10px] uppercase text-gray-500">Cargo / vínculo</p><p>{{ $colaborador->cargo ?? '—' }} · {{ $colaborador->vinculo->getLabel() }}</p></div>
            <div><p class="text-[10px] uppercase text-gray-500">PIX</p><p>{{ $colaborador->chave_pix ? $colaborador->tipo_chave_pix?->getLabel().': '.$colaborador->chave_pix : '—' }}</p></div>
        </div>

        @foreach ([['Proventos', $proventos], ['Descontos', $descontos]] as [$titulo, $itens])
            <div class="secao mb-6">
                <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">{{ $titulo }}</h2>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1 text-left">Item</th>
                            <th class="border p-1 text-right">Qtd.</th>
                            <th class="border p-1 text-right">Unitário</th>
                            <th class="border p-1 text-right">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($itens as $item)
                            <tr>
                                <td class="border p-1">{{ $item->descricao }}</td>
                                <td class="border p-1 text-right">{{ $item->quantidade !== null ? number_format((float) $item->quantidade, 2, ',', '.') : '—' }}</td>
                                <td class="border p-1 text-right">{{ $item->valor_unitario !== null ? $brl((float) $item->valor_unitario) : '—' }}</td>
                                <td class="border p-1 text-right">{{ $brl((float) $item->valor) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="border p-2 text-center italic">Nenhum.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach

        <div class="secao mb-8 flex justify-end">
            <table class="w-72 border-collapse">
                <tr><td class="border p-1">Total de proventos</td><td class="border p-1 text-right">{{ $brl((float) $repasse->total_proventos) }}</td></tr>
                <tr><td class="border p-1">Total de descontos</td><td class="border p-1 text-right">{{ $brl((float) $repasse->total_descontos) }}</td></tr>
                <tr class="bg-gray-100 font-bold"><td class="border p-1">Líquido</td><td class="border p-1 text-right">{{ $brl((float) $repasse->valor_liquido) }}</td></tr>
            </table>
        </div>

        <div class="secao mt-12 grid grid-cols-2 gap-10 text-center">
            <div class="border-t border-gray-500 pt-1">{{ $colaborador->nome }}</div>
            <div class="border-t border-gray-500 pt-1">Empresa</div>
        </div>
        @if ($repasse->ciente_em)
            <p class="mt-4 text-center text-gray-500">Ciência eletrônica do colaborador em {{ $repasse->ciente_em->format('d/m/Y H:i') }}.</p>
        @endif
    </div>
</body>

</html>
