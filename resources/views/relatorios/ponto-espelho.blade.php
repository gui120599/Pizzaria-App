@php
    use App\Services\Ponto\EspelhoPontoService;

    $h = fn (?int $minutos): string => EspelhoPontoService::horas((int) $minutos);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>Espelho de ponto — {{ $periodo->rotulo() }}</title>

    @vite(['resources/css/app.css'])

    <style>
        @media print {
            @page { size: A4; margin: 1.2cm; }
            .no-print { display: none !important; }
            .colaborador { break-after: page; }
            .colaborador:last-child { break-after: auto; }
            tr { break-inside: avoid; }
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-800 text-xs">
    <button onclick="window.print()"
        class="no-print fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-lg hover:bg-gray-700">
        Imprimir
    </button>

    <div class="mx-auto max-w-4xl bg-white p-8 shadow print:shadow-none print:p-0">
        @foreach ($grade['linhas'] as $linha)
            <div class="colaborador mb-10">
                <div class="flex items-center justify-between border-b-2 border-gray-800 pb-3 mb-4">
                    <div class="flex items-center gap-4">
                        <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="Logo" class="h-12">
                        <div>
                            <h1 class="text-base font-bold">Espelho de ponto — {{ $linha['colaborador']->nome }}</h1>
                            <p class="text-gray-600">{{ $linha['colaborador']->cargo ?? '' }} · {{ $linha['colaborador']->vinculo->getLabel() }} · Período {{ $periodo->rotulo() }}</p>
                        </div>
                    </div>
                    <p class="text-right text-gray-600">Emitido em {{ now()->format('d/m/Y H:i') }}</p>
                </div>

                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1 text-left">Dia</th>
                            <th class="border p-1 text-left">Situação</th>
                            <th class="border p-1 text-right">Previsto</th>
                            <th class="border p-1 text-right">Trabalhado</th>
                            <th class="border p-1 text-right">Intervalo</th>
                            <th class="border p-1 text-right">Noturno</th>
                            <th class="border p-1 text-right">Extra</th>
                            <th class="border p-1 text-right">Atraso</th>
                            <th class="border p-1 text-center">Corrigido</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($linha['celulas'] as $data => $dia)
                            <tr>
                                <td class="border p-1">{{ \Illuminate\Support\Carbon::parse($data)->translatedFormat('D d/m') }}</td>
                                <td class="border p-1">{{ $dia?->situacao->getLabel() ?? '—' }}{{ $dia && $dia->inconsistencias ? ' ('.implode(', ', $dia->rotulosInconsistencias()).')' : '' }}</td>
                                <td class="border p-1 text-right">{{ $dia ? $h($dia->minutos_previstos) : '' }}</td>
                                <td class="border p-1 text-right">{{ $dia ? $h($dia->minutos_trabalhados) : '' }}</td>
                                <td class="border p-1 text-right">{{ $dia ? $h($dia->minutos_intervalo) : '' }}</td>
                                <td class="border p-1 text-right">{{ $dia ? $h($dia->minutos_noturnos) : '' }}</td>
                                <td class="border p-1 text-right">{{ $dia ? $h($dia->minutos_extra_50 + $dia->minutos_extra_100) : '' }}</td>
                                <td class="border p-1 text-right">{{ $dia ? $h($dia->minutos_atraso) : '' }}</td>
                                <td class="border p-1 text-center">{{ isset($linha['corrigidos'][$data]) ? '✎' : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-100 font-bold">
                            <td class="border p-1" colspan="3">Totais (faltas: {{ $linha['totais']['faltas'] }})</td>
                            <td class="border p-1 text-right">{{ $h($linha['totais']['trabalhado']) }}</td>
                            <td class="border p-1"></td>
                            <td class="border p-1 text-right">{{ $h($linha['totais']['noturno']) }}</td>
                            <td class="border p-1 text-right">{{ $h($linha['totais']['extra_50'] + $linha['totais']['extra_100']) }}</td>
                            <td class="border p-1 text-right">{{ $h($linha['totais']['atraso']) }}</td>
                            <td class="border p-1"></td>
                        </tr>
                    </tfoot>
                </table>
                <p class="mt-2 text-gray-500">✎ = dia com correção feita pelo gestor (o registro original é mantido e auditado).</p>

                <div class="mt-12 grid grid-cols-2 gap-10 text-center">
                    <div class="border-t border-gray-500 pt-1">{{ $linha['colaborador']->nome }}</div>
                    <div class="border-t border-gray-500 pt-1">Empresa</div>
                </div>
            </div>
        @endforeach
    </div>
</body>

</html>
