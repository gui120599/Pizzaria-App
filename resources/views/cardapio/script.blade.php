    @if(! $mesaCliente && config('services.recaptcha.site_key'))
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif

    {{-- JSON puro (menor que @js): JSON_HEX_TAG impede fechar a tag com </script> vindo de dado. --}}
    <script type="application/json" id="cardapio-produtos">{!! json_encode($produtosTela->map->paraTela(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    <script>
        const _checkoutRoute        = @js(route('cardapio.checkout'));
        const _cupomRoute           = @js(route('cardapio.cupom.validar'));
        const _lookupRoute          = @js(route('cardapio.lookup_cliente'));
        const _csrfToken            = @js(csrf_token());
        const _opcoesEntregas       = @js($opcoesEntregas);
        const _opcoesPagamento      = @js($opcoesPagamento);
        const _categoriasComSabores = @js($categoriasComSabores);
        const _estaAberto           = @js($estaAberto);
        const _proximoHorario       = @js($proximoHorario);
        const _horarios             = @js($horarios);
        const _avaliacaoLinks       = @js($avaliacaoLinks);
        // Promoção "leve outro produto por +R$X", indexada pelo produto gatilho.
        const _promocoesAdicionais  = @js($promocoesAdicionais);
        // Perguntas do item (borda, ponto da carne): por produto (item comum e
        // pizza inteira) e por categoria (pizza de sabores). O servidor revalida.
        const _perguntasProdutos    = @js($perguntasProdutos);
        const _perguntasCombos      = @js($perguntasCombos);
        // Adicionais vinculados a cada produto (bacon, cheddar...).
        const _adicionaisProdutos   = @js($adicionaisProdutos);
        // Página do QR da mesa (null no delivery): o carrinho é deste celular
        // nesta mesa e o envio vai para a conta da mesa, não para o WhatsApp.
        const _mesaCliente          = @js($mesaCliente);
        const _chaveCarrinho        = _mesaCliente ? 'mesa_cart_' + _mesaCliente.codigo : 'cardapio_cart';
        // Dados de exibição de cada produto (sheet de detalhe). Só exibição: o
        // carrinho segue chamando add()/abrirSabores() e o servidor reprecifica.
        const _produtosTela         = JSON.parse(document.getElementById('cardapio-produtos').textContent);

        document.addEventListener('alpine:init', () => {
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
                    setInterval(() => { this.relogio = Date.now(); }, 30000);
                    const produtoId = Number(new URLSearchParams(window.location.search).get('produto'));
                    if (produtoId) this.abrirDetalhe(produtoId);
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
                ofertaModal: { open: false, pending: null, ofertas: [] },

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
                adicionarOuOferecer(itemData) {
                    const promo = _promocoesAdicionais[itemData.id];
                    if (promo && promo.ofertas.length > 0) {
                        this.ofertaModal = { open: true, pending: itemData, ofertas: promo.ofertas };
                        return;
                    }
                    this.items.push(itemData);
                    this.save();
                },
                /** Escolha de uma opção (ou recusa) no modal — só aqui o item realmente entra no carrinho. */
                confirmarOferta(aceitar, ofertaId = null) {
                    const itemData = this.ofertaModal.pending;
                    const opcao    = aceitar ? this.ofertaModal.ofertas.find(o => o.ofertaId === ofertaId) : null;
                    this.ofertaModal = { open: false, pending: null, ofertas: [] };
                    if (!itemData) return;
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
                    this.items.push(itemData);
                    this.save();
                },
                /**
                 * Chamado ao escolher forma de pagamento: remove a oferta (e
                 * volta o item ao preço normal) de qualquer item do carrinho
                 * cuja campanha não aceita a forma escolhida — guardando o
                 * que foi removido em item._promoBackup. Ao voltar pra uma
                 * forma válida, restaura o preço/oferta de onde ficaram. O
                 * servidor também revalida isso de forma independente no
                 * checkout — isto é só pra manter a UI coerente antes de
                 * finalizar.
                 */
                revalidarOfertasContraPagamento() {
                    const pagamentoId = this.form.pagamentoId;
                    const removidos = [];
                    const restaurados = [];
                    this.items.forEach(item => {
                        const promo = _promocoesAdicionais[item.id];
                        if (!promo) return;
                        const permitidas = promo.opcoesPagamentoPermitidas ?? [];
                        const permitido = permitidas.length === 0 || permitidas.includes(pagamentoId);
                        if (!permitido) {
                            if (!item._promoBackup) {
                                item._promoBackup = { oferta: item.oferta, preco: item.preco };
                                item.oferta = null;
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
                get total()        { return this.items.reduce((s, i) => s + i.preco * i.qty + (i.oferta ? i.oferta.valorAdicional : 0), 0); },
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
                    const count = this.count;
                    if (count > this._countSalvo) {
                        this.pulso++;
                        if (!this.drawerOpen) this.avisar('Adicionado à sacola');
                    }
                    this._countSalvo = count;
                },
                clear() { this.items = []; this._countSalvo = 0; this.avisoPagamentoRemovido = []; this.avisoPagamentoRestaurado = []; localStorage.removeItem(_chaveCarrinho); },

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
                 * com a foto do sabor escolhido naquela posição (a ordem importa:
                 * é a mesma da linha do carrinho). Redonda: fatias em leque;
                 * quadrada: faixas (2×2 com 4 sabores).
                 */
                svgPizza() {
                    if (this.saboresModal.formato === 'quadrada') return this.svgPizzaQuadrada();
                    const n = Math.max(1, this.saboresModal.modo);
                    const sel = this.saboresModal.selecionados;
                    const c = 64, r = 60;
                    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
                    let defs = '', fatias = '', linhas = '';
                    for (let i = 0; i < n; i++) {
                        const sabor = sel[i];
                        if (this.temFoto(sabor?.foto)) {
                            defs += `<pattern id="fatia-${i}" patternUnits="userSpaceOnUse" width="128" height="128"><image href="${esc(sabor.foto)}" x="0" y="0" width="128" height="128" preserveAspectRatio="xMidYMid slice"/></pattern>`;
                        }
                        const fill = sabor ? (this.temFoto(sabor.foto) ? `url(#fatia-${i})` : '#5a4636') : '#2A221C';
                        if (n === 1) {
                            fatias += `<circle cx="${c}" cy="${c}" r="${r}" fill="${fill}"/>`;
                            continue;
                        }
                        const a0 = (-90 + i * 360 / n) * Math.PI / 180;
                        const a1 = (-90 + (i + 1) * 360 / n) * Math.PI / 180;
                        const x0 = c + r * Math.cos(a0), y0 = c + r * Math.sin(a0);
                        const x1 = c + r * Math.cos(a1), y1 = c + r * Math.sin(a1);
                        const grande = 360 / n > 180 ? 1 : 0;
                        fatias += `<path d="M${c} ${c} L${x0.toFixed(2)} ${y0.toFixed(2)} A${r} ${r} 0 ${grande} 1 ${x1.toFixed(2)} ${y1.toFixed(2)} Z" fill="${fill}"/>`;
                        linhas += `<line x1="${c}" y1="${c}" x2="${x0.toFixed(2)}" y2="${y0.toFixed(2)}" stroke="#14100D" stroke-width="3"/>`;
                    }
                    return `<svg viewBox="0 0 128 128" class="size-full" aria-hidden="true"><defs>${defs}</defs><circle cx="${c}" cy="${c}" r="${r + 2}" fill="#3a2a1c"/>${fatias}${linhas}<circle cx="${c}" cy="${c}" r="${r}" fill="none" stroke="#F2B33D" stroke-width="3"/></svg>`;
                },
                svgPizzaQuadrada() {
                    const n = Math.max(1, this.saboresModal.modo);
                    const sel = this.saboresModal.selecionados;
                    const m = 4, lado = 120, raio = 14;
                    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
                    // Grade das partes: 4 sabores = 2×2; os demais, faixas verticais.
                    const colunas = n === 4 ? 2 : n;
                    const linhas = n === 4 ? 2 : 1;
                    const w = lado / colunas, h = lado / linhas;
                    let defs = `<clipPath id="pizza-quadrada"><rect x="${m}" y="${m}" width="${lado}" height="${lado}" rx="${raio}"/></clipPath>`;
                    let partes = '', divisas = '';
                    for (let i = 0; i < n; i++) {
                        const sabor = sel[i];
                        const x = m + (i % colunas) * w, y = m + Math.floor(i / colunas) * h;
                        if (this.temFoto(sabor?.foto)) {
                            // As fotos são de pizzas redondas: cada parte mostra o miolo
                            // da foto (ampliada e centrada nela), sem a borda curva.
                            const zoom = Math.max(w, h) * 1.45;
                            const ix = x + w / 2 - zoom / 2, iy = y + h / 2 - zoom / 2;
                            defs += `<pattern id="fatia-${i}" patternUnits="userSpaceOnUse" width="128" height="128"><image href="${esc(sabor.foto)}" x="${ix.toFixed(2)}" y="${iy.toFixed(2)}" width="${zoom.toFixed(2)}" height="${zoom.toFixed(2)}" preserveAspectRatio="xMidYMid slice"/></pattern>`;
                        }
                        const fill = sabor ? (this.temFoto(sabor.foto) ? `url(#fatia-${i})` : '#5a4636') : '#2A221C';
                        partes += `<rect x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${w.toFixed(2)}" height="${h.toFixed(2)}" fill="${fill}"/>`;
                    }
                    for (let c = 1; c < colunas; c++) {
                        const x = (m + c * w).toFixed(2);
                        divisas += `<line x1="${x}" y1="${m}" x2="${x}" y2="${m + lado}" stroke="#14100D" stroke-width="3"/>`;
                    }
                    for (let l = 1; l < linhas; l++) {
                        const y = (m + l * h).toFixed(2);
                        divisas += `<line x1="${m}" y1="${y}" x2="${m + lado}" y2="${y}" stroke="#14100D" stroke-width="3"/>`;
                    }
                    return `<svg viewBox="0 0 128 128" class="size-full" aria-hidden="true"><defs>${defs}</defs><rect x="${m - 2}" y="${m - 2}" width="${lado + 4}" height="${lado + 4}" rx="${raio + 2}" fill="#3a2a1c"/><g clip-path="url(#pizza-quadrada)">${partes}${divisas}</g><rect x="${m}" y="${m}" width="${lado}" height="${lado}" rx="${raio}" fill="none" stroke="#F2B33D" stroke-width="3"/></svg>`;
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
                    // Só pizza inteira (1 sabor) participa da promoção adicional —
                    // meia a meia/terços ficam fora do escopo (mesma regra do servidor).
                    const seguir = (item) => {
                        if (sel.length === 1) {
                            this.adicionarOuOferecer(item);
                        } else {
                            this.items.push(item);
                            this.save();
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
                    if (tel.length < 8) { this.clienteEncontrado = false; return; }

                    this.lookingUp = true;
                    try {
                        const res  = await fetch(`${_lookupRoute}?telefone=${tel}`);
                        const data = await res.json();
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
                    if (!this.form.telefone || this.form.telefone.replace(/\D/g, '').length < 8)
                        e.telefone = 'Informe um telefone válido.';
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
                            this.erros = { geral: data.message || 'Erro ao registrar pedido. Tente novamente.' };
                            return;
                        }

                        // Abre WhatsApp com a mensagem + número do pedido
                        const tel     = '5564981380071';
                        const opcao   = _opcoesEntregas.find(o => o.id === this.form.opcaoEntregaId);
                        let msg = `Olá! Gostaria de confirmar meu pedido *#${data.pedido_id}*:\n\n`;
                        this.items.forEach(i => {
                            const precoExib = (i.precoOriginal ?? i.preco);
                            const sub = (precoExib * i.qty).toFixed(2).replace('.', ',');
                            msg += `• ${i.qty}x ${i.nome} — R$${sub}\n`;
                            (i.respostasTexto ?? []).forEach(r => { msg += `  ${r}\n`; });
                            if ((i.adicionaisTexto ?? []).length) msg += `  + ${i.adicionaisTexto.join(', ')}\n`;
                            if (i.oferta) {
                                const subOferta = i.oferta.valorAdicional.toFixed(2).replace('.', ',');
                                msg += `  🎉 Promoção: ${i.oferta.nomeExibicao} — R$${subOferta}\n`;
                            }
                        });
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
                        msg += `\n\n🔍 *Acompanhe seu pedido em tempo real:*\n${window.location.origin}/acompanhar/${data.pedido_id}`;

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
                            linkAcompanhar: `${window.location.origin}/acompanhar/${data.pedido_id}`,
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
        });

        /**
         * Comportamento da página (não do carrinho): altura das barras fixas,
         * aba da seção visível, busca no cardápio e trava de rolagem com
         * sheet aberto.
         */
        document.addEventListener('alpine:init', () => {
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
                    const aberto = c.saboresModal.open || c.montagemModal.open || c.ofertaModal.open || c.trocoModal.open || !!c.detalhe
                        || (c.drawerOpen && window.innerWidth < 1024) || (c.drawerOpen && c.step !== 'cart');
                    document.documentElement.style.overflow = aberto ? 'hidden' : '';
                },
            }));
        });
    </script>
