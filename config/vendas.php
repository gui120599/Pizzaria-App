<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alerta de venda em alteração
    |--------------------------------------------------------------------------
    |
    | Venda finalizada reaberta no PDV (AlteracaoVendaService) fica sem a
    | entrada no caixa da sessão até ser finalizada de novo ou descartada.
    | Passadas estas horas, vendas:alertar-alteracoes-abertas avisa (uma vez)
    | quem pode alterar venda finalizada.
    |
    */

    'alerta_alteracao_horas' => (int) env('VENDAS_ALERTA_ALTERACAO_HORAS', 2),

];
