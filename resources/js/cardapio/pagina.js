/**
 * Comportamento da página (não do carrinho): altura das barras fixas,
 * aba da seção visível, busca no cardápio e trava de rolagem com
 * sheet aberto.
 */
export function registrarPagina(Alpine) {
    /**
     * Carrossel de banners do topo: troca sozinho a cada 6 s, pausa quando o
     * cliente toca ou passa o mouse e não anda com a aba escondida nem com
     * "reduzir movimento" ligado.
     */
    Alpine.data('carrosselBanners', (total) => ({
        atual: 0,
        timer: null,
        retomarEm: null,
        init() {
            if (total < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            this.retomar();
            document.addEventListener('visibilitychange', () => (document.hidden ? this.pausar() : this.retomar()));
        },
        destroy() {
            clearInterval(this.timer);
            clearTimeout(this.retomarEm);
        },
        ir(indice) {
            const trilho = this.$refs.trilho;
            this.atual = (indice + total) % total;
            trilho.scrollTo({ left: this.atual * trilho.clientWidth, behavior: 'smooth' });
        },
        aoRolar() {
            const trilho = this.$refs.trilho;
            this.atual = Math.round(trilho.scrollLeft / Math.max(1, trilho.clientWidth));
        },
        pausar() {
            clearInterval(this.timer);
            this.timer = null;
            clearTimeout(this.retomarEm);
            // Depois de mexer, volta a andar sozinho em 10 s.
            if (total > 1) this.retomarEm = setTimeout(() => this.retomar(), 10000);
        },
        retomar() {
            if (total < 2 || this.timer || document.hidden || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            clearTimeout(this.retomarEm);
            this.timer = setInterval(() => this.ir(this.atual + 1), 6000);
        },
    }));

    /** Trilho horizontal: sabe se ainda há conteúdo à esquerda/direita para mostrar setas. */
    Alpine.data('trilhoRolavel', () => ({
        podeVoltar: false,
        podeAvancar: false,
        init() {
            this.$nextTick(() => this.medir());
            new ResizeObserver(() => this.medir()).observe(this.$refs.trilho);
        },
        medir() {
            const t = this.$refs.trilho;
            this.podeVoltar = t.scrollLeft > 4;
            this.podeAvancar = t.scrollLeft + t.clientWidth < t.scrollWidth - 4;
        },
        rolar(direcao) {
            const t = this.$refs.trilho;
            t.scrollBy({ left: direcao * t.clientWidth * 0.7, behavior: 'smooth' });
        },
    }));

    Alpine.data('cardapioPagina', () => ({
        secaoAtiva: null,
        busca: '',
        buscaAberta: false,
        semResultado: false,

        init() {
            const raiz = document.documentElement;
            const topo = document.getElementById('topo-fixo');
            const abas = document.getElementById('abas-fixas');
            const medir = () => {
                raiz.style.setProperty('--altura-topo', (topo?.offsetHeight ?? 56) + 'px');
                raiz.style.setProperty('--altura-abas', (abas?.offsetHeight ?? 57) + 'px');
            };
            medir();
            new ResizeObserver(medir).observe(topo);

            // Aba ativa = última seção cujo topo já passou da linha logo
            // abaixo das barras fixas (a anterior não "vaza" para a próxima).
            const secoes = [...document.querySelectorAll('[data-secao]')];
            this.secaoAtiva = secoes[0]?.id ?? null;
            let agendado = false;
            const atualizar = () => {
                agendado = false;
                const linha = (topo?.offsetHeight ?? 56) + (abas?.offsetHeight ?? 57) + 24;
                const visiveis = secoes.filter(s => s.offsetParent !== null);
                const atual = visiveis.filter(s => s.getBoundingClientRect().top <= linha).pop() ?? visiveis[0];
                if (atual && atual.id !== this.secaoAtiva) {
                    this.secaoAtiva = atual.id;
                    this.centralizarAba(atual.id);
                }
            };
            window.addEventListener('scroll', () => {
                if (!agendado) { agendado = true; requestAnimationFrame(atualizar); }
            }, { passive: true });
        },

        irPara(id) {
            const alvo = document.getElementById(id);
            if (!alvo) return;
            this.secaoAtiva = id;
            this.centralizarAba(id);
            alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        /**
         * Filtro de subcategoria encolhe a lista de baixo de quem já
         * rolou para dentro da seção — sem isto a tela "cai" na seção
         * seguinte ou anterior. Volta para o início da própria seção.
         */
        alinharSecao(el) {
            this.$nextTick(() => {
                const secao = el.closest('[data-secao]');
                const linha = (document.getElementById('topo-fixo')?.offsetHeight ?? 56) + (document.getElementById('abas-fixas')?.offsetHeight ?? 57);
                if (secao && secao.getBoundingClientRect().top < linha) secao.scrollIntoView({ block: 'start' });
            });
        },
        centralizarAba(id) {
            const trilho = document.querySelector('#trilho-abas [x-ref="trilho"]');
            const aba = trilho?.querySelector(`[data-aba="${id}"]`);
            if (aba) trilho.scrollTo({ left: aba.offsetLeft - (trilho.clientWidth - aba.offsetWidth) / 2, behavior: 'smooth' });
        },

        // ── Busca: filtra os cards das categorias (destaques somem enquanto busca) ──
        alternarBusca() {
            this.buscaAberta = !this.buscaAberta;
            if (!this.buscaAberta) this.limparBusca();
        },
        limparBusca() {
            this.busca = '';
            this.buscaAberta = false;
            this.filtrar();
        },
        filtrar() {
            const termo = Alpine.store('cart').normalizar(this.busca);
            document.documentElement.classList.toggle('buscando', termo !== '');
            if (termo !== '') window.dispatchEvent(new CustomEvent('cardapio-busca'));
            let algum = false;
            document.querySelectorAll('[data-secao-categoria]').forEach(secao => {
                let naSecao = false;
                secao.querySelectorAll('[data-grupo]').forEach(grupo => {
                    let noGrupo = false;
                    grupo.querySelectorAll('[data-busca]').forEach(card => {
                        const ok = termo === '' || card.dataset.busca.includes(termo);
                        card.hidden = !ok;
                        noGrupo ||= ok;
                    });
                    grupo.hidden = !noGrupo;
                    naSecao ||= noGrupo;
                });
                secao.hidden = termo !== '' && !naSecao;
                algum ||= naSecao;
            });
            this.semResultado = !algum;
            if (termo !== '') window.scrollTo({ top: 0 });
        },

        /** Reinicia a animação curta da sacola a cada item adicionado. */
        pulsar(el, pulso) {
            if (!pulso) return;
            el.classList.remove('pulso-sacola');
            void el.offsetWidth;
            el.classList.add('pulso-sacola');
        },

        travarRolagem() {
            const c = Alpine.store('cart');
            const aberto = c.saboresModal.open || c.montagemModal.open || c.ofertaModal.open || c.sugestaoModal.open || c.trocoModal.open || !!c.detalhe
                || c.comboOportunidade.open || c.comboConquista.open || c.anuncio.open
                || (c.drawerOpen && window.innerWidth < 1024) || (c.drawerOpen && c.step !== 'cart');
            document.documentElement.style.overflow = aberto ? 'hidden' : '';
        },
    }));
}
