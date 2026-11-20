<x-mcp::app :title="$title">
    <x-slot:head>
        <style>
            :root {
                --color-background-primary: light-dark(#ffffff, #171717);
                --color-background-secondary: light-dark(#f5f5f4, #262626);
                --color-text-primary: light-dark(#1c1917, #fafafa);
                --color-text-secondary: light-dark(#57534e, #a8a29e);
                --color-border-primary: light-dark(#e7e5e4, #404040);
                --font-sans: system-ui, -apple-system, "Segoe UI", sans-serif;
                --border-radius-md: 10px;
                --verde: light-dark(#15803d, #4ade80);
                --vermelho: light-dark(#b91c1c, #f87171);
                --ambar: light-dark(#b45309, #fbbf24);
            }
            * { box-sizing: border-box; }
            body { font-family: var(--font-sans); background: var(--color-background-primary); color: var(--color-text-primary); margin: 0; padding: 14px; font-size: 14px; }
            header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
            h1 { font-size: 16px; margin: 0; }
            .sub { color: var(--color-text-secondary); font-size: 12px; }
            .nav { display: flex; gap: 6px; }
            button { font: inherit; border: 1px solid var(--color-border-primary); background: var(--color-background-secondary); color: var(--color-text-primary); border-radius: 8px; padding: 4px 10px; cursor: pointer; }
            button:disabled { opacity: .4; cursor: default; }
            .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
            .card { background: var(--color-background-secondary); border: 1px solid var(--color-border-primary); border-radius: var(--border-radius-md); padding: 10px 12px; }
            .rotulo { color: var(--color-text-secondary); font-size: 12px; }
            .valor { font-size: 20px; font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }
            .var { font-size: 12px; margin-top: 2px; }
            .alta { color: var(--verde); } .baixa { color: var(--vermelho); }
            section { margin-top: 12px; }
            h2 { font-size: 13px; margin: 0 0 6px; color: var(--color-text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
            table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
            td { padding: 4px 0; border-bottom: 1px solid var(--color-border-primary); }
            td.num { text-align: right; white-space: nowrap; }
            ul.alertas { list-style: none; padding: 0; margin: 0; }
            ul.alertas li { padding: 6px 10px; border-radius: 8px; margin-bottom: 4px; background: var(--color-background-secondary); border-left: 3px solid var(--ambar); }
            ul.alertas li.grave { border-left-color: var(--vermelho); }
            .ok { color: var(--verde); }
            footer { display: flex; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
            .erro { color: var(--vermelho); }
            [hidden] { display: none !important; }
        </style>
        <script type="module">
            createMcpApp(async (app) => {
                const raiz = document.getElementById('app');
                const linkPainel = raiz.dataset.linkPainel;
                const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
                const numero = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
                let atual = null;

                app.autoResize();

                const el = (tag, attrs = {}, ...filhos) => {
                    const no = document.createElement(tag);
                    Object.entries(attrs).forEach(([k, v]) => k === 'class' ? no.className = v : no.setAttribute(k, v));
                    filhos.flat().forEach((f) => no.append(f instanceof Node ? f : document.createTextNode(String(f ?? ''))));
                    return no;
                };

                const dados = (resultado) => {
                    if (resultado?.isError) {
                        throw new Error(resultado.content?.[0]?.text ?? 'Erro ao buscar o painel.');
                    }
                    return resultado?.structuredContent ?? JSON.parse(resultado?.content?.[0]?.text ?? '{}');
                };

                const dataBr = (iso) => iso.split('-').reverse().join('/');
                const somarDias = (iso, dias) => {
                    const d = new Date(iso + 'T12:00:00');
                    d.setDate(d.getDate() + dias);
                    return d.toISOString().slice(0, 10);
                };

                const cartao = (rotulo, valor, variacao = null) => el('div', { class: 'card' },
                    el('div', { class: 'rotulo' }, rotulo),
                    el('div', { class: 'valor' }, valor),
                    variacao === null ? '' : el('div', { class: 'var ' + (variacao >= 0 ? 'alta' : 'baixa') },
                        (variacao >= 0 ? '▲ ' : '▼ ') + numero.format(Math.abs(variacao)) + '% vs semana passada'),
                );

                const alertas = (d) => {
                    const lista = [];
                    (d.caixa?.sessoes ?? []).filter((s) => Math.abs(s.diferenca ?? 0) >= 0.01).forEach((s) =>
                        lista.push([`${/^caixa/i.test(s.caixa ?? '') ? s.caixa : 'Caixa ' + (s.caixa ?? '')} (${s.operador ?? '—'}): diferença de ${moeda.format(s.diferenca)}`, Math.abs(s.diferenca) >= 20]));
                    if (d.fiscal?.com_falha) lista.push([`${d.fiscal.com_falha} NFC-e com falha`, true]);
                    if (d.fiscal?.pendentes_de_envio) lista.push([`${d.fiscal.pendentes_de_envio} NFC-e pendente(s) de envio`, false]);
                    if (d.stone_travadas_7_dias) lista.push([`${d.stone_travadas_7_dias} pagamento(s) Stone travado(s) nos últimos 7 dias`, true]);
                    if (d.compras?.pagamentos_a_confirmar) lista.push([`${d.compras.pagamentos_a_confirmar} pagamento(s) de compra a confirmar (${moeda.format(d.compras.valor_a_confirmar)})`, false]);
                    if (d.compras?.pendencias_abertas) lista.push([`${d.compras.pendencias_abertas} pendência(s) de compra em aberto`, false]);
                    if (d.contas_a_pagar?.a_pagar_vencidos) lista.push([`${d.contas_a_pagar.a_pagar_vencidos} conta(s) vencida(s): ${moeda.format(d.contas_a_pagar.valor_vencido)}`, true]);
                    if (d.contas_a_pagar?.a_pagar_hoje_e_amanha) lista.push([`${d.contas_a_pagar.a_pagar_hoje_e_amanha} conta(s) vencendo hoje/amanhã: ${moeda.format(d.contas_a_pagar.valor_hoje_e_amanha)}`, false]);
                    if (d.excecoes?.cancelados?.quantidade) lista.push([`${d.excecoes.cancelados.quantidade} pedido(s) cancelado(s): ${moeda.format(d.excecoes.cancelados.valor ?? 0)}`, false]);
                    return lista;
                };

                const desenhar = (d) => {
                    atual = d;
                    document.getElementById('titulo-data').textContent = `${d.dia_da_semana ?? ''}, ${dataBr(d.data)}`;
                    document.getElementById('atualizado').textContent = d.data === d.hoje ? `parcial, atualizado às ${d.atualizado_em}` : 'dia fechado';
                    document.getElementById('proximo').disabled = d.data >= d.hoje;

                    const corpo = document.getElementById('corpo');
                    corpo.replaceChildren();

                    if (d.vendas) {
                        const i = d.vendas.indicadores;
                        corpo.append(el('div', { class: 'grid' },
                            cartao('Faturamento', moeda.format(i.faturamento), d.vendas.variacao_faturamento_pct),
                            cartao('Vendas', i.vendas),
                            cartao('Ticket médio', moeda.format(i.ticket)),
                            cartao('Margem', numero.format(i.margem_pct) + '%'),
                        ));
                        if (d.vendas.top_produtos?.length) {
                            corpo.append(el('section', {}, el('h2', {}, 'Mais vendidos'), el('table', {},
                                d.vendas.top_produtos.map((p) => el('tr', {},
                                    el('td', {}, p.produto),
                                    el('td', { class: 'num' }, numero.format(p.quantidade) + ' un'),
                                    el('td', { class: 'num' }, moeda.format(p.receita)))))));
                        }
                    }

                    if (d.caixa) {
                        corpo.append(el('section', {}, el('h2', {}, 'Caixa'), el('div', { class: 'grid' },
                            cartao('Recebido', moeda.format(d.caixa.resumo.recebido ?? 0)),
                            cartao('Fiado', moeda.format(d.caixa.resumo.fiado ?? 0)),
                            cartao('Sessões', (d.caixa.sessoes ?? []).length),
                        )));
                    }

                    const lista = alertas(d);
                    corpo.append(el('section', {}, el('h2', {}, 'Pontos de atenção'), lista.length
                        ? el('ul', { class: 'alertas' }, lista.map(([texto, grave]) => el('li', { class: grave ? 'grave' : '' }, texto)))
                        : el('div', { class: 'ok' }, '✓ Nada fora do normal.')));

                    if (!d.vendas && !d.caixa) {
                        corpo.prepend(el('div', { class: 'sub' }, 'Seu perfil não vê vendas nem caixa no painel.'));
                    }
                };

                const carregar = async (data) => {
                    document.getElementById('erro').hidden = true;
                    try {
                        desenhar(dados(await app.callServerTool({ name: 'painel_do_dia', arguments: { data } })));
                    } catch (e) {
                        const erro = document.getElementById('erro');
                        erro.textContent = e.message;
                        erro.hidden = false;
                    }
                };

                app.onToolResult((resultado) => {
                    try { desenhar(dados(resultado)); } catch (e) {
                        const erro = document.getElementById('erro');
                        erro.textContent = e.message;
                        erro.hidden = false;
                    }
                });

                document.getElementById('anterior').addEventListener('click', () => atual && carregar(somarDias(atual.data, -1)));
                document.getElementById('proximo').addEventListener('click', () => atual && carregar(somarDias(atual.data, 1)));
                document.getElementById('atualizar').addEventListener('click', () => atual && carregar(atual.data));
                document.getElementById('abrir').addEventListener('click', () => app.openLink(linkPainel));
                document.getElementById('analisar').addEventListener('click', () => atual && app.sendMessage(
                    `Analise o painel de ${dataBr(atual.data)}: explique a variação das vendas e o que eu devo resolver primeiro nos pontos de atenção.`));
            });
        </script>
    </x-slot:head>

    <div id="app" data-link-painel="{{ $linkPainel }}">
        <header>
            <div>
                <h1 id="titulo-data">Painel do dia</h1>
                <div class="sub" id="atualizado">carregando…</div>
            </div>
            <div class="nav">
                <button id="anterior" title="Dia anterior">◀</button>
                <button id="proximo" title="Próximo dia">▶</button>
            </div>
        </header>
        <div id="erro" class="erro" hidden></div>
        <div id="corpo"></div>
        <footer>
            <button id="atualizar">Atualizar</button>
            <button id="analisar">Pedir análise ao Claude</button>
            <button id="abrir">Abrir no painel</button>
        </footer>
    </div>
</x-mcp::app>
