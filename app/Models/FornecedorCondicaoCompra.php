<?php

namespace App\Models;

use App\Enums\BaseVencimentoEnum;
use App\Enums\Compras\AutonomiaAgente;
use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\FormaPagamento;
use App\Enums\RegraDataEntradaEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Condições comerciais de compra de um fornecedor (1:1 com Prestador). As
 * condições originais (prazo, forma, conta, data de entrada) só sugerem
 * valores no modal "Confirmar compra". Os campos de automação (regra de
 * baixa, entrada automática...) só valem para as notas que passam pela
 * automação de compras (ver App\Services\Compras\CompraImportacaoService).
 */
class FornecedorCondicaoCompra extends Model
{
    use HasFactory;

    protected $table = 'fornecedor_condicoes_compra';

    protected $fillable = [
        'fcc_prestador_id',
        'fcc_prazo_pagamento_id',
        'fcc_prazo_base',
        'fcc_forma_pagamento',
        'fcc_conta_financeira_id',
        'fcc_data_entrada_regra',
        'fcc_regra_baixa',
        'fcc_plano_despesa_id',
        'fcc_autonomia_agente',
        'fcc_tolerancia_preco_pct',
        'fcc_entrada_automatica',
        'fcc_entrada_dias_apos_emissao',
        'fcc_observacoes',
    ];

    protected function casts(): array
    {
        return [
            'fcc_prazo_base' => BaseVencimentoEnum::class,
            'fcc_forma_pagamento' => FormaPagamento::class,
            'fcc_data_entrada_regra' => RegraDataEntradaEnum::class,
            'fcc_regra_baixa' => RegraBaixaCompra::class,
            'fcc_autonomia_agente' => AutonomiaAgente::class,
            'fcc_tolerancia_preco_pct' => 'decimal:2',
            'fcc_entrada_automatica' => 'boolean',
            'fcc_entrada_dias_apos_emissao' => 'integer',
        ];
    }

    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'fcc_prestador_id');
    }

    public function prazoPagamento(): BelongsTo
    {
        return $this->belongsTo(PrazoPagamento::class, 'fcc_prazo_pagamento_id');
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'fcc_conta_financeira_id');
    }

    public function planoDespesa(): BelongsTo
    {
        return $this->belongsTo(PlanoDespesa::class, 'fcc_plano_despesa_id');
    }

    /** Regra de baixa efetiva: sem regra cadastrada, manual. */
    public function regraBaixa(): RegraBaixaCompra
    {
        return $this->fcc_regra_baixa ?? RegraBaixaCompra::Manual;
    }

    public function autonomiaAgente(): AutonomiaAgente
    {
        return $this->fcc_autonomia_agente ?? AutonomiaAgente::PADRAO;
    }

    /**
     * Data da entrada automática no estoque: emissão + N dias, nunca antes
     * da emissão. Pode ser futura (a compra espera o dia em rascunho). Null
     * sem entrada automática ou sem emissão.
     */
    public function dataEntradaAutomatica(?Carbon $emissao): ?Carbon
    {
        if (! $this->fcc_entrada_automatica || $emissao === null) {
            return null;
        }

        return $emissao->copy()->startOfDay()->addDays(max(0, (int) $this->fcc_entrada_dias_apos_emissao));
    }

    public function usaDataDeEmissao(): bool
    {
        return $this->fcc_data_entrada_regra === RegraDataEntradaEnum::Emissao;
    }

    /**
     * Data de entrada sugerida pela regra do fornecedor: a emissão da nota
     * (nunca futura, a entrada não pode ser) ou, nos demais casos, o padrão.
     */
    public function dataEntradaSugerida(?Carbon $emissao, Carbon $padrao): Carbon
    {
        if (! $this->usaDataDeEmissao() || $emissao === null) {
            return $padrao;
        }

        return $emissao->copy()->startOfDay()->min(today());
    }
}
