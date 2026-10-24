<?php

namespace App\Models;

use App\Enums\StatusComissaoEnum;
use App\Enums\TipoDescontoEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comissao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'comissoes';

    protected $fillable = [
        'comissao_influenciador_id',
        'comissao_pedido_id',
        'comissao_cupom_id',
        'comissao_base_calculo',
        'comissao_tipo',
        'comissao_valor_referencia',
        'comissao_valor',
        'comissao_status',
        'comissao_aprovada_em',
        'comissao_paga_em',
        'comissao_pgcom_id',
        'comissao_observacao',
    ];

    protected function casts(): array
    {
        return [
            'comissao_base_calculo' => 'decimal:2',
            'comissao_tipo' => TipoDescontoEnum::class,
            'comissao_valor_referencia' => 'decimal:2',
            'comissao_valor' => 'decimal:2',
            'comissao_status' => StatusComissaoEnum::class,
            'comissao_aprovada_em' => 'datetime',
            'comissao_paga_em' => 'datetime',
        ];
    }

    public function influenciador()
    {
        return $this->belongsTo(Influenciador::class, 'comissao_influenciador_id');
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'comissao_pedido_id');
    }

    public function cupom()
    {
        return $this->belongsTo(Cupom::class, 'comissao_cupom_id');
    }

    public function pagamento()
    {
        return $this->belongsTo(PagamentoComissao::class, 'comissao_pgcom_id');
    }
}
