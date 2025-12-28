<section class="h-full">
    <div class="w-[18rem] sm:w-[99%] overflow-auto h-full">
        <div class="max-w-md mx-auto mt-8 bg-white p-4 shadow rounded">
            <h2 class="text-xl font-bold mb-4">Filtrar Vendas Mensais</h2>
            <form id="form-vendas">
                <div class="mb-4">
                    <label for="meses" class="block font-medium">Meses</label>
                    @foreach ($mesesDisponiveis as $mes)
                        <input type="checkbox" name="meses[]" id="mes_{{ $mes }}" value="{{ $mes }}" />
                        <label for="mes_{{ $mes }}" class="uppercase">
                            {{ \Carbon\Carbon::create()->month($mes)->translatedFormat('F') }}
                        </label><br>
                    @endforeach
                    <p class="text-[10px]">Caso não selecionado nenhum mês, o relatório busca todos os meses do ano selecionado!</p>
                </div>

                <div class="mb-4">
                    <label for="ano" class="block font-medium">Ano</label>
                    <select name="ano" id="ano" class="w-full border p-2 rounded">
                        @foreach ($anosDisponiveis as $ano)
                            <option value="{{ $ano }}">{{ $ano }}</option>
                        @endforeach
                    </select>
                </div>

                <x-secondary-button type="button" onclick="abrirRelatorio()" title="IMPRIMIR">
                    <i class='bx bx-printer'></i>
                    <span>Imprimir</span>
                </x-secondary-button>

            </form>

        </div>
    </div>
    <script>
        function abrirRelatorio() {
            const form = document.getElementById('form-vendas');
            const formData = new FormData(form);
            const params = new URLSearchParams();

            // Coleta o ano selecionado
            const ano = formData.get('ano');
            if (ano) params.append('ano', ano);

            // Coleta todos os meses marcados
            formData.getAll('meses[]').forEach(mes => {
                params.append('meses[]', mes);
            });

            // Monta a URL com base na sua rota Laravel
            const url = `{{ route('vendasMensal.imprimir') }}?${params.toString()}`;

            // Abre nova janela com a URL montada
            window.open(url, 'RelatórioVendas', 'width=800,height=600');
        }
    </script>


</section>
