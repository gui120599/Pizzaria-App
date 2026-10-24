<?php

namespace App\Models;

use App\Enums\OrigemCupomEnum;
use App\Enums\TipoDescontoEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cupom extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cupons';

    /**
     * `cupom_influenciador_id` é fillable mesmo sendo setado só
     * programaticamente (nunca exposto no form) — CreateCupom::
     * mutateFormDataBeforeCreate() injeta esse valor em $data antes do
     * create(), e sem estar aqui o Eloquent descarta o campo silenciosamente
     * (bug real: pego só ao testar o fluxo via Livewire, não em Cupom::create()
     * direto).
     */
    protected $fillable = [
        'cupom_influenciador_id',
        'cupom_origem',
        'cupom_codigo',
        'cupom_tipo_desconto',
        'cupom_valor_desconto',
        'cupom_valor_minimo_pedido',
        'cupom_limite_uso_total',
        'cupom_limite_uso_por_cliente',
        'cupom_data_inicio',
        'cupom_data_expiracao',
        'cupom_ativo',
    ];

    protected function casts(): array
    {
        return [
            'cupom_origem' => OrigemCupomEnum::class,
            'cupom_tipo_desconto' => TipoDescontoEnum::class,
            'cupom_valor_desconto' => 'decimal:2',
            'cupom_valor_minimo_pedido' => 'decimal:2',
            'cupom_data_inicio' => 'date',
            'cupom_data_expiracao' => 'date',
            'cupom_ativo' => 'boolean',
        ];
    }

    /** Maiúsculas e sem espaços: é assim que o código é gravado e procurado. */
    public static function normalizarCodigo(string $codigo): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $codigo));
    }

    /** Já foi gasto em algum pedido (cancelado não conta). */
    public function jaFoiUsado(): bool
    {
        return $this->exists && $this->pedidosValidos()->exists();
    }

    public function influenciador()
    {
        return $this->belongsTo(Influenciador::class, 'cupom_influenciador_id');
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'pedido_cupom_id');
    }

    /** Pedidos que efetivamente "gastam" o cupom — cancelados não contam. */
    public function pedidosValidos()
    {
        return $this->pedidos()->where('pedido_status', '!=', 'CANCELADO');
    }

    public function estaValido(): bool
    {
        if (! $this->cupom_ativo) {
            return false;
        }

        $hoje = now()->toDateString();

        if ($this->cupom_data_inicio && $hoje < $this->cupom_data_inicio->toDateString()) {
            return false;
        }

        if ($this->cupom_data_expiracao && $hoje > $this->cupom_data_expiracao->toDateString()) {
            return false;
        }

        if ($this->cupom_limite_uso_total !== null && $this->pedidosValidos()->count() >= $this->cupom_limite_uso_total) {
            return false;
        }

        return true;
    }

    public function usosRestantes(): ?int
    {
        if ($this->cupom_limite_uso_total === null) {
            return null;
        }

        return max(0, $this->cupom_limite_uso_total - $this->pedidosValidos()->count());
    }
}
