<?php

use App\Enums\OperacaoEntradaEnum as Op;

/*
|--------------------------------------------------------------------------
| Classificação da operação das NF-e de entrada
|--------------------------------------------------------------------------
|
| Mapa CFOP → operação usado por App\Services\Compras\ClassificadorOperacaoEntrada.
| O XML recebido traz o CFOP do EMITENTE (5xxx/6xxx/7xxx), então o mapa casa
| pelos três últimos dígitos, valendo também para o CFOP espelho de entrada
| (1xxx/2xxx/3xxx). Ver docs/planos/operacoes-fiscais-entrada-nfe.md (seção 4.3).
|
| ⚖️ Calibrar com o contador depois de observar notas reais.
*/

return [

    /*
    | Sufixo (3 últimos dígitos do CFOP) → operação sugerida para o item.
    | CFOP ausente aqui, ou 949, cai em OUTRA com confiança baixa.
    */
    'sufixos' => [
        // Transferência (5151-5159, 5408, 5409)
        '151' => Op::TRANSFERENCIA, '152' => Op::TRANSFERENCIA, '153' => Op::TRANSFERENCIA,
        '154' => Op::TRANSFERENCIA, '155' => Op::TRANSFERENCIA, '156' => Op::TRANSFERENCIA,
        '157' => Op::TRANSFERENCIA, '158' => Op::TRANSFERENCIA, '159' => Op::TRANSFERENCIA,
        '408' => Op::TRANSFERENCIA, '409' => Op::TRANSFERENCIA,

        // Devolução de compra (x201-x210, x410-x413): documento do fornecedor referente
        // a mercadoria que devolvemos. Nunca pode virar entrada de estoque.
        '201' => Op::DEVOLUCAO_AO_FORNECEDOR, '202' => Op::DEVOLUCAO_AO_FORNECEDOR,
        '203' => Op::DEVOLUCAO_AO_FORNECEDOR, '204' => Op::DEVOLUCAO_AO_FORNECEDOR,
        '205' => Op::DEVOLUCAO_AO_FORNECEDOR, '206' => Op::DEVOLUCAO_AO_FORNECEDOR,
        '207' => Op::DEVOLUCAO_AO_FORNECEDOR, '208' => Op::DEVOLUCAO_AO_FORNECEDOR,
        '209' => Op::DEVOLUCAO_AO_FORNECEDOR, '210' => Op::DEVOLUCAO_AO_FORNECEDOR,
        '410' => Op::DEVOLUCAO_AO_FORNECEDOR, '411' => Op::DEVOLUCAO_AO_FORNECEDOR,
        '412' => Op::DEVOLUCAO_AO_FORNECEDOR, '413' => Op::DEVOLUCAO_AO_FORNECEDOR,

        // Comodato (remessa e retorno)
        '908' => Op::COMODATO, '909' => Op::COMODATO,

        // Vasilhame / sacaria
        '920' => Op::VASILHAME, '921' => Op::VASILHAME,

        // Conserto
        '915' => Op::CONSERTO, '916' => Op::CONSERTO,

        // Amostra grátis
        '911' => Op::AMOSTRA_GRATIS,

        // Bonificação, doação ou brinde (mesmo CFOP; a distinção é comercial)
        '910' => Op::BONIFICACAO,

        // Vendas do emitente (x101-x125, x401-x405, x551, x556)
        '101' => Op::COMPRA, '102' => Op::COMPRA, '103' => Op::COMPRA, '104' => Op::COMPRA,
        '105' => Op::COMPRA, '106' => Op::COMPRA, '107' => Op::COMPRA, '108' => Op::COMPRA,
        '109' => Op::COMPRA, '110' => Op::COMPRA, '111' => Op::COMPRA, '112' => Op::COMPRA,
        '113' => Op::COMPRA, '114' => Op::COMPRA, '115' => Op::COMPRA, '116' => Op::COMPRA,
        '117' => Op::COMPRA, '118' => Op::COMPRA, '119' => Op::COMPRA, '120' => Op::COMPRA,
        '121' => Op::COMPRA, '122' => Op::COMPRA, '123' => Op::COMPRA, '124' => Op::COMPRA,
        '125' => Op::COMPRA,
        '401' => Op::COMPRA, '402' => Op::COMPRA, '403' => Op::COMPRA, '404' => Op::COMPRA,
        '405' => Op::COMPRA,
        '551' => Op::COMPRA, '556' => Op::COMPRA,
    ],

    /*
    | Palavras da natureza da operação (natOp, sem acento e minúsculas) e as
    | operações compatíveis com cada uma. Se a operação do CFOP não estiver na
    | lista, a confiança é rebaixada e a divergência vira alerta.
    | Chave 'remessa' = qualquer coisa que não seja compra.
    */
    'natureza' => [
        'bonific' => [Op::BONIFICACAO, Op::DOACAO, Op::AMOSTRA_GRATIS],
        'doa' => [Op::DOACAO, Op::BONIFICACAO],
        'brinde' => [Op::BONIFICACAO, Op::DOACAO],
        'comodato' => [Op::COMODATO],
        'devolu' => [Op::DEVOLUCAO_AO_FORNECEDOR, Op::OUTRA],
        'remessa' => [
            Op::BONIFICACAO, Op::DOACAO, Op::AMOSTRA_GRATIS, Op::COMODATO, Op::VASILHAME,
            Op::CONSERTO, Op::TRANSFERENCIA, Op::OUTRA, Op::DEVOLUCAO_AO_FORNECEDOR,
        ],
    ],
];
