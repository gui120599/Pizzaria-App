{{-- ══════════════════════════════════════════════════ --}}
{{-- Mesa: identificação do celular                    --}}
{{-- ══════════════════════════════════════════════════ --}}
<div x-show="$store.mesa.identOpen" x-cloak
     class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center"
     style="display:none">
    <div class="absolute inset-0 bg-black/70" @click="$store.mesa.identOpen = false"></div>
    <form @submit.prevent="$store.mesa.entrar()"
          class="relative w-full max-w-sm bg-forno-2 border border-linha rounded-t-2xl sm:rounded-2xl p-5 z-10 space-y-4">
        <div>
            <h3 class="text-white font-bold text-lg">Pedir pelo celular</h3>
            <p class="text-farinha text-xs mt-1">Só uma vez: assim o garçom sabe de quem é cada pedido.</p>
        </div>
        <div>
            <label for="mesa-nome" class="text-creme text-xs font-semibold uppercase tracking-wide block mb-1">Seu nome</label>
            <input id="mesa-nome" type="text" x-model="$store.mesa.form.nome" maxlength="120" autocomplete="given-name"
                   class="w-full bg-forno border border-linha text-white rounded-lg px-3 py-3 text-base focus:outline-none focus:border-brasa-viva">
        </div>
        <div>
            <label for="mesa-celular" class="text-creme text-xs font-semibold uppercase tracking-wide block mb-1">Celular com DDD</label>
            <input id="mesa-celular" type="tel" inputmode="numeric" x-model="$store.mesa.form.celular"
                   @input="$store.mesa.mascararCelular($event)" placeholder="(64) 9 9999-9999" autocomplete="tel"
                   class="w-full bg-forno border border-linha text-white rounded-lg px-3 py-3 text-base focus:outline-none focus:border-brasa-viva">
        </div>
        <div x-show="$store.mesa.estado.pede_pin">
            <label for="mesa-pin" class="text-creme text-xs font-semibold uppercase tracking-wide block mb-1">PIN da mesa</label>
            <input id="mesa-pin" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" x-model="$store.mesa.form.pin"
                   @input="$store.mesa.form.pin = $event.target.value = $event.target.value.replace(/\D/g, '').slice(0, 4)"
                   placeholder="0000" autocomplete="off"
                   class="w-full bg-forno border border-linha text-white rounded-lg px-3 py-3 text-center text-2xl font-bold tracking-[0.5em] focus:outline-none focus:border-brasa-viva">
            <p class="text-farinha text-xs mt-1">Peça o PIN de 4 números ao garçom.</p>
        </div>
        <p x-show="$store.mesa.erroIdentificacao" style="display:none" class="text-red-300 text-xs" x-text="$store.mesa.erroIdentificacao"></p>
        <button type="submit" :disabled="$store.mesa.ocupado"
                class="w-full py-3.5 rounded-xl bg-brasa hover:bg-brasa-escura disabled:opacity-60 text-white font-bold text-sm uppercase tracking-wide">
            <span x-text="$store.mesa.ocupado ? 'Entrando...' : 'Entrar na mesa'"></span>
        </button>
    </form>
</div>

