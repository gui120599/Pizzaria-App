{{-- Estado e métodos Alpine para a busca de cliente por nome.
     Incluir dentro de um x-data="{ ... }" que já tenha tel, clienteId, nome,
     encontrado. Renderiza propriedades de objeto, então deve terminar com vírgula. --}}
modalBuscaAberta: false,
termo: '',
resultados: [],
buscandoNome: false,
abrirBusca() {
    this.modalBuscaAberta = true;
    this.termo = '';
    this.resultados = [];
    this.$nextTick(() => this.$refs.inputBuscaNome?.focus());
},
async buscarPorNome() {
    if (this.termo.trim().length < 2) { this.resultados = []; return; }
    this.buscandoNome = true;
    const r = await fetch('{{ route('cliente.buscar_nome') }}?nome=' + encodeURIComponent(this.termo));
    const d = await r.json();
    this.buscandoNome = false;
    this.resultados = d.clientes ?? [];
},
selecionarBusca(c) {
    this.clienteId = c.cliente_id;
    this.nome = c.nome;
    this.tel = c.celular ?? '';
    this.encontrado = true;
    this.modalBuscaAberta = false;
},
