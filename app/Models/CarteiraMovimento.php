<?php

namespace App\Models;

use App\Enums\TipoMovimentoCarteiraEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Linha do extrato da carteira do influenciador. Append-only: nunca é editada
 * nem apagada; correção é sempre um movimento inverso. Valor com sinal
 * (crédito positivo, débito negativo) e saldo = soma dos movimentos.
 */
class CarteiraMovimento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'carteira_movimentos';

    protected $fillable = [
        'cm_influenciador_id',
        'cm_tipo',
        'cm_valor',
        'cm_comissao_id',
        'cm_resgate_id',
        'cm_pagamento_venda_id',
        'cm_user_id',
        'cm_observacao',
    ];

    protected function casts(): array
    {
        return [
            'cm_tipo' => TipoMovimentoCarteiraEnum::class,
            'cm_valor' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O extrato da carteira não pode ser editado: faça um movimento inverso.'));
        static::deleting(fn () => throw new LogicException('O extrato da carteira não pode ser apagado: faça um movimento inverso.'));
    }

    public function influenciador()
    {
        return $this->belongsTo(Influenciador::class, 'cm_influenciador_id');
    }

    public function comissao()
    {
        return $this->belongsTo(Comissao::class, 'cm_comissao_id');
    }

    public function resgate()
    {
        return $this->belongsTo(ResgateCarteira::class, 'cm_resgate_id');
    }

    public function pagamentoVenda()
    {
        return $this->belongsTo(PagamentosVenda::class, 'cm_pagamento_venda_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'cm_user_id');
    }
}
