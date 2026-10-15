<?php

namespace App\Models;

use App\Enums\FormaPagamento;
use App\Enums\TipoLancamento;
use App\Services\Financeiro\BaixaFinanceira;
use App\Services\TaxaMaquininhaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Um pagamento (parcial ou total) de um lançamento. Um lançamento pode ter N pagamentos
 * (parcelas); status/valor pago do lançamento são derivados da soma destas linhas — ver
 * Lancamento::recalcularStatus(), disparado pelos eventos saved/deleted abaixo.
 *
 * Com conta financeira, o pagamento movimenta a conta (ver BaixaFinanceira):
 * criar gera a movimentação, excluir estorna, e valor/data/sessão/conta não
 * mudam depois disso.
 */
class LancamentoPagamento extends Model
{
    protected $table = 'lancamento_pagamentos';

    /** Motivo gravado no estorno da movimentação quando este pagamento for excluído. */
    public ?string $motivoEstorno = null;

    protected $fillable = [
        'lancamento_id',
        'sessao_caixa_id',
        'conta_financeira_id',
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

            app(BaixaFinanceira::class)->preparar($pagamento);
        });

        static::created(fn (LancamentoPagamento $pagamento) => app(BaixaFinanceira::class)->movimentar($pagamento));
        static::updating(fn (LancamentoPagamento $pagamento) => app(BaixaFinanceira::class)->garantirImutavel($pagamento));
        static::deleting(fn (LancamentoPagamento $pagamento) => app(BaixaFinanceira::class)->estornar($pagamento));

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

    /** Conta de onde saiu (pagar) ou para onde foi (receber) o dinheiro; nula antes do módulo de contas financeiras. */
    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id')->withTrashed();
    }

    /** Movimentação gerada por este pagamento na conta financeira. */
    public function movimentacaoFinanceira(): HasOne
    {
        return $this->hasOne(MovimentacaoFinanceira::class, 'lancamento_pagamento_id')->whereNull('estorno_de_id');
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
