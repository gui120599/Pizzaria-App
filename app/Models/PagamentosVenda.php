<?php

namespace App\Models;

use App\Services\TaxaMaquininhaService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PagamentosVenda extends Model
{
    use HasFactory;

    protected $table = 'pagamentos_vendas';

    protected $fillable = [
        'pg_venda_venda_id',
        'pg_venda_opcaopagamento_id',
        'pg_venda_cartao_id',
        'pg_venda_maquininha_id',
        'pg_venda_numero_autorizacao_cartao',
        'pg_venda_tipo_integracao',
        'pg_venda_valor_pagamento',
        'pg_venda_valor_recebido',
        'pg_venda_valor_pago_pelo_cliente',
        'pg_venda_valor_troco',
        'pg_venda_valor_acrescimo',
        'pg_venda_valor_desconto',
        'pg_venda_taxa_maquininha_percentual',
        'pg_venda_taxa_maquininha_valor',
    ];

    protected $casts = [
        'pg_venda_valor_pagamento' => 'decimal:2',
        'pg_venda_valor_recebido' => 'decimal:2',
        'pg_venda_valor_pago_pelo_cliente' => 'decimal:2',
        'pg_venda_valor_troco' => 'decimal:2',
        'pg_venda_valor_acrescimo' => 'decimal:2',
        'pg_venda_valor_desconto' => 'decimal:2',
        'pg_venda_taxa_maquininha_percentual' => 'decimal:2',
        'pg_venda_taxa_maquininha_valor' => 'decimal:2',
    ];

    /**
     * Grava o retrato da taxa da maquininha em todo pagamento criado (PDV,
     * legado, Stone, dividir conta) — ver TaxaMaquininhaService.
     */
    protected static function booted(): void
    {
        static::creating(function (PagamentosVenda $pagamento): void {
            if ($pagamento->pg_venda_taxa_maquininha_percentual === null) {
                app(TaxaMaquininhaService::class)->gravarRetrato($pagamento);
            }
        });
    }

    public function venda()
    {
        return $this->belongsTo(Venda::class, 'pg_venda_venda_id');
    }

    public function opcaoPagamento()
    {
        return $this->belongsTo(OpcoesPagamento::class, 'pg_venda_opcaopagamento_id');
    }

    public function cartao()
    {
        return $this->belongsTo(CartoesPagamento::class, 'pg_venda_cartao_id');
    }

    public function maquininha()
    {
        return $this->belongsTo(Maquininha::class, 'pg_venda_maquininha_id');
    }
}
