<?php

namespace App\Models;

use App\Enums\SefazNotaRecebidaStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SefazNotaRecebida extends Model
{
    protected $table = 'sefaz_notas_recebidas';

    protected $fillable = [
        'snr_chave_acesso',
        'snr_nsu',
        'snr_cnpj_emitente',
        'snr_nome_emitente',
        'snr_valor',
        'snr_data_emissao',
        'snr_xml_path',
        'snr_status',
        'snr_compra_id',
        'snr_encontrada_em',
    ];

    protected function casts(): array
    {
        return [
            'snr_status' => SefazNotaRecebidaStatusEnum::class,
            'snr_valor' => 'decimal:2',
            'snr_data_emissao' => 'date',
            'snr_encontrada_em' => 'datetime',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'snr_compra_id');
    }
}
