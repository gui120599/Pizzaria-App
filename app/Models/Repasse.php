<?php

namespace App\Models;

use App\Enums\Ponto\StatusRepasse;
use App\Enums\Ponto\TipoItemRepasse;
use App\Enums\StatusLancamento;
use App\Models\Concerns\TemHistoricoPonto;
use App\Observers\PontoAuditoriaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Repasse do colaborador num período. O título no contas a pagar aponta
 * para cá (lancamentos.repasse_id); cancelar a aprovação deixa o título
 * Cancelado e uma nova aprovação gera outro.
 */
#[ObservedBy([PontoAuditoriaObserver::class])]
class Repasse extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoPonto;

    protected $table = 'repasses';

    protected $fillable = [
        'ponto_periodo_id',
        'colaborador_id',
        'remuneracao_snapshot',
        'resumo_jornada',
        'total_proventos',
        'total_descontos',
        'valor_liquido',
        'status',
        'conferido_holerite',
        'aprovado_por',
        'aprovado_em',
        'ciente_em',
        'ciente_ip',
        'pago_em',
        'observacao',
    ];

    protected function casts(): array
    {
        return [
            'remuneracao_snapshot' => 'array',
            'resumo_jornada' => 'array',
            'total_proventos' => 'decimal:2',
            'total_descontos' => 'decimal:2',
            'valor_liquido' => 'decimal:2',
            'status' => StatusRepasse::class,
            'conferido_holerite' => 'boolean',
            'aprovado_em' => 'datetime',
            'ciente_em' => 'datetime',
            'pago_em' => 'datetime',
        ];
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PontoPeriodo::class, 'ponto_periodo_id')->withTrashed();
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }

    public function itens(): HasMany
    {
        return $this->hasMany(RepasseItem::class, 'repasse_id')->orderBy('tipo', 'desc')->orderBy('id');
    }

    /** Adiantamentos descontados neste repasse. */
    public function adiantamentos(): HasMany
    {
        return $this->hasMany(Adiantamento::class, 'repasse_id');
    }

    /** Todos os títulos já gerados (inclui os cancelados por cancelamento de aprovação). */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class, 'repasse_id');
    }

    /** O título vigente: o mais recente que não está cancelado. */
    public function lancamento(): HasOne
    {
        return $this->hasOne(Lancamento::class, 'repasse_id')
            ->where('status', '!=', StatusLancamento::Cancelado)
            ->latestOfMany();
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    /** Recalcula proventos, descontos e líquido a partir dos itens e grava. */
    public function recalcularTotais(): void
    {
        $itens = $this->itens()->get();

        $proventos = round((float) $itens->where('tipo', TipoItemRepasse::Provento)->sum('valor'), 2);
        $descontos = round((float) $itens->where('tipo', TipoItemRepasse::Desconto)->sum('valor'), 2);

        $this->forceFill([
            'total_proventos' => $proventos,
            'total_descontos' => $descontos,
            'valor_liquido' => round($proventos - $descontos, 2),
        ])->save();
    }

    public function podeEditar(): bool
    {
        return $this->status === StatusRepasse::Rascunho;
    }
}
