<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bx-note'></i>
            <a href="{{ route('nota_fiscal') }}">{{ __('Notas Fiscais Emitididas') }}</a>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">


            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    <header>
                        <div class="flex justify-between">
                            <h2 class="text-lg font-medium text-gray-900">
                                {{ __('Eventos') }}
                            </h2>
                            <x-primary-button onclick="window.open('{{ route('venda.gerar_JSONNFE', ['id' => $venda_id]) }}', '_blank')" title="Venda">JSON</x-primary-button>
                        </div>
                    </header>
                    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
                        <table id="jsonTable" class="w-full ">
                            <thead>
                                <tr>
                                    <th>Campo</th>
                                    <th>Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    
                        <script>
                            const jsonData = {!! json_encode($data) !!}; // Substitua por seu JSON dinâmico
                    
                            function createTableRows(data, prefix = '') {
                                let rows = '';
                                for (const key in data) {
                                    if (typeof data[key] === 'object' && data[key] !== null) {
                                        rows += createTableRows(data[key], `${prefix}${key}.`);
                                    } else {
                                        rows += `<tr class="border">
                                                    <td class="border-r">${prefix}${key}</td>
                                                    <td>{{ __('${data[key]}')}}</td>
                                                </tr>`;
                                    }
                                }
                                return rows;
                            }
                    
                            document.querySelector('#jsonTable tbody').innerHTML = createTableRows(jsonData);
                        </script>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