{{-- ══════════════════════════════════════════════════ --}}
{{-- Mesa: meus pedidos, conta e chamados              --}}
{{-- ══════════════════════════════════════════════════ --}}
<div x-show="$store.mesa.painelOpen" x-cloak
     class="fixed inset-0 z-[60] flex flex-col justify-end"
     style="display:none">
    <div class="absolute inset-0 bg-black/60" @click="$store.mesa.painelOpen = false"></div>
    <div class="relative bg-forno-2 rounded-t-3xl max-h-[90vh] flex flex-col z-10 w-full md:max-w-lg md:mx-auto">
        <div class="flex items-center justify-between px-4 py-3 border-b border-linha shrink-0">
            <h3 class="text-white font-bold text-lg flex items-center gap-2">
                <i class='bx bx-receipt text-manjericao'></i>
                <span x-text="$store.mesa.estado.mesa.nome"></span>
            </h3>
            <button @click="$store.mesa.painelOpen = false" class="text-farinha hover:text-white transition" aria-label="Fechar">
                <i class='bx bx-x text-2xl'></i>
            </button>
        </div>

        <div class="overflow-y-auto flex-1 px-4 py-3 space-y-5">
            <template x-if="$store.mesa.estado.estado !== 'pedindo'">
                <p class="text-creme text-sm py-6 text-center" x-text="$store.mesa.legenda()"></p>
            </template>

            <template x-if="$store.mesa.estado.estado === 'pedindo'">
                <div class="space-y-5">
                    {{-- Rodadas deste celular --}}
                    <section>
                        <h4 class="text-farinha text-xs font-semibold uppercase tracking-wide mb-2">Seus pedidos</h4>
                        <p x-show="$store.mesa.estado.rodadas.length === 0" class="text-farinha text-sm">Você ainda não pediu nada.</p>
                        <div class="space-y-2">
                            <template x-for="rodada in $store.mesa.estado.rodadas" :key="rodada.id">
                                <div class="rounded-xl border border-linha bg-forno p-3">
                                    <div class="flex items-center justify-between gap-2 mb-1.5">
                                        <span class="text-farinha text-xs" x-text="rodada.hora"></span>
                                        <span class="text-[11px] font-bold uppercase rounded px-1.5 py-0.5"
                                              :class="rodada.recusada ? 'bg-red-500/20 text-red-300' : (rodada.aguardando ? 'bg-yellow-500/20 text-yellow-300' : 'bg-green-500/20 text-green-300')"
                                              x-text="rodada.situacao"></span>
                                    </div>
                                    <template x-for="(item, i) in rodada.itens" :key="i">
                                        <div class="text-sm">
                                            <span class="text-white" x-text="item.quantidade.toLocaleString('pt-BR') + '× ' + item.nome"></span>
                                            <template x-for="detalhe in item.detalhes" :key="detalhe">
                                                <span class="block text-farinha text-[11px] leading-tight" x-text="detalhe"></span>
                                            </template>
                                        </div>
                                    </template>
                                    <p x-show="rodada.motivo_recusa" style="display:none" class="mt-1.5 text-red-300 text-xs" x-text="'Motivo: ' + rodada.motivo_recusa"></p>
                                    <p class="mt-1.5 text-right text-creme text-xs" :class="rodada.recusada && 'line-through'" x-text="$store.mesa.reais(rodada.total)"></p>
                                </div>
                            </template>
                        </div>
                    </section>

                    {{-- Conta da mesa --}}
                    <section x-show="$store.mesa.estado.conta" class="rounded-xl border border-linha p-3 space-y-1 text-sm">
                        <h4 class="text-farinha text-xs font-semibold uppercase tracking-wide mb-1">Conta da mesa</h4>
                        <div class="flex justify-between text-creme"><span>Consumo</span><span x-text="$store.mesa.reais($store.mesa.estado.conta?.subtotal)"></span></div>
                        <div x-show="$store.mesa.estado.conta?.taxa > 0" class="flex justify-between text-creme">
                            <span x-text="'Serviço (' + ($store.mesa.estado.conta?.percentual ?? 0).toLocaleString('pt-BR') + '%)'"></span>
                            <span x-text="$store.mesa.reais($store.mesa.estado.conta?.taxa)"></span>
                        </div>
                        <div class="flex justify-between text-white font-bold"><span>Total da mesa</span><span x-text="$store.mesa.reais($store.mesa.estado.conta?.total)"></span></div>
                        <div class="flex justify-between text-manjericao font-semibold pt-1 border-t border-linha">
                            <span>Sua parte</span><span x-text="$store.mesa.reais($store.mesa.estado.conta?.minha.total)"></span>
                        </div>
                        <p class="text-farinha text-[11px]">Pedidos aguardando o garçom entram na conta quando ele confirma.</p>
                    </section>
                </div>
            </template>
        </div>

        <div x-show="$store.mesa.estado.estado === 'pedindo'" class="px-4 pb-6 pt-3 border-t border-linha grid grid-cols-2 gap-2 shrink-0">
            <button type="button" @click="$store.mesa.chamar('CHAMAR_GARCOM')"
                    :disabled="$store.mesa.chamadoAberto('CHAMAR_GARCOM') || $store.mesa.ocupado"
                    class="min-h-[48px] rounded-xl text-sm font-bold flex items-center justify-center gap-1 transition-colors disabled:opacity-60"
                    :class="$store.mesa.chamadoAberto('CHAMAR_GARCOM') ? 'bg-forno-3 text-creme' : 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'">
                <i class='bx bx-bell'></i>
                <span x-text="$store.mesa.chamadoAberto('CHAMAR_GARCOM') ? 'Garçom chamado' : 'Chamar garçom'"></span>
            </button>
            <button type="button" @click="$store.mesa.chamar('PEDIR_CONTA')"
                    :disabled="$store.mesa.chamadoAberto('PEDIR_CONTA') || $store.mesa.ocupado"
                    class="min-h-[48px] rounded-xl text-sm font-bold flex items-center justify-center gap-1 transition-colors disabled:opacity-60"
                    :class="$store.mesa.chamadoAberto('PEDIR_CONTA') ? 'bg-forno-3 text-creme' : 'bg-purple-500 text-white hover:bg-purple-400'">
                <i class='bx bx-wallet'></i>
                <span x-text="$store.mesa.chamadoAberto('PEDIR_CONTA') ? 'Conta pedida' : 'Pedir a conta'"></span>
            </button>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        const mesa = @js($mesaCliente);
        const chaveEnvio = 'mesa_envio_' + mesa.codigo;

        /** uuid v4 também fora de HTTPS (crypto.randomUUID só existe em contexto seguro). */
        const uuid = () => ([1e7] + -1e3 + -4e3 + -8e3 + -1e11)
            .replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));

        Alpine.store('mesa', {
            estado: mesa.estado,
            identOpen: false,
            painelOpen: false,
            form: { nome: '', celular: '', pin: '' },
            erroIdentificacao: '',
            erro: '',
            aviso: '',
            ocupado: false,
            enviando: false,
            aposEntrar: null,
            timer: null,

            init() {
                if (this.estado.redirecionar) window.location = this.estado.redirecionar;
                this.agendar();
                // Aba oculta não consulta; ao voltar, atualiza na hora.
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') this.atualizar().finally(() => this.agendar());
                    else clearTimeout(this.timer);
                });
            },

            get podePedir() { return ['pedindo', 'identificar'].includes(this.estado.estado); },
            get aguardando() { return this.estado.rodadas.filter(r => r.aguardando).length; },
            chamadoAberto(tipo) { return this.estado.chamados.includes(tipo); },
            reais(valor) { return 'R$ ' + Number(valor ?? 0).toFixed(2).replace('.', ','); },

            legenda() {
                return {
                    pedindo: 'Escolha os itens e envie: vai direto para a conta da mesa.',
                    identificar: 'Monte seu pedido. Ao enviar, pedimos seu nome e o PIN da mesa (o garçom informa).',
                    fechada: this.estado.abertura_solicitada ? 'Avisamos o garçom. Assim que ele abrir a mesa, você pode pedir.' : 'Mesa fechada: veja o cardápio e chame o garçom para abrir.',
                }[this.estado.estado] ?? (this.estado.mensagem ?? '');
            },
            mensagemBloqueio() {
                return this.estado.estado === 'fechada' ? 'Esta mesa ainda não está aberta. Chame o garçom para abrir.' : (this.estado.mensagem ?? 'Não é possível pedir agora.');
            },

            aplicar(estado) {
                const antes = this.estado.estado;
                this.estado = estado;
                if (estado.redirecionar) { window.location = estado.redirecionar; return; }
                // Conta encerrada: o carrinho deste celular não vale mais.
                if (estado.estado === 'encerrada' && antes !== 'encerrada') Alpine.store('cart').clear();
            },
            agendar() {
                clearTimeout(this.timer);
                if (document.visibilityState === 'hidden') return;
                this.timer = setTimeout(() => this.atualizar().finally(() => this.agendar()), (this.estado.polling_segundos || 15) * 1000);
            },
            async atualizar() {
                try {
                    const res = await fetch(mesa.rotas.estado, { headers: { Accept: 'application/json' } });
                    if (res.ok) this.aplicar(await res.json());
                } catch (_) { /* sem rede: tenta na próxima */ }
            },
            async post(url, body = {}) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': _csrfToken },
                    body: JSON.stringify(body),
                });
                let data = {};
                try { data = await res.json(); } catch (_) {}
                if (res.status === 429) data.message = 'Muitas tentativas. Aguarde um minuto.';
                if (res.status === 419) data.message = 'A página expirou. Atualize e tente de novo.';
                return { ok: res.ok, status: res.status, data };
            },
            avisar(texto) {
                this.aviso = texto;
                setTimeout(() => { if (this.aviso === texto) this.aviso = ''; }, 6000);
            },

            abrirPainel() {
                if (this.estado.estado === 'identificar') { this.identificar(); return; }
                this.painelOpen = true;
                this.atualizar();
            },
            identificar(aposEntrar = null) {
                this.aposEntrar = aposEntrar;
                this.erroIdentificacao = '';
                this.identOpen = true;
            },
            mascararCelular(e) {
                const d = e.target.value.replace(/\D/g, '').slice(0, 11);
                let m = d;
                if (d.length > 2) m = '(' + d.slice(0, 2) + ') ' + d.slice(2);
                if (d.length > 6) m = '(' + d.slice(0, 2) + ') ' + d.slice(2, d.length - 4) + '-' + d.slice(d.length - 4);
                this.form.celular = m;
                e.target.value = m;
            },
            async entrar() {
                if (!this.form.nome.trim()) { this.erroIdentificacao = 'Informe seu nome.'; return; }
                if (![10, 11].includes(this.form.celular.replace(/\D/g, '').length)) { this.erroIdentificacao = 'Informe o celular com DDD.'; return; }
                if (this.estado.pede_pin && this.form.pin.length !== 4) { this.erroIdentificacao = 'Informe o PIN de 4 números. Peça ao garçom.'; return; }
                this.ocupado = true;
                try {
                    const { ok, data } = await this.post(mesa.rotas.entrar, { nome: this.form.nome.trim(), celular: this.form.celular, pin: this.form.pin });
                    if (!ok) { this.erroIdentificacao = data.message ?? 'Não foi possível entrar. Tente de novo.'; this.atualizar(); return; }
                    this.aplicar(data);
                    this.form.pin = '';
                    this.identOpen = false;
                    const seguir = this.aposEntrar;
                    this.aposEntrar = null;
                    if (seguir) seguir();
                } catch (_) {
                    this.erroIdentificacao = 'Falha de conexão. Verifique sua internet.';
                } finally {
                    this.ocupado = false;
                }
            },

            async enviar() {
                const cart = Alpine.store('cart');
                if (cart.items.length === 0 || this.enviando) return;
                if (this.estado.estado === 'identificar') { this.identificar(() => this.enviar()); return; }
                if (this.estado.estado !== 'pedindo') return;

                // Mesma chave até o servidor responder: reenviar depois de
                // queda de rede devolve o mesmo pedido, sem duplicar.
                let chave = localStorage.getItem(chaveEnvio);
                if (!chave) { chave = uuid(); localStorage.setItem(chaveEnvio, chave); }

                this.enviando = true;
                this.erro = '';
                try {
                    const { ok, status, data } = await this.post(mesa.rotas.pedidos, {
                        chave,
                        itens: cart.items.map(i => ({
                            id: i.id,
                            qty: i.qty,
                            observacao: i.obs?.trim() || null,
                            sabores: i.sabores ?? null,
                            respostas: i.respostas ?? null,
                            adicionais: i.adicionais ?? null,
                            oferta_produto_id: i.oferta?.produtoId ?? null,
                        })),
                    });
                    // Resposta do servidor (aceito ou recusado): a chave já foi decidida.
                    if (status < 500) localStorage.removeItem(chaveEnvio);
                    if (!ok) {
                        this.erro = data.message ?? 'Não foi possível enviar. Tente de novo.';
                        if ([401, 403, 409, 410].includes(status)) this.atualizar();
                        return;
                    }
                    cart.clear();
                    cart.drawerOpen = false;
                    this.aplicar(data.estado);
                    this.avisar(data.message);
                    this.painelOpen = true;
                } catch (_) {
                    this.erro = 'Falha de conexão. Toque em enviar de novo: o pedido não será duplicado.';
                } finally {
                    this.enviando = false;
                }
            },

            async chamar(tipo) {
                this.ocupado = true;
                this.erro = '';
                try {
                    const { ok, status, data } = await this.post(mesa.rotas.chamados, { tipo });
                    if (!ok) {
                        this.erro = data.message ?? 'Não foi possível chamar. Tente de novo.';
                        if ([401, 403, 409, 410].includes(status)) this.atualizar();
                        return;
                    }
                    this.aplicar(data.estado);
                    this.avisar(data.message);
                } catch (_) {
                    this.erro = 'Falha de conexão. Verifique sua internet.';
                } finally {
                    this.ocupado = false;
                }
            },

            async pedirAbertura() {
                this.ocupado = true;
                this.erro = '';
                try {
                    const { ok, data } = await this.post(mesa.rotas.abertura);
                    if (!ok) { this.erro = data.message ?? 'Não foi possível chamar. Tente de novo.'; return; }
                    this.avisar(data.message);
                    await this.atualizar();
                } catch (_) {
                    this.erro = 'Falha de conexão. Verifique sua internet.';
                } finally {
                    this.ocupado = false;
                }
            },
        });
    });
</script>
