<?php

namespace App\Models;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Models\Concerns\TemHistoricoChecklist;
use App\Observers\ChecklistAuditoriaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([ChecklistAuditoriaObserver::class])]
class ChecklistNaoConformidade extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoChecklist;

    protected $table = 'checklist_nao_conformidades';

    protected $fillable = [
        'checklist_execucao_item_id',
        'descricao',
        'acao_corretiva',
        'responsavel_id',
        'prazo',
        'status',
        'critica',
        'resolvida_por',
        'resolvida_em',
        'observacao_resolucao',
    ];

    protected function casts(): array
    {
        return [
            'prazo' => 'date',
            'status' => NaoConformidadeStatus::class,
            'critica' => 'boolean',
            'resolvida_em' => 'datetime',
        ];
    }

    public function execucaoItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistExecucaoItem::class, 'checklist_execucao_item_id');
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function resolvidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvida_por');
    }

    public function scopePendentes(Builder $query): Builder
    {
        return $query->whereIn('status', NaoConformidadeStatus::pendentes());
    }

    public function scopeVencidas(Builder $query): Builder
    {
        return $query->pendentes()->whereDate('prazo', '<', today());
    }

    public function vencida(): bool
    {
        return $this->prazo !== null
            && in_array($this->status, NaoConformidadeStatus::pendentes(), true)
            && $this->prazo->lt(today());
    }
}
