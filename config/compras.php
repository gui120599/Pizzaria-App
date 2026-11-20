<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Custo de aquisição (App\Services\Compras\CustoAquisicaoCalculator)
    |--------------------------------------------------------------------------
    |
    | Com false (padrão, até a resposta do contador sobre o regime), o custo
    | do estoque segue como sempre foi: vProd − desconto + rateio de frete e
    | outras despesas. Com true, entram também seguro, IPI, ICMS-ST e FCP-ST
    | (tributos não recuperáveis no Simples Nacional), usando o valor de cada
    | item quando a nota traz e rateando o resto pelo valor dos produtos.
    | Vale para toda compra confirmada, manual ou importada.
    |
    */
    'custo' => [
        'incluir_tributos_nao_recuperaveis' => (bool) env('COMPRAS_CUSTO_INCLUIR_TRIBUTOS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lançamento já registrado à mão (FinanceiroCompraService)
    |--------------------------------------------------------------------------
    |
    | Antes de gerar o título de uma nota importada, procura um título a pagar
    | do mesmo fornecedor, sem compra, com o valor da nota (± tolerância) e
    | vencimento a até N dias da emissão.
    |
    */
    'lancamento_existente' => [
        'tolerancia_valor' => 0.05,
        'janela_dias' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agente (Fase 3)
    |--------------------------------------------------------------------------
    |
    | Abaixo deste limite, a sugestão do agente fica aguardando aprovação,
    | qualquer que seja a autonomia do fornecedor.
    |
    */
    'agente' => [
        'limite_confianca' => 0.90,
    ],

    /*
    | Compra avulsa sem NF-e pelo Claude (Fase 5, /mcp/compras-avulsa).
    */
    'avulsa' => [
        'expiracao_rascunho_min' => 30,
        'dias_retroativos' => 30,
        'tolerancia_total' => 0.05,
        'janela_duplicidade_horas' => 48,
        // Plano de despesa usado quando nem o item nem o fornecedor definem um.
        'plano_despesa_padrao' => env('COMPRAS_AVULSA_PLANO_DESPESA_ID'),
        'anexo_max_kb' => 10240,
        'anexo_link_horas' => 24,
    ],
];
