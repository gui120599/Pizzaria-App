<?php

namespace App\Models;

use App\Enums\FormaTrocoEnum;
use App\Enums\TipoPagamentoMaquininhaEnum;
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
        'opcaopag_envio_automatico_nfe',
        'plano_receita_id',
        'conta_financeira_id',
        'opcaopag_valor_percentual_taxa',
        'opcaopag_requer_bandeira',
        'opcaopag_requer_autorizacao',
        'opcaopag_stone_integrada',
        'opcaopag_pix_cnpj',
        'opcaopag_carteira_influenciador',
        'opcaopag_troco_padrao',
        'opcaopag_confirmavel_no_pedido',
        'opcaopag_tarifa_percentual',
    ];

    protected $casts = [
        'opcaopag_aparece_cardapio' => 'boolean',
        'opcaopag_tipo_taxa' => 'string',
        'opcaopag_desc_nfe' => 'string',
        'opcaopag_envio_automatico_nfe' => 'boolean',
        'opcaopag_valor_percentual_taxa' => 'float',
        'opcaopag_requer_bandeira' => 'boolean',
        'opcaopag_requer_autorizacao' => 'boolean',
        'opcaopag_stone_integrada' => 'boolean',
        'opcaopag_pix_cnpj' => 'boolean',
        'opcaopag_carteira_influenciador' => 'boolean',
        'opcaopag_troco_padrao' => FormaTrocoEnum::class,
        'opcaopag_confirmavel_no_pedido' => 'boolean',
        'opcaopag_tarifa_percentual' => 'decimal:2',
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
     * de pagamento — usada por App\Services\Financeiro\ContabilizacaoFechamentoService pra
     * decidir em qual conta cai o lançamento gerado da sessão de caixa.
     */
    public function planoReceita()
    {
        return $this->belongsTo(PlanoReceita::class, 'plano_receita_id');
    }

    /** Conta onde cai o dinheiro das formas sem maquininha (PIX CNPJ, vales); dinheiro vai sempre para o Caixa PDV. */
    public function contaFinanceira()
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id')->withTrashed();
    }

    /** "Carteira de influenciador": só o PDV aceita, com o código de resgate do próprio influenciador. */
    public function ehCarteiraInfluenciador(): bool
    {
        return (bool) $this->opcaopag_carteira_influenciador;
    }

    /** Formas que o pedido, o cardápio e o PDV legado podem oferecer (a carteira só vale no PDV). */
    public function scopeSemCarteira($query)
    {
        return $query->where('opcaopag_carteira_influenciador', false);
    }

    /**
     * Forma que PODE ser cobrada numa maquininha Stone Connect (não que sempre
     * seja): o PDV deixa o operador escolher entre lançar manual e cobrar na
     * Stone. Lançar manual continua valendo para qualquer maquininha.
     */
    public function permiteCobrarStone(): bool
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

    /** Pix direto na conta da empresa (chave CNPJ): conferido pelo extrato, com tarifa bancária, sem maquininha. */
    public function ehPixCnpj(): bool
    {
        return $this->opcaopag_desc_nfe === 'InstantPayment' && (bool) $this->opcaopag_pix_cnpj;
    }

    /** Tipo de transação na maquininha (define a taxa da adquirente); null = não passa por maquininha. */
    public function tipoMaquininha(): ?TipoPagamentoMaquininhaEnum
    {
        if ($this->ehPixCnpj()) {
            return null;
        }

        return match ($this->opcaopag_desc_nfe) {
            'creditCard' => TipoPagamentoMaquininhaEnum::Credito,
            'debitCard' => TipoPagamentoMaquininhaEnum::Debito,
            'InstantPayment' => TipoPagamentoMaquininhaEnum::Pix,
            default => null,
        };
    }

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];
}
