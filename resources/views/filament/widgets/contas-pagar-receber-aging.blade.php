<x-filament-widgets::widget>
    <x-filament::section heading="Aging (composição por prazo)">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-semibold text-danger-600 dark:text-danger-400 mb-2">A Pagar — Vencidos</h3>
                <table class="w-full text-sm">
                    <tbody>
                        @foreach (['1-15' => '1 a 15 dias', '16-30' => '16 a 30 dias', '31-60' => '31 a 60 dias', '61+' => 'Mais de 60 dias'] as $faixa => $label)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ $label }}</td>
                                <td class="py-1.5 text-right font-medium">R$ {{ number_format($agingVencidosPagar[$faixa], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <h3 class="text-sm font-semibold text-warning-600 dark:text-warning-400 mt-4 mb-2">A Pagar — A vencer</h3>
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ([7, 15, 30, 60] as $dias)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 text-gray-600 dark:text-gray-400">Próximos {{ $dias }} dias</td>
                                <td class="py-1.5 text-right font-medium">R$ {{ number_format($agingAVencerPagar[(string) $dias], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div>
                <h3 class="text-sm font-semibold text-danger-600 dark:text-danger-400 mb-2">A Receber — Vencidos</h3>
                <table class="w-full text-sm">
                    <tbody>
                        @foreach (['1-15' => '1 a 15 dias', '16-30' => '16 a 30 dias', '31-60' => '31 a 60 dias', '61+' => 'Mais de 60 dias'] as $faixa => $label)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ $label }}</td>
                                <td class="py-1.5 text-right font-medium">R$ {{ number_format($agingVencidosReceber[$faixa], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <h3 class="text-sm font-semibold text-warning-600 dark:text-warning-400 mt-4 mb-2">A Receber — A vencer</h3>
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ([7, 15, 30, 60] as $dias)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 text-gray-600 dark:text-gray-400">Próximos {{ $dias }} dias</td>
                                <td class="py-1.5 text-right font-medium">R$ {{ number_format($agingAVencerReceber[(string) $dias], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
