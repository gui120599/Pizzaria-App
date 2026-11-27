    @if(! $mesaCliente && config('services.recaptcha.site_key'))
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    @endif

    {{-- JSON puro (menor que @js): JSON_HEX_TAG impede fechar a tag com </script> vindo de dado. --}}
    <script type="application/json" id="cardapio-produtos">{!! json_encode($produtosTela->map->paraTela(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    {{-- Dados do carrinho (resources/js/cardapio): rotas, opções, promoções, perguntas, adicionais e mesa. --}}
    <script type="application/json" id="cardapio-dados">{!! json_encode([
        'rotas' => [
            'checkout' => route('cardapio.checkout'),
            'cupom' => route('cardapio.cupom.validar'),
            'combo' => route('cardapio.carrinho.precificar'),
            'lookupCliente' => route('cardapio.lookup_cliente'),
        ],
        'csrf' => csrf_token(),
        'opcoesEntregas' => $opcoesEntregas,
        'categoriasComSabores' => $categoriasComSabores,
        'horarios' => $horarios,
        'promocoesAdicionais' => $promocoesAdicionais,
        'campanhasExigencia' => $campanhasExigencia ?? [],
        'promocoesCombo' => $promocoesCombo ?? [],
        'anunciosPromocoes' => $anunciosPromocoes ?? [],
        'leveTambem' => (object) ($leveTambem ?? []),
        'perguntasProdutos' => $perguntasProdutos,
        'perguntasCombos' => $perguntasCombos,
        'adicionaisProdutos' => $adicionaisProdutos,
        'mesaCliente' => $mesaCliente,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    @vite('resources/js/cardapio/index.js')
