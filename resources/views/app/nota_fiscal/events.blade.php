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
                                {{ __('Lista de Notas Fiscais') }}
                            </h2>
                            <x-secondary-button
                                onclick="window.location.href = '{{ route('categoria.inactive') }}'">Mostrar
                                Inativos</x-secondary-button>
                        </div>
                    </header>
                    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
                        <table class="w-full text-center text-[7px] md:text-base">
                            <thead class="">
                                <tr class="border-b-4">
                                    <th class="w-1/6">#</th>
                                    <th class="w-1/6">Status</th>
                                    <th class="w-1/6">Data de Emissão</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (count($data) > 0)
                                    @foreach ($data as $item)
                                        @php
                                            $createdOn = isset($item['data']['createdOn']) ? $item['data']['createdOn'] : null;

                                            if ($createdOn) {
                                                $date = \Carbon\Carbon::parse($createdOn)->setTimezone(
                                                    'America/Sao_Paulo',
                                                );
                                                $formattedDate = $date->format('d/m/Y H:i:s');
                                            } else {
                                                $formattedDate = 'N/A';
                                            }
                                        @endphp

                                        @if ($item['sequence'] == 4)
                                            <tr class="border-b-2 border-gray-100">
                                            <td>{{ $item['sequence'] }}</td>
                                            <td>{{ $formattedDate }}</td>
                                            <td>{{ $item['data']['message'] }}</td>
                                        </tr>
                                        @endif

                                        
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="7" class="text-center py-4">Nenhum item encontrado.</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
