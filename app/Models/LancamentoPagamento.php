<?php

namespace App\Models;

use App\Enums\FormaPagamento;
use App\Enums\TipoLancamento;
use App\Services\TaxaMaquininhaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um pagamento (parcial ou total) de um lançamento. Um lançamento pode ter N pagamentos
 * (parcelas); status/valor pago do lançamento são derivados da soma destas linhas — ver
 * Lancamento::recalcularStatus(), disparado pelos eventos saved/deleted abaixo.
 */
class LancamentoPagamento extends Model
{
    protected $table = 'lancamento_pagamentos';

    protected $fillable = [
        'lancamento_id',
        'sessao_caixa_id',
        'valor',
        'data_pagamento',
        'forma_pagamento',
        'cartao_id',
        'numero_autorizacao_cartao',
        'maquininha_id',
        'taxa_percentual',
        'taxa_valor',
        'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'data_pagamento' => 'date',
            'forma_pagamento' => FormaPagamento::class,
            'taxa_percentual' => 'decimal:2',
            'taxa_valor' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Retrato da taxa da maquininha / tarifa do PIX CNPJ, só no que a
        // empresa recebe (título a receber) — ver TaxaMaquininhaService.
        static::creating(function (LancamentoPagamento $pagamento): void {
            if ($pagamento->taxa_percentual === null && $pagamento->lancamento?->tipo === TipoLancamento::Receber) {
                app(TaxaMaquininhaService::class)->gravarRetratoRecebimento($pagamento);
            }
        });

        static::saved(fn (LancamentoPagamento $pagamento) => $pagamento->lancamento?->recalcularStatus());
        static::deleted(fn (LancamentoPagamento $pagamento) => $pagamento->lancamento?->recalcularStatus());
    }

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class, 'lancamento_id');
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    /** Maquininha em que o recebimento em cartão/Pix passou (sem ela, a padrão). */
    public function maquininha(): BelongsTo
    {
        return $this->belongsTo(Maquininha::class, 'maquininha_id');
    }

    /** Bandeira do cartão, quando o pagamento foi recebido via débito/crédito — só pra conciliação interna. */
    public function cartao(): BelongsTo
    {
        return $this->belongsTo(CartoesPagamento::class, 'cartao_id');
    }
}
