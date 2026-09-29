<?php

namespace App\Models;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um pedido criado na Stone (Connect / Pagar.me v5) — o hub de correlação
 * entre o que está sendo cobrado (Venda do PDV, Pedido avulso ou conta de
 * SessaoMesa — ver stp_origem), a Maquininha, o(s) charge(s) pago(s) e o(s)
 * PagamentosVenda gerado(s). O log bruto de cada evento fica em
 * stone_webhooks; aqui mora o estado processado.
 */
class StonePedido extends Model
{
    protected $table = 'stone_pedidos';

    protected $fillable = [
        'stp_venda_id',
        'stp_pedido_id',
        'stp_sessao_mesa_id',
        'stp_maquininha_id',
        'stp_opcaopagamento_id',
        'stp_usuario_id',
        'stp_order_id',
        'stp_order_code',
        'stp_charge_id',
        'stp_charge_code',
        'stp_valor_solicitado',
        'stp_valor_pago',
        'stp_status',
        'stp_modo',
        'stp_origem',
        'stp_erro',
        'stp_fechado_em',
    ];

    protected function casts(): array
    {
        return [
            'stp_valor_solicitado' => 'decimal:2',
            'stp_valor_pago' => 'decimal:2',
            'stp_status' => StonePedidoStatus::class,
            'stp_modo' => StonePedidoModo::class,
            'stp_origem' => StonePedidoOrigem::class,
            'stp_fechado_em' => 'datetime',
        ];
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'stp_venda_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'stp_pedido_id');
    }

    public function sessaoMesa(): BelongsTo
    {
        return $this->belongsTo(SessaoMesa::class, 'stp_sessao_mesa_id');
    }

    public function maquininha(): BelongsTo
    {
        return $this->belongsTo(Maquininha::class, 'stp_maquininha_id');
    }

    public function opcaoPagamento(): BelongsTo
    {
        return $this->belongsTo(OpcoesPagamento::class, 'stp_opcaopagamento_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stp_usuario_id');
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(StoneWebhook::class, 'stw_stone_pedido_id');
    }

    public function estaAguardando(): bool
    {
        return $this->stp_status === StonePedidoStatus::Aguardando;
    }

    public function estaPago(): bool
    {
        return $this->stp_status === StonePedidoStatus::Pago;
    }
}
