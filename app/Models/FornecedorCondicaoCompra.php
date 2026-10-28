<?php

namespace App\Models;

use App\Enums\BaseVencimentoEnum;
use App\Enums\FormaPagamento;
use App\Enums\RegraDataEntradaEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Condições comerciais de compra de um fornecedor (1:1 com Prestador). Só
 * sugerem valores no modal "Confirmar compra": nada daqui paga ou confirma
 * sozinho.
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
    ];

    protected function casts(): array
    {
        return [
            'fcc_prazo_base' => BaseVencimentoEnum::class,
            'fcc_forma_pagamento' => FormaPagamento::class,
            'fcc_data_entrada_regra' => RegraDataEntradaEnum::class,
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
