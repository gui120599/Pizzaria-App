<?php

namespace App\Models;

use App\Enums\FormaPagamentoComissaoEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PagamentoComissao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pagamentos_comissao';

    protected $fillable = [
        'pgcom_influenciador_id',
        'pgcom_periodo_referencia',
        'pgcom_valor_total',
        'pgcom_forma',
        'pgcom_comprovante_path',
        'pgcom_pago_em',
        'pgcom_cupom_credito_id',
    ];

    protected function casts(): array
    {
        return [
            'pgcom_periodo_referencia' => 'date',
            'pgcom_valor_total' => 'decimal:2',
            'pgcom_forma' => FormaPagamentoComissaoEnum::class,
            'pgcom_pago_em' => 'datetime',
        ];
    }

    public function influenciador()
    {
        return $this->belongsTo(Influenciador::class, 'pgcom_influenciador_id');
    }

    public function cupomCredito()
    {
        return $this->belongsTo(Cupom::class, 'pgcom_cupom_credito_id');
    }

    public function comissoes()
    {
        return $this->hasMany(Comissao::class, 'comissao_pgcom_id');
    }
}
