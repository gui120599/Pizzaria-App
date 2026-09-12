<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OpcoesPagamento extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'opcoes_pagamentos';

    protected $fillable = [
        'opcaopag_nome',
        'opcaopag_aparece_cardapio',
        'opcaopag_descricao',
        'opcaopag_tipo_taxa',
        'opcaopag_desc_nfe',
        'plano_receita_id',
        'opcaopag_valor_percentual_taxa',
        'opcaopag_requer_bandeira',
        'opcaopag_requer_autorizacao',
        'opcaopag_stone_integrada',
    ];

    protected $casts = [
        'opcaopag_aparece_cardapio' => 'boolean',
        'opcaopag_tipo_taxa' => 'string',
        'opcaopag_desc_nfe' => 'string',
        'opcaopag_valor_percentual_taxa' => 'float',
        'opcaopag_requer_bandeira' => 'boolean',
        'opcaopag_requer_autorizacao' => 'boolean',
        'opcaopag_stone_integrada' => 'boolean',
    ];

    const TIPO_TAXA = [
        'N/A' => 'N/A',
        'DESCONTAR' => 'DESCONTAR',
        'ACRESCENTAR' => 'ACRESCENTAR',
    ];

    /*const DESC_NFE = [
        'Dinheiro' => 'cash',
        'cheque' => 'cheque',
        'cartão de crédito' => 'creditCard',
        'cartão de débito' => 'debitCard',
        'crédito em loja' => 'storeCredict',
        'vales alimentação' => 'foodVouchers',
        'vales refeição' => 'mealVouchers',
        'vales presente' => 'giftVouchers',
        'vales combustível' => 'fuelVouchers',
        'boleto bancário' => 'bankBill',
        'sem pagamento' => 'withoutPayment',
        'outros' => 'others'
    ];

    // Accessor for `opcaopag_desc_nfe` to ensure valid values
    public function getOpcaoPagDescNfeAttribute($value)
    {
        return array_search($value, self::DESC_NFE) ?: 'others';
    }

    // Mutator for `opcaopag_desc_nfe` to ensure valid values
    public function setOpcaoPagDescNfeAttribute($value)
    {
        $this->attributes['opcaopag_desc_nfe'] = self::DESC_NFE[$value] ?? 'others';
    }*/

    public function pagamentosVenda()
    {
        return $this->hasMany(PagamentosVenda::class, 'pg_venda_opcaopagamento_id', 'id');
    }

    /**
     * Conta do plano de receita que classifica o dinheiro recebido nesta opção
     * de pagamento — usada por App\Services\ImportacaoCaixaReceberService pra
     * decidir em qual conta cai o lançamento gerado da sessão de caixa.
     */
    public function planoReceita()
    {
        return $this->belongsTo(PlanoReceita::class, 'plano_receita_id');
    }

    /** Forma de pagamento que dispara o fluxo integrado de maquininha Stone no PDV. */
    public function ehIntegracaoStone(): bool
    {
        return (bool) $this->opcaopag_stone_integrada;
    }

    /** Tipo de transação Stone derivado do código NF-e: 'credit' | 'debit' | 'pix' | null. */
    public function tipoStone(): ?string
    {
        return match ($this->opcaopag_desc_nfe) {
            'creditCard' => 'credit',
            'debitCard' => 'debit',
            'InstantPayment' => 'pix',
            default => null,
        };
    }

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];
}
