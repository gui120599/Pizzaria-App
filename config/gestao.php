<?php

/*
| Rotinas de gestão do Claude (roteiro docs/planos/claude-mcp-gestao.md, G2).
*/
return [

    // Sugestão de compras: quantos dias de estoque comprar e quantos dias de
    // consumo olhar para calcular a média diária.
    'sugestao_compras' => [
        'cobertura_dias' => (int) env('GESTAO_COMPRAS_COBERTURA_DIAS', 7),
        'janela_dias' => (int) env('GESTAO_COMPRAS_JANELA_DIAS', 28),
        // Saídas que contam como consumo do insumo.
        'origens_consumo' => ['venda', 'producao', 'consumo_interno', 'perda', 'cortesia'],
    ],

    // Monitor de anomalias: o dia é comparado com a média do mesmo dia da
    // semana nas N semanas anteriores; vira alerta quando passa de
    // média × fator E do valor mínimo (evita alerta por centavos).
    'anomalias' => [
        'semanas_base' => (int) env('GESTAO_ANOMALIAS_SEMANAS', 4),
        'fator' => (float) env('GESTAO_ANOMALIAS_FATOR', 2.0),
        'minimos' => [
            'cancelamentos' => 50.0,
            'cortesias' => 30.0,
            'descontos' => 50.0,
        ],
        // Diferença de caixa (apurado − esperado) que vira alerta, em reais.
        'diferenca_caixa' => (float) env('GESTAO_ANOMALIAS_DIFERENCA_CAIXA', 20.0),
    ],

    // Ações confirmadas pelo chat (G3).
    'acoes' => [
        // Validade da prévia: depois disso é preciso preparar de novo.
        'validade_minutos' => (int) env('GESTAO_ACOES_VALIDADE_MINUTOS', 30),
        // Contagem de estoque: diferença acima deste % do saldo vira alerta (não bloqueia).
        'contagem_diferenca_pct' => (float) env('GESTAO_CONTAGEM_DIFERENCA_PCT', 30.0),
        // Itens por contagem enviada de uma vez.
        'contagem_max_itens' => 150,
    ],
];
