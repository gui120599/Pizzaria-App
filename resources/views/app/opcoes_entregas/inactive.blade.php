<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bxs-package' ></i>
            <a href="{{route('opcoes_entregas')}}">{{ __('Opções de Entregas') }}</a>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900">
                            {{ __('Opções de Entregas Inativadas') }}
                        </h2>
                    </header>
                    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">

                        <table class="w-full text-center text-[7px] md:text-base">
                            <thead class="">
                                <tr class="border-b-4">
                                    <th class="w-1/6">#</th>
                                    <th class="w-2/3 px-1 md:px-4">Descrição</th>
                                    <th class="w-1/6 px-1 md:px-4">Opções</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if (count($opcoes_entregas_inativadas) > 0)
                                    @foreach ($opcoes_entregas_inativadas as $opcao_entrega_inativada)
                                        <tr class="border-top border-gray-300">
                                            <td>{{ $opcao_entrega_inativada->id }}</td>
                                            <td>{{ $opcao_entrega_inativada->opcaoentrega_nome }}</td>
                                            <td>
                                                <div class="flex items-center justify-center space-x-4">
                                                    <x-primary-button
                                                        onclick="window.location.href = '{{ route('opcoes_entregas.active', ['id' => $opcao_entrega_inativada->id]) }}'">Ativar</x-primary-button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="3" class="text-center py-4">Nenhuma opção de entrega inativada.</td>
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
