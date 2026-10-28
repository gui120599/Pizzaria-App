<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Saque do saldo da carteira em Pix: ligado ao título pago no Financeiro. */
class ResgateCarteira extends Model
{
    protected $table = 'resgates_carteira';

    protected $fillable = [
        'rc_influenciador_id',
        'rc_valor',
        'rc_lancamento_id',
        'rc_comprovante_path',
        'rc_user_id',
        'rc_pago_em',
    ];

    protected function casts(): array
    {
        return [
            'rc_valor' => 'decimal:2',
            'rc_pago_em' => 'datetime',
        ];
    }

    public function influenciador()
    {
        return $this->belongsTo(Influenciador::class, 'rc_influenciador_id');
    }

    public function lancamento()
    {
        return $this->belongsTo(Lancamento::class, 'rc_lancamento_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'rc_user_id');
    }
}
