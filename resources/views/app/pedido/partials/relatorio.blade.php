<section class="h-full">
    <div class="w-[18rem] sm:w-[99%] overflow-auto h-full">
        <div class="max-w-md mx-auto mt-8 bg-white p-4 shadow rounded">
            <h2 class="text-xl font-bold mb-4">Pedidos Entregas</h2>
            <form id="form-pedidos">
                <div class="mb-4">
                    <label for="meses" class="block font-medium">Meses</label>
                    <input type="date" id="datahora_abertura" name="datahora_abertura" class="w-1/2">
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
            const data = document.getElementById('datahora_abertura');

            console.log(data.value);


            // Monta a URL com base na sua rota Laravel
            var url =
                "{{ route('pedidosEntregasPDF.imprimir', ['datahora_abertura' => 1]) }}";
            url = url.replace(/\/1\/Imprimir/, `/${data.value}/Imprimir`);

            // Abre nova janela com a URL montada
            window.open(url, 'RelatórioPedidos', 'width=800,height=600');
        }
    </script>
</section>
