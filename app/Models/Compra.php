<?php

namespace App\Models;

use App\Enums\CompraStatusEnum;
use App\Enums\ConfiancaOperacaoEnum;
use App\Enums\EfeitoEstoqueEnum;
use App\Enums\OperacaoEntradaEnum;
use App\Enums\VencimentoOrigemEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Compra extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'compras';

    protected $fillable = [
        'compra_prestador_id',
        'compra_numero',
        'compra_serie',
        'compra_chave_nfe',
        'compra_xml_path',
        'compra_data_emissao',
        'compra_data_entrada',
        'compra_valor_produtos',
        'compra_valor_frete',
        'compra_valor_desconto',
        'compra_valor_outros',
        'compra_valor_total',
        'compra_status',
        'compra_origem',
        'compra_centro_custo_id',
        'compra_observacao',
        'compra_user_id',
        'compra_confirmada_em',
        'compra_tp_nf',
        'compra_fin_nfe',
        'compra_nat_op',
        'compra_dest_documento',
        'compra_valor_nf',
        'compra_valor_st',
        'compra_valor_ipi',
        'compra_valor_seguro',
        'compra_valor_fcp_st',
        'compra_notas_referenciadas',
        'compra_pagamentos_nf',
        'compra_duplicatas_nf',
        'compra_vencimento_origem',
        'compra_operacao',
        'compra_operacao_confianca',
        'compra_operacao_criterios',
        'compra_operacao_definida_por',
        'compra_operacao_definida_em',
        'compra_operacao_motivo',
    ];

    protected function casts(): array
    {
        return [
            'compra_status' => CompraStatusEnum::class,
            'compra_data_emissao' => 'date',
            'compra_data_entrada' => 'date',
            'compra_confirmada_em' => 'datetime',
            'compra_valor_produtos' => 'decimal:2',
            'compra_valor_frete' => 'decimal:2',
            'compra_valor_desconto' => 'decimal:2',
            'compra_valor_outros' => 'decimal:2',
            'compra_valor_total' => 'decimal:2',
            'compra_valor_nf' => 'decimal:2',
            'compra_valor_st' => 'decimal:2',
            'compra_valor_ipi' => 'decimal:2',
            'compra_valor_seguro' => 'decimal:2',
            'compra_valor_fcp_st' => 'decimal:2',
            'compra_notas_referenciadas' => 'array',
            'compra_pagamentos_nf' => 'array',
            'compra_duplicatas_nf' => 'array',
            'compra_vencimento_origem' => VencimentoOrigemEnum::class,
            'compra_operacao_confianca' => ConfiancaOperacaoEnum::class,
            'compra_operacao_criterios' => 'array',
            'compra_operacao_definida_em' => 'datetime',
        ];
    }

    public function devolucoes(): HasMany
    {
        return $this->hasMany(CompraDevolucao::class, 'compra_id');
    }

    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'compra_prestador_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CompraItem::class, 'ci_compra_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'compra_centro_custo_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compra_user_id');
    }

    public function movimentacoes(): MorphMany
    {
        return $this->morphMany(MovimentacaoProduto::class, 'referencia', 'mov_referencia_type', 'mov_referencia_id');
    }

    /** Títulos a pagar (parcelas) gerados a partir desta compra. */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class, 'compra_id');
    }

    /** Devolução registrada a partir desta compra, quando ela é a nota fiscal de uma devolução. */
    public function devolucaoPorNota(): HasOne
    {
        return $this->hasOne(CompraDevolucao::class, 'compra_nota_id');
    }

    /** A nota é (ou tem item) de devolução ao fornecedor: o efeito é SAÍDA de estoque, nunca entrada. */
    public function ehNotaDeDevolucao(): bool
    {
        $devolucao = OperacaoEntradaEnum::DEVOLUCAO_AO_FORNECEDOR->value;

        return $this->compra_operacao === $devolucao
            || ($this->compra_operacao === OperacaoEntradaEnum::MISTA && $this->itens()->where('ci_operacao', $devolucao)->exists());
    }

    /**
     * A operação classificada não pode gerar entrada de estoque: devolução
     * (saída) ou documento não processável (de outra empresa, cancelado...).
     */
    public function operacaoImpedeEntrada(): bool
    {
        if ($this->ehNotaDeDevolucao()) {
            return true;
        }

        $operacao = $this->compra_operacao !== null ? OperacaoEntradaEnum::tryFrom($this->compra_operacao) : null;

        return $operacao !== null
            && ($operacao->efeitos()->bloqueiaConfirmacao || $operacao->efeitos()->estoque === EfeitoEstoqueEnum::SAIDA);
    }

    /** A compra tem dados fiscais do XML (tem sentido exibir a seção "Operação fiscal"). */
    public function temDadosFiscais(): bool
    {
        return $this->compra_tp_nf !== null || $this->compra_operacao !== null || $this->compra_nat_op !== null;
    }

    /** Diferença entre o total calculado e o vNF da nota; null sem vNF gravado. */
    public function divergenciaValorNf(): ?float
    {
        if ($this->compra_valor_nf === null) {
            return null;
        }

        return round((float) $this->compra_valor_total - (float) $this->compra_valor_nf, 2);
    }

    /**
     * Data de entrada sugerida: a emissão da nota quando o fornecedor tem essa
     * regra; sem condição cadastrada, a data já gravada ou hoje (como sempre foi).
     */
    public function dataEntradaSugerida(): Carbon
    {
        $padrao = $this->compra_data_entrada ?? now();

        return $this->prestador?->condicaoCompra?->dataEntradaSugerida($this->compra_data_emissao, $padrao) ?? $padrao;
    }

    /** A regra de data de entrada do fornecedor está ativa (aviso no modal de confirmação). */
    public function dataEntradaPelaRegraDeEmissao(): bool
    {
        return $this->prestador?->condicaoCompra?->usaDataDeEmissao() === true && $this->compra_data_emissao !== null;
    }

    public function isRascunho(): bool
    {
        return $this->compra_status === CompraStatusEnum::RASCUNHO;
    }

    /** Acréscimos rateáveis sobre os itens (frete + outros - desconto). */
    public function valorRateavel(): float
    {
        return (float) $this->compra_valor_frete
            + (float) $this->compra_valor_outros
            - (float) $this->compra_valor_desconto;
    }
}
