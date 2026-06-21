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
    this.$nextTick(() => document.getElementById('input-busca-nome-mesa')?.focus());
},
async buscarPorNome() {
    if (this.termo.trim().length < 2) { this.resultados = []; return; }
    this.buscandoNome = true;
    try {
        const r = await fetch('{{ route('cliente.buscar_nome') }}?nome=' + encodeURIComponent(this.termo));
        if (!r.ok) throw new Error(r.status);
        const d = await r.json();
        this.resultados = d.clientes ?? [];
    } catch {
        this.resultados = [];
    } finally {
        this.buscandoNome = false;
    }
},
selecionarBusca(c) {
    this.clienteId = c.cliente_id;
    this.nome = c.nome;
    this.tel = c.celular ?? '';
    this.encontrado = true;
    this.modalBuscaAberta = false;
},
