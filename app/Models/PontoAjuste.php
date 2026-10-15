<?php

namespace App\Models;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\TipoAjuste;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ajuste de ponto. Nunca altera ponto_marcacoes: a apuração usa marcações
 * originais − desconsiderações aprovadas + inclusões aprovadas.
 */
class PontoAjuste extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ponto_ajustes';

    protected $fillable = [
        'colaborador_id',
        'data_referencia',
        'tipo',
        'ponto_marcacao_id',
        'data_hora',
        'minutos',
        'motivo',
        'justificativa',
        'anexo_caminho',
        'status',
        'origem',
        'solicitado_por',
        'aprovado_por',
        'decidido_em',
        'motivo_rejeicao',
        'lote_uuid',
        'colaborador_ciente_em',
        'desfeito_por',
        'desfeito_em',
        'justificativa_desfazer',
    ];

    protected function casts(): array
    {
        return [
            'data_referencia' => 'date',
            'tipo' => TipoAjuste::class,
            'data_hora' => 'datetime',
            'minutos' => 'integer',
            'motivo' => MotivoAjuste::class,
            'status' => StatusAjuste::class,
            'origem' => OrigemAjuste::class,
            'decidido_em' => 'datetime',
            'colaborador_ciente_em' => 'datetime',
            'desfeito_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }

    public function marcacao(): BelongsTo
    {
        return $this->belongsTo(PontoMarcacao::class, 'ponto_marcacao_id');
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    public function desfeitoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desfeito_por');
    }

    public function scopeAprovados(Builder $query): Builder
    {
        return $query->where('status', StatusAjuste::Aprovado);
    }

    public function scopePendentes(Builder $query): Builder
    {
        return $query->where('status', StatusAjuste::Pendente);
    }

    public function scopeDoLote(Builder $query, string $loteUuid): Builder
    {
        return $query->where('lote_uuid', $loteUuid);
    }

    public function ehCorrecaoDoGestor(): bool
    {
        return $this->origem === OrigemAjuste::Gestor;
    }
}
