import { desenharPizza } from '../pizza-svg';

/**
 * Carrinho do cardápio (Alpine.store('cart')): sacola, montagem do item,
 * sabores, promoções, checkout e envio da mesa. Os dados vêm do servidor
 * (cardapio/script.blade.php, ver dados.js); o preço que vale é sempre o do
 * servidor, que reprecifica tudo no checkout.
 *
 * @param {object} Alpine
 * @param {object} dados  lerDados()
 */
export function registrarCarrinho(Alpine, dados) {
    const _checkoutRoute        = dados.rotas.checkout;
    const _cupomRoute           = dados.rotas.cupom;
    const _comboRoute           = dados.rotas.combo;
    const _lookupRoute          = dados.rotas.lookupCliente;
    const _csrfToken            = dados.csrf;
    const _opcoesEntregas       = dados.opcoesEntregas;
    const _categoriasComSabores = dados.categoriasComSabores;
    const _horarios             = dados.horarios;
    // Promoção "leve outro produto por +R$X", indexada pelo produto gatilho.
    const _promocoesAdicionais  = dados.promocoesAdicionais;
    // Campanhas com categoria exigida (ex.: Pizza Grande + refri 2L): ao pôr
    // a pizza, sugere os gatilhos; com o gatilho antes, oferece depois.
    const _campanhasExigencia   = dados.campanhasExigencia;
    // Promoção Combo ("2 grandes por R$ 87,99"): produtos participantes de
    // cada uma, para o convite de completar. O preço vem do servidor.
    const _promocoesCombo       = dados.promocoesCombo;
    // Aviso ao abrir o cardápio: promoções em destaque (combo, relâmpago,
    // "leve outro produto"), na ordem da página. Ver CardapioService::anunciosPromocoes().
    const _anunciosPromocoes    = dados.anunciosPromocoes ?? [];
    // "Leve também": produtoId => ids sugeridos (Produto::leveTambem), só os
    // que estão na tela. Pelo preço normal; o servidor precifica no checkout.
    const _leveTambem           = dados.leveTambem ?? {};
    // Perguntas do item (borda, ponto da carne): por produto (item comum e
    // pizza inteira) e por categoria (pizza de sabores). O servidor revalida.
    const _perguntasProdutos    = dados.perguntasProdutos;
    const _perguntasCombos      = dados.perguntasCombos;
    // Adicionais vinculados a cada produto (bacon, cheddar...).
    const _adicionaisProdutos   = dados.adicionaisProdutos;
    // Página do QR da mesa (null no delivery): o carrinho é deste celular
    // nesta mesa e o envio vai para a conta da mesa, não para o WhatsApp.
    const _mesaCliente          = dados.mesaCliente;
    const _chaveCarrinho        = _mesaCliente ? 'mesa_cart_' + _mesaCliente.codigo : 'cardapio_cart';
    // Dados de exibição de cada produto (sheet de detalhe). Só exibição: o
    // carrinho segue chamando add()/abrirSabores() e o servidor reprecifica.
    const _produtosTela         = dados.produtosTela;

    Alpine.store('cart', {
        // ── Estado do carrinho ──
        items: JSON.parse(localStorage.getItem(_chaveCarrinho) || '[]').map(i => ({
            cartKey: i.cartKey ?? String(i.id),
            ...i,
        })),
        drawerOpen: new URLSearchParams(window.location.search).get('abrir') === 'carrinho',
        step: 'cart',   // 'cart' | 'checkout' | 'sucesso'

        // ── Tela de sucesso do pedido (delivery) ──
        // Fica com o link do WhatsApp mesmo se o navegador bloquear o
        // pop-up aberto automaticamente depois do envio.
        ultimoPedido: null,

        // ── Sheet de detalhe do produto ──
        detalhe: null,

        // ── Aviso curto (adicionado, loja fechada) ──
        aviso: { texto: '', tipo: 'ok', id: 0 },
        // Quantidade salva por último: o aviso de "adicionado" só
        // aparece quando ela sobe (decremento não avisa).
        _countSalvo: 0,
        pulso: 0,

        // Atualizado a cada 30s: o status aberto/fechado do topo
        // muda sozinho na virada do horário.
        relogio: Date.now(),

        init() {
            this._countSalvo = this.count;
            _campanhasExigencia.forEach(c => { this._unidadesExigidas[c.promocaoId] = this.unidadesExigidas(c); });
            setInterval(() => { this.relogio = Date.now(); }, 30000);
            this.atualizarCombo(true);
            const produtoId = Number(new URLSearchParams(window.location.search).get('produto'));
            if (produtoId) this.abrirDetalhe(produtoId);
            else this.anunciarPromocoes();
        },

        // ── Cupom ──
        // O desconto exibido é só uma prévia calculada pelo servidor; o checkout revalida.
        // totalBase: total do carrinho quando o cupom foi validado (se mudar, a prévia vale 0).
        cupom: {
            codigo: (new URLSearchParams(window.location.search).get('cupom') || '').toUpperCase().replace(/\s/g, ''),
            aplicado: false, desconto: 0, totalBase: 0, erro: '', carregando: false,
        },

        // ── Modal de sabores ──
        saboresModal: {
            open: false, categoriaId: null, categoriaNome: '', maxSabores: 2,
            modo: 1, produtos: [], selecionados: [], opcoes: [], busca: '',
        },

        // ── Modal de oferta (promoção "leve outro produto por +R$X") ──
        // pending guarda o item já pronto pra entrar no carrinho; só é
        // empurrado pra items[] depois que o cliente escolhe uma opção
        // (ou recusa) — nada é adicionado sozinho.
        // existente = a linha já está no carrinho (gatilho entrou antes da
        // categoria exigida): aceitar só anota a oferta nela.
        ofertaModal: { open: false, pending: null, ofertas: [], existente: false },

        // ── Sugestão de promoção (pizza na sacola → "leve um refri 2L e
        // ganhe o brotinho") — escolher um gatilho segue o fluxo normal.
        sugestaoModal: { open: false, campanha: null },
        // Unidades da categoria exigida por campanha no último save():
        // só sugere/oferece quando sobe (não a cada mudança do carrinho).
        _unidadesExigidas: {},

        // ── Modal de montagem do item (perguntas e adicionais) ──
        // pending é o item já montado; só entra no carrinho depois da
        // montagem, seguindo para aposMontar (oferta ou direto).
        montagemModal: { open: false, pending: null, perguntas: [], respostas: {}, adicionais: [], adicionaisMarcados: [], aposMontar: null },

        // Nomes dos produtos cuja oferta foi removida automaticamente
        // por causa da forma de pagamento escolhida (ver
        // revalidarOfertasContraPagamento) — exibido como aviso.
        avisoPagamentoRemovido: [],
        // Nomes dos produtos cuja oferta acabou de ser restaurada por
        // voltar pra uma forma de pagamento válida — exibido como aviso.
        avisoPagamentoRestaurado: [],

        // ── Modal de troco ──
        trocoModal: { open: false },

        // ── Promoção Combo ──
        // Prévia calculada no servidor (/cardapio/carrinho/precificar) a
        // cada mudança da sacola; o checkout recalcula do zero.
        // linhas: cartKey => desconto; assinatura: sacola que gerou a resposta.
        combo: { desconto: 0, linhas: {}, aplicacoes: [], pendencias: [], assinatura: '' },
        _comboSeq: 0,
        _comboTimer: null,
        // Combos e pendências por promoção na última resposta. null = ainda
        // não houve resposta (a sacola restaurada não comemora nem convida).
        _comboPorPromocao: null,
        // Unidades participantes de cada promoção na última resposta: o
        // convite só aparece quando o cliente adiciona (nunca ao remover).
        _comboUnidades: {},
        comboOportunidade: { open: false, promocao: null, faltam: 0, grupos: [], combosFechados: 0 },
        comboConquista: { open: false, aplicacao: null },
        // Aviso da promoção ao abrir o cardápio (uma vez por sessão); 'outras' = as demais vigentes.
        anuncio: { open: false, promocao: null, outras: [], fechada: false, abreEm: null },
        // Sugestões do produto que acabou de entrar na sacola.
        leveTambem: { open: false, origem: '', produtos: [] },

        // ── Estado do formulário ──
        form: {
            clienteId:      null,
            nome:           '',
            telefone:       '',
            opcaoEntregaId: null,
            requerEndereco: false,
            endereco:       '',
            pagamentoId:    null,
            pagamentoNome:  '',
            isDinheiro:     false,
            trocoPara:      '',
        },
        lookingUp:        false,
        clienteEncontrado: false,
        submitting:       false,
        erros:            {},

        // ── Verificação de horário (recalcula a cada chamada) ──
        estaAbertoAgora() {
            // Na mesa vale a conta aberta, não o horário do delivery.
            if (_mesaCliente) return Alpine.store('mesa').podePedir;
            void this.relogio;
            if (!_horarios.length) return true;
            const agora = new Date();
            const dia   = agora.getDay();
            const hh    = agora.getHours().toString().padStart(2, '0');
            const mm    = agora.getMinutes().toString().padStart(2, '0');
            const hora  = `${hh}:${mm}`;
            return _horarios.some(h => h.dia === dia && h.abertura <= hora && h.fechamento >= hora);
        },
        proximoHorarioAgora() {
            if (_mesaCliente || !_horarios.length) return null;
            void this.relogio;
            const agora = new Date();
            const dias  = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
            const hh    = agora.getHours().toString().padStart(2, '0');
            const mm    = agora.getMinutes().toString().padStart(2, '0');
            const hora  = `${hh}:${mm}`;
            const hoje  = _horarios.filter(h => h.dia === agora.getDay() && h.abertura > hora)
                .sort((a, b) => a.abertura.localeCompare(b.abertura));
            if (hoje.length) return 'hoje às ' + hoje[0].abertura;
            for (let i = 1; i <= 7; i++) {
                const dia  = (agora.getDay() + i) % 7;
                const prox = _horarios.filter(h => h.dia === dia).sort((a, b) => a.abertura.localeCompare(b.abertura));
                if (prox.length) {
                    const label = i === 1 ? 'amanhã' : (dia === 0 ? 'no Domingo' : dia === 6 ? 'no Sábado' : 'na ' + dias[dia] + '-feira');
                    return label + ' às ' + prox[0].abertura;
                }
            }
            return null;
        },

        // ── Carrinho ──
        add(id, nome, preco, precoOriginal, foto = '') {
            if (!this.estaAbertoAgora()) {
                this.avisarFechado();
                return;
            }
            const itemData = { cartKey: String(id), id, nome, preco, precoOriginal: precoOriginal ?? preco, qty: 1, foto, obs: '' };
            // Produto com perguntas ou adicionais sempre abre a montagem:
            // cada combinação de escolhas é uma linha própria do carrinho.
            const perguntas  = _perguntasProdutos[id] ?? [];
            const adicionais = _adicionaisProdutos[id] ?? [];
            if (perguntas.length > 0 || adicionais.length > 0) {
                this.montar(itemData, perguntas, adicionais, (item) => this.adicionarOuOferecer(item));
                return;
            }
            const idx = this.items.findIndex(i => i.cartKey === itemData.cartKey);
            if (idx >= 0) {
                this.items[idx].qty++;
                this.save();
                this.sugerirLeveTambem(id);
                return;
            }
            this.adicionarOuOferecer(itemData);
        },
        // ── Montagem do item (perguntas e adicionais) ──
        montar(itemData, perguntas, adicionais, aposMontar) {
            this.montagemModal = { open: true, pending: itemData, perguntas, respostas: {}, adicionais, adicionaisMarcados: [], aposMontar };
        },
        respostaMarcada(perguntaId, opcaoId) {
            return (this.montagemModal.respostas[perguntaId] ?? []).includes(opcaoId);
        },
        /** Escolha única troca a opção; múltipla marca até o máximo da pergunta. */
        alternarResposta(pergunta, opcaoId) {
            const marcadas = this.montagemModal.respostas[pergunta.id] ?? [];
            let novas = marcadas;
            if (marcadas.includes(opcaoId)) novas = marcadas.filter(id => id !== opcaoId);
            else if (pergunta.maximo <= 1) novas = [opcaoId];
            else if (marcadas.length < pergunta.maximo) novas = [...marcadas, opcaoId];
            this.montagemModal.respostas = { ...this.montagemModal.respostas, [pergunta.id]: novas };
        },
        faltaResponder(pergunta) {
            return (this.montagemModal.respostas[pergunta.id] ?? []).length < pergunta.minimo;
        },
        get perguntasRespondidas() {
            return this.montagemModal.perguntas.every(p => !this.faltaResponder(p));
        },
        adicionalMarcado(adicionalId) {
            return this.montagemModal.adicionaisMarcados.includes(adicionalId);
        },
        alternarAdicional(adicionalId) {
            const marcados = this.montagemModal.adicionaisMarcados;
            this.montagemModal.adicionaisMarcados = marcados.includes(adicionalId)
                ? marcados.filter(id => id !== adicionalId)
                : [...marcados, adicionalId];
        },
        /** Acréscimo das opções e adicionais marcados, por unidade — o servidor cobra o mesmo. */
        get acrescimoMontagem() {
            const respostas = this.montagemModal.perguntas.reduce((total, p) => total + p.opcoes
                .filter(o => this.respostaMarcada(p.id, o.id))
                .reduce((s, o) => s + o.valor, 0), 0);
            const adicionais = this.montagemModal.adicionais
                .filter(a => this.adicionalMarcado(a.id))
                .reduce((s, a) => s + a.valor, 0);
            return respostas + adicionais;
        },
        confirmarMontagem() {
            if (!this.perguntasRespondidas) return;
            const { pending, perguntas, respostas, adicionais, aposMontar } = this.montagemModal;
            const acrescimo = this.acrescimoMontagem;
            const respondidas = perguntas
                .map(p => ({ pergunta: p, opcoes: p.opcoes.filter(o => (respostas[p.id] ?? []).includes(o.id)) }))
                .filter(r => r.opcoes.length > 0);
            const escolhidos = adicionais.filter(a => this.adicionalMarcado(a.id));
            const itemData = {
                ...pending,
                preco: Math.round((pending.preco + acrescimo) * 100) / 100,
                precoOriginal: Math.round(((pending.precoOriginal ?? pending.preco) + acrescimo) * 100) / 100,
                respostas: Object.fromEntries(respondidas.map(r => [r.pergunta.id, r.opcoes.map(o => o.id)])),
                respostasTexto: respondidas.map(r => r.pergunta.texto + ': ' + r.opcoes.map(o => o.nome).join(', ')),
                adicionais: escolhidos.map(a => a.id),
                adicionaisTexto: escolhidos.map(a => a.nome),
                cartKey: pending.cartKey
                    + '|' + respondidas.map(r => r.pergunta.id + ':' + r.opcoes.map(o => o.id).join('.')).join(',')
                    + '|' + escolhidos.map(a => a.id).sort((x, y) => x - y).join('.'),
            };
            this.cancelarMontagem();
            const existente = this.items.find(i => i.cartKey === itemData.cartKey);
            if (existente) {
                existente.qty++;
                this.save();
                return;
            }
            aposMontar(itemData);
        },
        cancelarMontagem() {
            this.montagemModal = { open: false, pending: null, perguntas: [], respostas: {}, adicionais: [], adicionaisMarcados: [], aposMontar: null };
        },
        /**
         * Produto novo no carrinho (nunca um incremento de quantidade):
         * se ele dispara uma promoção "leve outro produto por +R$X"
         * vigente, pergunta antes de adicionar. Sem oferta, adiciona
         * direto — igual ao comportamento de sempre.
         */
        /**
         * Campanha com categoria exigida (ex.: Pizza Grande): só oferece
         * quando há unidade dessa categoria sobrando no carrinho (uma por
         * oferta já aceita da mesma campanha). O servidor revalida.
         */
        atendeExigencia(promo) {
            if (!promo.produtosExigidos) return true;
            const unidades = this.items
                .filter(i => promo.produtosExigidos.includes(i.id))
                .reduce((s, i) => s + i.qty, 0);
            const aceites = this.items
                .filter(i => i.oferta && _promocoesAdicionais[i.id]?.promocaoId === promo.promocaoId)
                .length;
            return unidades > aceites;
        },
        adicionarOuOferecer(itemData) {
            const promo = _promocoesAdicionais[itemData.id];
            if (promo && promo.ofertas.length > 0 && this.atendeExigencia(promo)) {
                this.ofertaModal = { open: true, pending: itemData, ofertas: promo.ofertas, existente: false };
                return;
            }
            this.items.push(itemData);
            this.save();
            this.sugerirLeveTambem(itemData.id);
            // Gatilho antes da categoria exigida: avisa o que falta. Quando
            // ela entrar, verificarExigencias() oferece para esta linha.
            if (promo && promo.ofertas.length > 0 && promo.produtosExigidos) {
                this.avisar('Adicione 1 ' + (promo.categoriaExigida ?? 'item da promoção') + ' e ganhe a oferta!');
            }
        },
        unidadesExigidas(campanha) {
            return this.items
                .filter(i => campanha.produtosExigidos.includes(i.id))
                .reduce((s, i) => s + i.qty, 0);
        },
        /**
         * Chamado no save(): quando entra mais uma unidade da categoria
         * exigida e ainda sobra unidade sem oferta, oferece para um
         * gatilho já na sacola (sem oferta e não recusado) ou, se não
         * houver gatilho pendente, sugere os gatilhos da campanha.
         */
        verificarExigencias() {
            for (const campanha of _campanhasExigencia) {
                const unidades = this.unidadesExigidas(campanha);
                const antes = this._unidadesExigidas[campanha.promocaoId] ?? 0;
                this._unidadesExigidas[campanha.promocaoId] = unidades;
                if (unidades <= antes) continue;
                if (this.ofertaModal.open || this.sugestaoModal.open || this.montagemModal.open || this.saboresModal.open) return;
                const daCampanha = this.items.filter(i => _promocoesAdicionais[i.id]?.promocaoId === campanha.promocaoId);
                const aceites = daCampanha.filter(i => i.oferta).length;
                if (unidades <= aceites) continue;
                const pendente = daCampanha.find(i => !i.oferta && !i.ofertaRecusada);
                if (pendente) {
                    this.ofertaModal = { open: true, pending: pendente, ofertas: _promocoesAdicionais[pendente.id].ofertas, existente: true };
                    return;
                }
                if (daCampanha.every(i => i.oferta) && campanha.gatilhos.length > 0) {
                    this.sugestaoModal = { open: true, campanha };
                    return;
                }
            }
        },
        escolherGatilhoSugerido(gatilho) {
            this.sugestaoModal = { open: false, campanha: null };
            this.add(gatilho.id, gatilho.nome, gatilho.preco, gatilho.precoVenda, gatilho.foto);
        },
        fecharSugestao() {
            this.sugestaoModal = { open: false, campanha: null };
        },
        /** Escolha de uma opção (ou recusa) no modal — só aqui o item realmente entra no carrinho. */
        confirmarOferta(aceitar, ofertaId = null) {
            const itemData = this.ofertaModal.pending;
            const existente = this.ofertaModal.existente;
            const opcao    = aceitar ? this.ofertaModal.ofertas.find(o => o.ofertaId === ofertaId) : null;
            this.ofertaModal = { open: false, pending: null, ofertas: [], existente: false };
            if (!itemData) return;
            if (!opcao) itemData.ofertaRecusada = true;
            if (opcao) {
                itemData.oferta = {
                    ofertaId:       opcao.ofertaId,
                    produtoId:      opcao.produtoId,
                    nome:           opcao.nome,
                    nomeExibicao:   opcao.nomeExibicao,
                    valorAdicional: opcao.valorAdicional,
                    foto:           opcao.foto,
                };
            }
            if (!existente) this.items.push(itemData);
            this.save();
            if (!existente) this.sugerirLeveTambem(itemData.id);
        },
        /**
         * Chamado ao escolher forma de pagamento: volta ao preço normal
         * (e tira a oferta da campanha adicional) todo item cuja promoção
         * não aceita a forma escolhida — promoção adicional ou relâmpago
         * (ex.: "só Pix ou Dinheiro") — guardando o que foi removido em
         * item._promoBackup. Ao voltar pra uma forma válida, restaura o
         * preço/oferta de onde ficaram. O servidor também revalida isso de
         * forma independente no checkout — isto é só pra manter a UI
         * coerente antes de finalizar.
         */
        revalidarOfertasContraPagamento() {
            const pagamentoId = this.form.pagamentoId;
            const removidos = [];
            const restaurados = [];
            this.items.forEach(item => {
                const promo = _promocoesAdicionais[item.id];
                const permitidas = promo?.opcoesPagamentoPermitidas ?? [];
                const adicionalBarrado = !!promo && permitidas.length > 0 && !permitidas.includes(pagamentoId);
                const relampagoBarrado = this.relampagoRecusaPagamento(item, pagamentoId);
                if (adicionalBarrado || relampagoBarrado) {
                    if (!item._promoBackup) {
                        item._promoBackup = { oferta: item.oferta, preco: item.preco };
                        if (adicionalBarrado) item.oferta = null;
                        item.preco = item.precoOriginal ?? item.preco;
                    }
                    removidos.push(item.nome);
                } else if (item._promoBackup) {
                    item.oferta = item._promoBackup.oferta;
                    item.preco = item._promoBackup.preco;
                    delete item._promoBackup;
                    restaurados.push(item.nome);
                }
            });
            this.avisoPagamentoRemovido = removidos;
            this.avisoPagamentoRestaurado = restaurados;
            if (removidos.length > 0 || restaurados.length > 0) this.save();
            else this.agendarCombo();
        },
        /** O item (ou algum sabor dele) está numa relâmpago que não aceita esta forma de pagamento. */
        relampagoRecusaPagamento(item, pagamentoId) {
            if (!pagamentoId) return false;
            const ids = item.sabores?.length ? item.sabores.map(s => s.id) : [item.id];
            return ids.some(id => {
                const aceitas = _produtosTela[id]?.relampagoPagamentos ?? [];
                return aceitas.length > 0 && !aceitas.includes(pagamentoId);
            });
        },
        increment(cartKey) {
            const item = this.items.find(i => i.cartKey === String(cartKey));
            if (item) { item.qty++; this.save(); }
        },
        decrement(cartKey) {
            let idx = this.items.findIndex(i => i.cartKey === String(cartKey));
            // Card do produto passa o id: com perguntas, as linhas têm
            // chave própria (id + respostas) — tira da última adicionada.
            if (idx < 0 && typeof cartKey === 'number') idx = this.items.findLastIndex(i => i.id === cartKey);
            if (idx < 0) return;
            this.items[idx].qty--;
            if (this.items[idx].qty <= 0) this.items.splice(idx, 1);
            this.save();
        },
        remove(cartKey) { this.items = this.items.filter(i => i.cartKey !== String(cartKey)); this.save(); },
        /** Cliente mudou de ideia depois de aceitar a oferta — some só a oferta, o produto fica. */
        removerOferta(cartKey) {
            const item = this.items.find(i => i.cartKey === String(cartKey));
            if (item) { item.oferta = null; this.save(); }
        },
        qty(id) { return this.items.filter(i => i.id === id).reduce((s, i) => s + i.qty, 0); },
        get subtotalItens() { return this.items.reduce((s, i) => s + i.preco * i.qty + (i.oferta ? i.oferta.valorAdicional : 0), 0); },
        get total()        { return Math.max(0, Math.round((this.subtotalItens - this.comboDesconto) * 100) / 100); },
        get totalOriginal() { return this.items.reduce((s, i) => s + (i.precoOriginal ?? i.preco) * i.qty + (i.oferta ? i.oferta.valorAdicional : 0), 0); },
        get totalDesconto() { return Math.max(0, this.totalOriginal - this.total); },
        get cupomVigente() { return this.cupom.aplicado && Math.abs(this.cupom.totalBase - this.total) < 0.005; },
        get cupomDesconto() { return this.cupomVigente ? this.cupom.desconto : 0; },
        get count() { return this.items.reduce((s, i) => s + i.qty + (i.oferta ? 1 : 0), 0); },
        get valorFrete() {
            if (!this.form.opcaoEntregaId) return 0;
            const opcao = _opcoesEntregas.find(o => o.id === this.form.opcaoEntregaId);
            if (!opcao || !opcao.valor_frete) return 0;
            if (opcao.min_frete > 0 && (this.total - this.cupomDesconto) >= opcao.min_frete) return 0;
            return opcao.valor_frete;
        },
        get totalComFrete() { return this.total - this.cupomDesconto + this.valorFrete; },
        save()  {
            localStorage.setItem(_chaveCarrinho, JSON.stringify(this.items));
            this.agendarCombo();
            const count = this.count;
            if (count > this._countSalvo) {
                this.pulso++;
                if (!this.drawerOpen) this.avisar('Adicionado à sacola');
            }
            this._countSalvo = count;
            this.verificarExigencias();
        },
        clear() { this.items = []; this._countSalvo = 0; this._unidadesExigidas = {}; this.combo = { desconto: 0, linhas: {}, aplicacoes: [], pendencias: [], assinatura: '' }; this._comboPorPromocao = {}; this._comboUnidades = {}; this.avisoPagamentoRemovido = []; this.avisoPagamentoRestaurado = []; localStorage.removeItem(_chaveCarrinho); },

        // ── Promoção Combo: prévia do servidor e convites ──
        /** O que o servidor precisa para precificar (nunca preço). */
        itensParaServidor(itens = this.items) {
            return itens.map(i => ({
                id: i.id, qty: i.qty, sabores: i.sabores ?? null, respostas: i.respostas ?? null,
                adicionais: i.adicionais ?? null, oferta_produto_id: i.oferta?.produtoId ?? null,
            }));
        },
        assinaturaCombo(itens = this.items) {
            return JSON.stringify(this.itensParaServidor(itens));
        },
        /** Desconto da prévia, só enquanto ela é da sacola atual. */
        get comboVigente() { return this.combo.assinatura !== '' && this.combo.assinatura === this.assinaturaCombo(); },
        get comboDesconto() { return this.comboVigente ? this.combo.desconto : 0; },
        comboDaLinha(cartKey) { return this.comboVigente ? (this.combo.linhas[cartKey] ?? 0) : 0; },
        get combosAplicados() { return this.comboVigente ? this.combo.aplicacoes : []; },
        /** Pendências com convite ligado: "falta 1 para levar 2 por R$ 87,99". */
        get combosPendentes() {
            if (!this.comboVigente) return [];
            return this.combo.pendencias
                .map(p => ({ ...p, promocao: _promocoesCombo.find(c => c.promocaoId === p.promocao_id) }))
                .filter(p => p.promocao?.sugerir);
        },
        agendarCombo() {
            clearTimeout(this._comboTimer);
            this._comboTimer = setTimeout(() => this.atualizarCombo(), 300);
        },
        async atualizarCombo(inicial = false) {
            const itens = this.items.slice();
            const seq = ++this._comboSeq;
            if (_promocoesCombo.length === 0 || itens.length === 0) {
                this.aplicarRespostaCombo(null, itens, inicial);
                return;
            }
            const assinatura = this.assinaturaCombo(itens);
            try {
                const res = await fetch(_comboRoute, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
                    body: JSON.stringify({
                        itens: this.itensParaServidor(itens),
                        opcao_entrega_id: this.form.opcaoEntregaId,
                        opcao_pagamento_id: this.form.pagamentoId,
                        mesa: !!_mesaCliente,
                    }),
                });
                if (seq !== this._comboSeq) return;
                this.aplicarRespostaCombo(res.ok ? await res.json() : null, itens, inicial, assinatura);
            } catch (_) {
                // Sem conexão: a sacola segue sem a prévia; o checkout aplica o combo.
            }
        },
        /**
         * Guarda a prévia e decide o convite: combo novo comemora; item
         * participante sem par (o 1º, o 3º, o 5º...) convida a completar,
         * no máximo uma vez até o cliente fechar outro combo.
         */
        aplicarRespostaCombo(data, itens, inicial, assinatura = '') {
            const linhas = {};
            Object.entries(data?.linhas ?? {}).forEach(([indice, desconto]) => {
                const item = itens[Number(indice)];
                if (item) linhas[item.cartKey] = (linhas[item.cartKey] ?? 0) + desconto;
            });
            const aplicacoes = (data?.aplicacoes ?? []).map(a => ({
                ...a,
                nomesItens: a.itens.map(indice => itens[indice]?.nome).filter(Boolean),
            }));
            const pendencias = data?.pendencias ?? [];
            this.combo = { desconto: data?.desconto ?? 0, linhas, aplicacoes, pendencias, assinatura };

            const porPromocao = {};
            aplicacoes.forEach(a => { porPromocao[a.promocao_id] = (porPromocao[a.promocao_id] ?? 0) + 1; });
            const unidades = Object.fromEntries(_promocoesCombo.map(promo => [
                promo.promocaoId,
                itens.filter(i => promo.produtos.includes(i.id)).reduce((s, i) => s + i.qty, 0),
            ]));
            const antes = this._comboPorPromocao;
            const unidadesAntes = this._comboUnidades;
            this._comboPorPromocao = porPromocao;
            this._comboUnidades = unidades;
            if (inicial || antes === null) return;

            const nova = aplicacoes.filter(a => (porPromocao[a.promocao_id] ?? 0) > (antes[a.promocao_id] ?? 0)).pop();
            if (nova) {
                this.quandoLivre(() => { this.comboConquista = { open: true, aplicacao: nova }; });
                return;
            }
            for (const p of this.combosPendentes) {
                if ((unidades[p.promocao_id] ?? 0) <= (unidadesAntes[p.promocao_id] ?? 0)) continue;
                if (this.comboRecusado(p.promocao_id, porPromocao[p.promocao_id] ?? 0)) continue;
                this.quandoLivre(() => this.abrirOportunidadeCombo(p.promocao_id));
                return;
            }
        },
        /** Convite e comemoração esperam o cliente terminar o que está fazendo (sabores, montagem, oferta). */
        quandoLivre(mostrar, tentativas = 0) {
            const ocupado = this.saboresModal.open || this.montagemModal.open || this.ofertaModal.open || this.sugestaoModal.open
                || this.trocoModal.open || this.comboOportunidade.open || this.comboConquista.open || this.anuncio.open || this.leveTambem.open || !!this.detalhe;
            if (!ocupado) { mostrar(); return; }
            if (tentativas < 40) setTimeout(() => this.quandoLivre(mostrar, tentativas + 1), 500);
        },
        abrirOportunidadeCombo(promocaoId) {
            const pendencia = this.combosPendentes.find(p => p.promocao_id === promocaoId);
            if (!pendencia) return;
            this.comboOportunidade = {
                open: true,
                promocao: pendencia.promocao,
                faltam: pendencia.faltam,
                grupos: pendencia.grupos ?? [],
                combosFechados: this._comboPorPromocao?.[promocaoId] ?? 0,
            };
        },
        /**
         * Produtos exibidos no cardápio que completam o combo: só os dos
         * grupos que ainda faltam (pizza grande na sacola → só brotinhos).
         */
        elegiveisCombo(promocao, gruposFaltando = []) {
            const faltando = gruposFaltando.map(g => g.grupo_id);
            const ids = faltando.length === 0
                ? (promocao?.produtos ?? [])
                : (promocao?.grupos ?? []).filter(g => faltando.includes(g.id)).flatMap(g => g.produtos);
            return [...new Set(ids)].map(id => _produtosTela[id]).filter(Boolean);
        },
        /** "1× Pizza Brotinho" — o que falta, pelos nomes dos grupos. */
        textoFaltam(promocao, gruposFaltando = [], faltam = 0) {
            const nomes = gruposFaltando
                .map(g => ({ ...g, grupo: (promocao?.grupos ?? []).find(x => x.id === g.grupo_id) }))
                .filter(g => g.grupo)
                .map(g => g.faltam + '× ' + g.grupo.nome);
            return nomes.length > 0 ? nomes.join(' + ') : faltam + (faltam > 1 ? ' itens' : ' item');
        },
        /** Escolha no convite segue o fluxo normal do produto (sabores, montagem, oferta). */
        escolherDoCombo(produto) {
            this.comboOportunidade = { ...this.comboOportunidade, open: false };
            if (produto.permiteSabores) {
                this.abrirSabores(produto.categoriaId, produto.categoriaNome, produto.maxSabores, produto.id);
            } else {
                this.add(produto.id, produto.nome, produto.preco, produto.precoVenda, produto.foto ?? '');
            }
        },
        recusarCombo() {
            const { promocao, combosFechados } = this.comboOportunidade;
            this.comboOportunidade = { open: false, promocao: null, faltam: 0, grupos: [], combosFechados: 0 };
            if (!promocao) return;
            try {
                const recusas = JSON.parse(sessionStorage.getItem('combo_recusas') || '{}');
                recusas[promocao.promocaoId] = combosFechados;
                sessionStorage.setItem('combo_recusas', JSON.stringify(recusas));
            } catch (_) {}
        },
        /** Recusado nesta sessão e o cliente ainda não fechou outro combo da mesma promoção. */
        comboRecusado(promocaoId, combosFechados) {
            try {
                const recusa = JSON.parse(sessionStorage.getItem('combo_recusas') || '{}')[promocaoId];
                return recusa !== undefined && combosFechados <= recusa;
            } catch (_) {
                return false;
            }
        },
        fecharConquista() {
            this.comboConquista = { open: false, aplicacao: null };
        },
        /**
         * Ao abrir o cardápio: apresenta a primeira promoção ainda não anunciada
         * nesta sessão e lista as demais. Mostrar marca todas como vistas: o
         * aviso só volta com uma promoção nova. Com a loja fechada também
         * anuncia (com "abrimos às"), contado à parte: quem viu fechado vê de
         * novo quando a loja abrir.
         */
        anunciarPromocoes() {
            if (_anunciosPromocoes.length === 0) return;
            const fechada = !this.estaAbertoAgora();
            const chave = a => a.chave + (fechada ? ':fechada' : '');
            let vistas = [];
            try { vistas = JSON.parse(sessionStorage.getItem('promocoes_anunciadas') || '[]'); } catch (_) {}
            const promocao = _anunciosPromocoes.find(a => !vistas.includes(chave(a)));
            if (!promocao) return;
            try {
                sessionStorage.setItem('promocoes_anunciadas', JSON.stringify([...new Set([...vistas, ..._anunciosPromocoes.map(chave)])]));
            } catch (_) {}
            this.anuncio = {
                open: true,
                promocao,
                outras: _anunciosPromocoes.filter(a => a.chave !== promocao.chave),
                fechada,
                abreEm: fechada ? this.proximoHorarioAgora() : null,
            };
        },
        fecharAnuncio() {
            this.anuncio = { open: false, promocao: null, outras: [], fechada: false, abreEm: null };
        },
        /** "Ver" no aviso: fecha e rola até a seção da promoção. */
        verPromocaoAnunciada(promocao) {
            this.fecharAnuncio();
            document.getElementById(promocao?.secao)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        // ── Leve também (Produto::leveTambem) ──
        /**
         * Toda vez que o produto entra na sacola (linha nova ou mais uma
         * unidade), sugere os produtos configurados nele — inclusive os que já
         * estão na sacola (o sheet mostra quantos). Espera os outros modais
         * (oferta, combo, montagem) fecharem.
         */
        sugerirLeveTambem(produtoId) {
            const produtos = (_leveTambem[produtoId] ?? []).map(id => _produtosTela[id]).filter(Boolean);
            if (produtos.length === 0) return;
            const origem = _produtosTela[produtoId]?.nome ?? '';
            this.quandoLivre(() => { this.leveTambem = { open: true, origem, produtos }; });
        },
        /**
         * Escolha segue o fluxo normal do produto (sabores, montagem, oferta).
         * As outras sugestões voltam quando ele terminar (ex.: dois refrigerantes).
         */
        escolherLeveTambem(produto) {
            const restantes = this.leveTambem.produtos.filter(p => p.id !== produto.id);
            this.leveTambem = { open: false, origem: this.leveTambem.origem, produtos: restantes };
            if (produto.permiteSabores) {
                this.abrirSabores(produto.categoriaId, produto.categoriaNome, produto.maxSabores, produto.id);
            } else {
                this.add(produto.id, produto.nome, produto.preco, produto.precoVenda, produto.foto ?? '');
            }
            if (restantes.length > 0) {
                this.quandoLivre(() => {
                    if (!this.leveTambem.open && this.leveTambem.produtos.length > 0) this.leveTambem = { ...this.leveTambem, open: true };
                });
            }
        },
        fecharLeveTambem() {
            this.leveTambem = { open: false, origem: '', produtos: [] };
        },

        // ── Frete grátis: mesma conta do getter valorFrete ──
        /** Quanto falta para a entrega grátis (null quando não há regra de valor mínimo). */
        get progressoFrete() {
            const opcao = _opcoesEntregas.find(o => o.requer_endereco && o.valor_frete > 0 && o.min_frete > 0);
            if (!opcao) return null;
            const base  = this.total - this.cupomDesconto;
            const falta = Math.max(0, Math.round((opcao.min_frete - base) * 100) / 100);
            return { minimo: opcao.min_frete, falta, atingido: falta === 0, pct: Math.min(100, Math.max(4, base / opcao.min_frete * 100)) };
        },

        // ── Status da loja (topo do cardápio) ──
        statusLoja() {
            if (this.estaAbertoAgora()) {
                const agora = new Date(this.relogio);
                const hora  = agora.getHours().toString().padStart(2, '0') + ':' + agora.getMinutes().toString().padStart(2, '0');
                const turno = _horarios.find(h => h.dia === agora.getDay() && h.abertura <= hora && h.fechamento >= hora);
                return { aberto: true, texto: turno ? 'até ' + turno.fechamento : '' };
            }
            const proximo = this.proximoHorarioAgora();
            return { aberto: false, texto: proximo ? 'abre ' + proximo : '' };
        },

        // ── Avisos ──
        avisar(texto, tipo = 'ok') {
            const id = this.aviso.id + 1;
            this.aviso = { texto, tipo, id };
            setTimeout(() => { if (this.aviso.id === id) this.aviso = { ...this.aviso, texto: '' }; }, 2600);
        },
        /** Fora do horário não se adiciona nada; na mesa o carrinho explica o bloqueio. */
        avisarFechado() {
            if (_mesaCliente) {
                this.drawerOpen = true;
                return;
            }
            const proximo = this.proximoHorarioAgora();
            this.avisar('Estamos fechados agora' + (proximo ? ' · voltamos ' + proximo : ''), 'fechado');
        },

        // ── Sheet de detalhe ──
        abrirDetalhe(id) {
            this.detalhe = _produtosTela[id] ?? null;
        },
        /** Mesmo caminho do "+" do card: add() ou montagem de sabores. */
        adicionarDoDetalhe() {
            const p = this.detalhe;
            if (!p) return;
            this.detalhe = null;
            if (p.permiteSabores) {
                this.abrirSabores(p.categoriaId, p.categoriaNome, p.maxSabores, p.id);
            } else {
                this.add(p.id, p.nome, p.preco, p.precoVenda, p.foto ?? '');
            }
        },

        // ── Carrinho: exibição ──
        fecharDrawer() {
            this.drawerOpen = false;
            if (this.step === 'sucesso') this.step = 'cart';
        },
        novoPedido() {
            this.ultimoPedido = null;
            this.step = 'cart';
            this.drawerOpen = false;
        },
        /** "Categoria — sabor 1 / sabor 2" vira título + linha de sabores. */
        partesNome(nome) {
            const [titulo, ...resto] = String(nome).split(' — ');
            return { titulo, detalhe: resto.join(' — ') };
        },
        /** Foto real (o placeholder antigo "Sem Imagem" não conta como foto). */
        temFoto(url) {
            return !!url && !/Sem(%20| )Imagem/.test(url);
        },
        formatar(valor) {
            return 'R$ ' + Number(valor || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        },

        // ── Sabores (meia a meia / terços) ──
        abrirSabores(categoriaId, categoriaNome, maxSabores, produtoId = null) {
            if (!this.estaAbertoAgora()) {
                this.avisarFechado();
                return;
            }
            const cat      = _categoriasComSabores.find(c => c.id === categoriaId);
            const produtos  = cat ? cat.produtos : [];
            const presel   = produtoId ? (produtos.find(p => p.id === produtoId) ?? null) : null;
            const opcoes   = (cat ? cat.opcoesSabores : []).filter(o => o.quantidade <= maxSabores);
            this.saboresModal = {
                open: true, categoriaId, categoriaNome, maxSabores,
                modo: 1, produtos, selecionados: presel ? [presel] : [], opcoes,
                regraPreco: cat ? cat.regraPreco : 'MEDIA', busca: '',
                formato: cat?.formato ?? 'redonda',
            };
            if (presel) {
                setTimeout(() => {
                    document.getElementById('sabor-item-' + presel.id)
                        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 320);
            }
        },
        get saboresFiltrados() {
            const termo = this.normalizar(this.saboresModal.busca);
            if (!termo) return this.saboresModal.produtos;
            return this.saboresModal.produtos.filter(p => this.normalizar(p.nome + ' ' + (p.ingredientes ?? '')).includes(termo));
        },
        normalizar(texto) {
            return String(texto ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
        },
        regraSaboresTexto() {
            const rotulo = this.saboresModal.modo === 2 ? 'Meia a meia' : this.saboresModal.modo + ' sabores';
            return rotulo + (this.saboresModal.regraPreco === 'MAIOR' ? ': vale o valor do sabor mais caro' : ': valor pela média dos sabores');
        },
        /**
         * Pizza em SVG dividida no número de sabores do modo, cada fatia
         * com a foto do sabor escolhido naquela posição. O desenho é o
         * módulo compartilhado com os painéis (resources/js/pizza-svg.js).
         */
        svgPizza() {
            return desenharPizza({
                formato: this.saboresModal.formato,
                quantidade: this.saboresModal.modo,
                sabores: this.saboresModal.selecionados,
            });
        },
        setModoSabores(modo) {
            this.saboresModal.modo = modo;
            this.saboresModal.selecionados = [];
        },
        toggleSabor(produto) {
            const idx = this.saboresModal.selecionados.findIndex(s => s.id === produto.id);
            if (idx >= 0) {
                this.saboresModal.selecionados.splice(idx, 1);
            } else if (this.saboresModal.modo === 1) {
                // Inteiro: troca direta sem precisar desmarcar manualmente
                this.saboresModal.selecionados = [produto];
            } else if (this.saboresModal.selecionados.length < this.saboresModal.modo) {
                this.saboresModal.selecionados.push(produto);
            }
        },
        // Preço de pizza multi-sabor pela regra da categoria (mesma do
        // back-end): MEDIA distribui cada sabor em centavos, evitando perda
        // de arredondamento; MAIOR cobra o sabor de maior preço final.
        // Retorna { preco (líquido), precoOriginal (bruto) } por pizza.
        precoSabores(sel) {
            const n = sel.length;
            if (n === 1) {
                const s = sel[0];
                return { preco: s.preco, precoOriginal: s.precoOriginal ?? s.preco };
            }
            if (this.saboresModal.regraPreco === 'MAIOR') {
                let maior = null;
                sel.forEach((s) => {
                    const precoFrac = s.precoFracao ?? s.preco;
                    const orig = Math.max(s.precoOriginal ?? precoFrac, precoFrac);
                    if (maior === null || precoFrac > maior.preco) {
                        maior = { preco: precoFrac, precoOriginal: orig };
                    }
                });
                return maior;
            }
            const fatia = (valor, idx) => {
                const tc = Math.round(valor * 100);
                const base = Math.floor(tc / n);
                const extra = tc % n;
                return (base + (idx < extra ? 1 : 0)) / 100;
            };
            let bruto = 0, desc = 0;
            sel.forEach((s, idx) => {
                // Como fração, o produto usa precoFracao: numa promoção
                // relâmpago "só inteira" isso é o preço normal (a promoção
                // não vale meia a meia); nos demais casos == s.preco.
                const precoFrac = s.precoFracao ?? s.preco;
                const orig = Math.max(s.precoOriginal ?? precoFrac, precoFrac);
                const descUnit = Math.max(0, orig - precoFrac);
                bruto += fatia(orig, idx);
                desc  += fatia(descUnit, idx);
            });
            bruto = Math.round(bruto * 100) / 100;
            desc  = Math.round(desc * 100) / 100;
            return { preco: Math.round((bruto - desc) * 100) / 100, precoOriginal: bruto };
        },
        confirmarSabores() {
            if (!this.estaAbertoAgora()) {
                this.saboresModal.open = false;
                this.avisarFechado();
                return;
            }
            const sel = this.saboresModal.selecionados;
            if (sel.length !== this.saboresModal.modo) return;
            const { preco, precoOriginal } = this.precoSabores(sel);
            const nomes   = sel.map(s => s.nome).join(' / ');
            const nome    = this.saboresModal.categoriaNome + ' — ' + nomes;
            // Ordem importa: cada posição tem seu percentual na categoria.
            const cartKey = 'sabor-' + sel.map(s => s.id).join('-');
            const idx = this.items.findIndex(i => i.cartKey === cartKey);
            const sabores = sel.length > 1 ? sel.map(s => ({ id: s.id, nome: s.nome, preco: s.preco, precoOriginal: s.precoOriginal ?? s.preco })) : null;
            const foto = sel[0]?.foto ?? '';
            const itemData = { cartKey, id: sel[0].id, nome, preco, precoOriginal, qty: 1, foto, sabores, obs: '' };
            // Pizza inteira responde as perguntas do produto; pizza de
            // sabores, as da categoria — e só leva os adicionais
            // vinculados a todos os sabores (mesma regra do servidor).
            const perguntas = sel.length > 1
                ? (_perguntasCombos[this.saboresModal.categoriaId] ?? [])
                : (_perguntasProdutos[sel[0].id] ?? []);
            const adicionais = sel
                .map(s => _adicionaisProdutos[s.id] ?? [])
                .reduce((comuns, doSabor) => comuns.filter(a => doSabor.some(b => b.id === a.id)));
            // Pizza inteira participa da promoção adicional; meia a meia/
            // terços só quando todos os sabores são gatilho da mesma
            // campanha e ela vale para meia a meia (mesma regra do servidor).
            const promos = sel.map(s => _promocoesAdicionais[s.id]);
            const comboComOferta = sel.length > 1
                && promos.every(p => p && p.aplicaFracionado && p.promocaoId === promos[0].promocaoId);
            const seguir = (item) => {
                if (sel.length === 1 || comboComOferta) {
                    this.adicionarOuOferecer(item);
                } else {
                    this.items.push(item);
                    this.save();
                    this.sugerirLeveTambem(item.id);
                }
            };
            this.saboresModal.open = false;
            if (perguntas.length > 0 || adicionais.length > 0) {
                this.montar(itemData, perguntas, adicionais, seguir);
                return;
            }
            if (idx >= 0) {
                this.items[idx].qty++;
                this.save();
                return;
            }
            seguir(itemData);
        },

        // ── Máscara monetária para troco ──
        maskTroco(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (!v) { this.form.trocoPara = ''; e.target.value = ''; return; }
            v = (parseInt(v) / 100).toFixed(2);
            v = v.replace('.', ',').replace(/(\d)(?=(\d{3})+(?=,))/g, '$1.');
            e.target.value = v;
            this.form.trocoPara = v;
        },

        // ── Reset imediato ao alterar telefone ──
        resetCamposCliente() {
            this.form.clienteId    = null;
            this.form.nome         = '';
            this.form.endereco     = '';
            this.clienteEncontrado = false;
        },

        // ── Lookup de cliente por telefone ──
        async lookupCliente() {
            const tel = this.form.telefone.replace(/\D/g, '');
            if (tel.length < 10) { this.clienteEncontrado = false; return; }

            this.lookingUp = true;
            try {
                // 429 (muitas tentativas) segue como "não encontrado": o cliente digita os dados.
                const res  = await fetch(`${_lookupRoute}?telefone=${tel}`, { headers: { Accept: 'application/json' } });
                const data = res.ok ? await res.json() : { encontrado: false };
                if (data.encontrado) {
                    this.form.clienteId = data.cliente_id;
                    this.form.nome      = data.nome;
                    this.form.endereco  = data.endereco || '';
                    this.clienteEncontrado = true;
                } else {
                    this.form.clienteId    = null;
                    this.clienteEncontrado = false;
                }
            } catch (_) {
                this.clienteEncontrado = false;
            } finally {
                this.lookingUp = false;
            }
        },

        // ── Validação do formulário ──
        validar() {
            const e = {};
            if (!this.form.telefone || this.form.telefone.replace(/\D/g, '').length < 10)
                e.telefone = 'Informe seu WhatsApp com DDD.';
            if (!this.form.nome?.trim())
                e.nome = 'Informe seu nome.';
            if (!this.form.opcaoEntregaId)
                e.opcao = 'Selecione como deseja receber.';
            if (this.form.requerEndereco && !this.form.endereco?.trim())
                e.endereco = 'Informe o endereço de entrega.';
            if (!this.form.pagamentoId)
                e.pagamento = 'Selecione a forma de pagamento.';
            if (this.form.isDinheiro && !this.form.trocoPara?.trim())
                e.troco = 'Informe o valor para troco (ou 0,00 se não precisar).';
            this.erros = e;
            return Object.keys(e).length === 0;
        },

        // ── Cupom ──
        async aplicarCupom() {
            const c = this.cupom;
            if (!c.codigo || c.carregando) return;
            c.carregando = true;
            c.erro = '';
            try {
                const res = await fetch(_cupomRoute, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': _csrfToken },
                    body: JSON.stringify({
                        codigo_cupom: c.codigo,
                        telefone: this.form.telefone || null,
                        opcao_pagamento_id: this.form.pagamentoId,
                        itens: this.items.map(i => ({
                            id: i.id, qty: i.qty, sabores: i.sabores ?? null, respostas: i.respostas ?? null,
                            adicionais: i.adicionais ?? null, oferta_produto_id: i.oferta?.produtoId ?? null,
                        })),
                    }),
                });
                const data = await res.json();
                if (!res.ok) {
                    c.aplicado = false; c.desconto = 0;
                    c.erro = data.message || 'Cupom inválido ou indisponível.';
                    return;
                }
                c.codigo = data.codigo;
                c.desconto = Number(data.valor_desconto) || 0;
                c.totalBase = this.total;
                c.aplicado = true;
            } catch (_) {
                c.erro = 'Falha de conexão. Tente novamente.';
            } finally {
                c.carregando = false;
            }
        },
        removerCupom() {
            this.cupom = { codigo: '', aplicado: false, desconto: 0, totalBase: 0, erro: '', carregando: false };
        },
        /** Cupom vindo do link (?cupom=): aplica sozinho ao abrir o checkout. */
        async aplicarCupomPendente() {
            if (this.cupom.codigo && !this.cupom.aplicado && !this.cupom.erro) await this.aplicarCupom();
        },

        // ── Checkout ──
        async submitCheckout() {
            if (!this.estaAbertoAgora()) {
                const proximo = this.proximoHorarioAgora();
                this.erros = { geral: 'Não estamos aceitando pedidos no momento.' + (proximo ? ' Voltamos ' + proximo + '.' : '') };
                return;
            }
            if (!this.validar()) return;

            // reCAPTCHA v2 token (se widget estiver presente)
            let recaptchaToken = null;
            if (typeof grecaptcha !== 'undefined') {
                recaptchaToken = grecaptcha.getResponse();
                if (!recaptchaToken) {
                    this.erros = { ...this.erros, recaptcha: 'Confirme que você não é um robô.' };
                    return;
                }
            }

            this.submitting = true;
            this.erros = {};

            try {
                const res = await fetch(_checkoutRoute, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': _csrfToken,
                    },
                    body: JSON.stringify({
                        nome:             this.form.nome.trim(),
                        telefone:         this.form.telefone,
                        opcao_entrega_id: this.form.opcaoEntregaId,
                        endereco:         this.form.endereco.trim() || null,
                        pagamento_nome:   this.form.pagamentoNome,
                        opcao_pagamento_id: this.form.pagamentoId,
                        troco_para:       this.form.isDinheiro && this.form.trocoPara
                            ? parseFloat(this.form.trocoPara.replace(/\./g, '').replace(',', '.')) || null
                            : null,
                        recaptcha_token:  recaptchaToken,
                        codigo_cupom:     this.cupom.aplicado ? this.cupom.codigo : null,
                        itens: this.items.map(i => ({
                            id:             i.id,
                            qty:            i.qty,
                            preco:          i.preco,
                            preco_original: i.precoOriginal ?? i.preco,
                            observacao:     i.obs?.trim() || null,
                            sabores:        i.sabores ?? null,
                            respostas:      i.respostas ?? null,
                            adicionais:     i.adicionais ?? null,
                            // O servidor NUNCA usa o valor/regra daqui — só qual
                            // produto foi escolhido. Ele revalida do zero (regra
                            // vigente, saldo, forma de pagamento) via
                            // PrecificadorService::regraAdicionalDoProduto().
                            oferta_produto_id: i.oferta?.produtoId ?? null,
                        })),
                    }),
                });

                const data = await res.json();

                if (!res.ok) {
                    this.erros = data.errors?.telefone
                        ? { telefone: data.errors.telefone[0] }
                        : { geral: data.message || 'Erro ao registrar pedido. Tente novamente.' };
                    return;
                }

                // Brinde que o servidor não concedeu (promoção esgotou no
                // caminho) sai da mensagem; o aviso vai junto.
                const semBrinde = data.itens_sem_brinde ?? [];
                const avisos    = data.avisos ?? [];

                // Abre WhatsApp com a mensagem + número do pedido
                const tel     = '5564981380071';
                const opcao   = _opcoesEntregas.find(o => o.id === this.form.opcaoEntregaId);
                let msg = `Olá! Gostaria de confirmar meu pedido *#${data.pedido_id}*:\n\n`;
                this.items.map((i, indice) => semBrinde.includes(indice) ? { ...i, oferta: null } : i).forEach(i => {
                    const precoExib = (i.precoOriginal ?? i.preco);
                    const sub = (precoExib * i.qty).toFixed(2).replace('.', ',');
                    msg += `• ${i.qty}x ${i.nome} — R$${sub}\n`;
                    (i.respostasTexto ?? []).forEach(r => { msg += `  ${r}\n`; });
                    if ((i.adicionaisTexto ?? []).length) msg += `  + ${i.adicionaisTexto.join(', ')}\n`;
                    if (i.oferta) {
                        const subOferta = i.oferta.valorAdicional > 0 ? 'R$' + i.oferta.valorAdicional.toFixed(2).replace('.', ',') : 'Grátis';
                        msg += `  🎉 Promoção: ${i.oferta.nomeExibicao} — ${subOferta}\n`;
                    }
                });
                (data.combos ?? []).forEach(c => {
                    msg += `\n🎉 ${c.nome}: de R$${c.valor_cheio.toFixed(2).replace('.', ',')} por R$${c.preco.toFixed(2).replace('.', ',')}`;
                });
                if ((data.combos ?? []).length) msg += '\n';
                avisos.forEach(a => { msg += `\n⚠️ ${a}`; });
                if (avisos.length) msg += '\n';
                if (data.total_desconto > 0)
                    msg += `\nDesconto: -R$${data.total_desconto.toFixed(2).replace('.', ',')}`;
                if (this.cupom.aplicado && data.valor_desconto_cupom > 0)
                    msg += ` (cupom ${this.cupom.codigo}: -R$${data.valor_desconto_cupom.toFixed(2).replace('.', ',')})`;
                if (data.valor_frete > 0)
                    msg += `\nTaxa de entrega: R$${data.valor_frete.toFixed(2).replace('.', ',')}`;
                msg += `\n*Total: R$${data.total_final.toFixed(2).replace('.', ',')}*`;
                msg += `\n*Entrega:* ${opcao?.nome ?? ''}`;
                if (this.form.endereco) msg += `\n*Endereço:* ${this.form.endereco}`;
                msg += `\n*Pagamento:* ${this.form.pagamentoNome}`;
                if (this.form.isDinheiro && this.form.trocoPara)
                    msg += ` (troco para R$${(parseFloat(this.form.trocoPara.replace(/\./g, '').replace(',', '.')) || 0).toFixed(2).replace('.', ',')})`;
                msg += '\n\nAguardo confirmação! 🍕';
                msg += `\n\n🔍 *Acompanhe seu pedido em tempo real:*\n${data.link_acompanhamento}`;

                const linkWhatsapp = `https://wa.me/${tel}?text=${encodeURIComponent(msg)}`;
                window.open(linkWhatsapp, '_blank');

                // O pedido já está gravado: a tela de sucesso garante o
                // envio mesmo se o pop-up acima tiver sido bloqueado.
                this.ultimoPedido = {
                    id:           data.pedido_id,
                    total:        data.total_final,
                    frete:        data.valor_frete,
                    recebimento:  opcao?.nome ?? '',
                    linkWhatsapp,
                    linkAcompanhar: data.link_acompanhamento,
                    avisos,
                    combos:       data.combos ?? [],
                };

                // Limpa tudo
                this.clear();
                this.step         = 'sucesso';
                this.form         = { clienteId: null, nome: '', telefone: '', opcaoEntregaId: null, requerEndereco: false, endereco: '', pagamentoId: null, pagamentoNome: '', isDinheiro: false, trocoPara: '' };
                this.clienteEncontrado = false;
                this.removerCupom();

            } catch (_) {
                this.erros = { geral: 'Falha de conexão. Verifique sua internet.' };
            } finally {
                this.submitting = false;
            }
        },
    });
}
