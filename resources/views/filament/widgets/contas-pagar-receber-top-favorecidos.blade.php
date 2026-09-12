<x-filament-widgets::widget>
    <x-filament::section heading="Maiores saldos em aberto">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Top fornecedores (A Pagar)</h3>
                @if ($topFornecedores->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum saldo em aberto no período filtrado.</p>
                @else
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($topFornecedores as $linha)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ $linha['nome'] }}</td>
                                    <td class="py-1.5 text-right font-medium">R$ {{ number_format($linha['saldo'], 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Top clientes (A Receber)</h3>
                @if ($topClientes->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum saldo em aberto no período filtrado.</p>
                @else
                    <table class="w-full text-sm">
                        <tbody>
                            @foreach ($topClientes as $linha)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ $linha['nome'] }}</td>
                                    <td class="py-1.5 text-right font-medium">R$ {{ number_format($linha['saldo'], 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
