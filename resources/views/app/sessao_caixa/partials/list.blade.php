<section>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-center text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="w-1/12 px-1 md:px-4">#</th>
                    <th class="w-1/6 px-1 md:px-4">Caixa</th>
                    <th class="w-1/6 px-1 md:px-4">Funcionário</th>
                    <th class="w-1/6 px-1 md:px-4">Data/Hora Abertura</th>
                    <th class="w-1/6 px-1 md:px-4">Status</th>
                    <th class="w-1/6 px-1 md:px-4">Saldo Inicial</th>
                    <th class="w-1/6 px-1 md:px-4">Saldo Final</th>
                </tr>
            </thead>
            <tbody>
                @if(count($sessao_caixa) > 0)
                    @foreach ($sessao_caixa as $sessao)
                        <tr class="border-b-2 border-gray-100">
                            <td>{{ $sessao->id }}</td>
                            <td class="uppercase">{{ $sessao->caixa->caixa_nome }}</td>
                            <td class="uppercase">{{ $sessao->user->name_first }}</td>
                            <td>{{\Carbon\Carbon::parse($sessao->sessaocaixa_data_hora_abertura)->format('d/m/Y H:i:s') }}</td>
                            <td class="uppercase">{{ $sessao->sessaocaixa_status }}</td>
                            <td class="uppercase">R$ {{ $sessao->sessaocaixa_saldo_inicial ? str_replace('.',',',$sessao->sessaocaixa_saldo_inicial) : '0,00' }}</td>
                            <td class="uppercase">R$ {{ $sessao->sessaocaixa_saldo_final ? str_replace('.',',',$sessao->sessaocaixa_saldo_final) : '0,00'}}</td>
                            {{--<td>
                                <div class="flex items-center justify-center space-x-2">
                                    <x-primary-button onclick="window.location.href = '{{ route('sessao.edit', ['sessao' => $sessao]) }}'" title="Editar"><i class='bx bx-edit text-sm'></i></x-primary-button>
                                    <form action="{{ route('sessao.destroy', ['id' => $sessao]) }}" method="post">
                                        @method('delete')
                                        @csrf
                                        <x-danger-button title="Excluir"><i class='bx bx-trash text-sm'></i></x-primary-button>
                                    </form>
                                </div>
                            </td>--}}
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="3" class="text-center py-4">Nenhuma sessao encontrada.</td>
                    </tr>
                @endif
            </tbody>
        </table>
        
    </div>
</section>