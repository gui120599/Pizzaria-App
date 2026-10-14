<?php

namespace App\Models;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExecucaoStatus;
use App\Models\Concerns\TemHistoricoChecklist;
use App\Observers\ChecklistAuditoriaObserver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Uma execução de um modelo num dia operacional. Cabeçalho e itens são
 * snapshot do modelo no momento da geração.
 */
#[ObservedBy([ChecklistAuditoriaObserver::class])]
class ChecklistExecucao extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoChecklist;

    protected $table = 'checklist_execucoes';

    protected $fillable = [
        'checklist_modelo_id',
        'modelo_versao',
        'modelo_nome',
        'tipo',
        'setor',
        'data_referencia',
        'status',
        'chave_unica',
        'iniciado_por',
        'iniciado_em',
        'concluido_por',
        'concluido_em',
        'aprovado_por',
        'aprovado_em',
        'motivo_reprovacao',
        'observacao_geral',
        'total_itens',
        'itens_respondidos',
        'itens_nao_conformes',
        'percentual_conformidade',
        'sessao_caixa_id',
        'operador_id',
        'operador_nome',
        'dispositivo_cozinha_id',
    ];

    protected function casts(): array
    {
        return [
            'modelo_versao' => 'integer',
            'tipo' => ChecklistTipo::class,
            'setor' => ChecklistSetor::class,
            'data_referencia' => 'date',
            'status' => ExecucaoStatus::class,
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
            'aprovado_em' => 'datetime',
            'total_itens' => 'integer',
            'itens_respondidos' => 'integer',
            'itens_nao_conformes' => 'integer',
            'percentual_conformidade' => 'decimal:2',
        ];
    }

    /**
     * Dia operacional do instante informado: antes de config('checklists.virada_dia')
     * ainda é o dia anterior (fechamento de sábado feito à 00:40 de domingo é de sábado).
     *
     * TODO: depois do commit do módulo de checklists, delegar para
     * App\Support\DiaOperacional::de() e trocar checklists.virada_dia por
     * operacao.virada_dia (dia operacional compartilhado com o ponto).
     */
    public static function dataOperacional(?CarbonInterface $momento = null): CarbonImmutable
    {
        $momento = CarbonImmutable::instance($momento ?? now());
        $virada = $momento->setTimeFromTimeString((string) config('checklists.virada_dia', '05:00'));

        return ($momento->lessThan($virada) ? $momento->subDay() : $momento)->startOfDay();
    }

    /** Chave de unicidade modelo+dia (null quando o modelo permite várias execuções no dia). */
    public static function chaveUnica(ChecklistModelo $modelo, CarbonInterface $dataReferencia): ?string
    {
        return $modelo->permite_multiplas_execucoes_dia
            ? null
            : $modelo->id.'-'.$dataReferencia->format('Y-m-d');
    }

    public function modelo(): BelongsTo
    {
        return $this->belongsTo(ChecklistModelo::class, 'checklist_modelo_id')->withTrashed();
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ChecklistExecucaoItem::class, 'checklist_execucao_id')->orderBy('ordem');
    }

    public function naoConformidades(): HasManyThrough
    {
        return $this->hasManyThrough(
            ChecklistNaoConformidade::class,
            ChecklistExecucaoItem::class,
            'checklist_execucao_id',
            'checklist_execucao_item_id',
        );
    }

    /** Pessoa que operava o tablet ao iniciar (módulo de cozinha); a conta logada fica em iniciado_por. */
    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operador_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoCozinha::class, 'dispositivo_cozinha_id')->withTrashed();
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function concluidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    public function scopeDoDia(Builder $query, ?CarbonInterface $dataReferencia = null): Builder
    {
        return $query->whereDate('data_referencia', ($dataReferencia ?? self::dataOperacional())->format('Y-m-d'));
    }

    public function scopeEmAberto(Builder $query): Builder
    {
        return $query->whereIn('status', ExecucaoStatus::emAberto());
    }

    /** Execuções que o usuário iniciou ou em que respondeu ao menos um item. */
    public function scopeParticipadasPor(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('iniciado_por', $user->id)
            ->orWhereHas('itens', fn (Builder $itens) => $itens->where('respondido_por', $user->id)));
    }

    public function participou(User $user): bool
    {
        return $this->iniciado_por === $user->id
            || $this->itens()->where('respondido_por', $user->id)->exists();
    }

    public function temRespostas(): bool
    {
        return $this->itens()->whereNotNull('respondido_em')->exists();
    }

    /**
     * Recalcula contadores e % de conformidade a partir dos itens (sem salvar).
     * % = conformes / avaliados × 100, onde avaliados = itens com `conforme` não nulo.
     */
    public function recalcularTotais(): void
    {
        $itens = $this->itens()->get(['id', 'respondido_em', 'conforme']);

        $avaliados = $itens->whereNotNull('conforme');
        $naoConformes = $avaliados->where('conforme', false)->count();

        $this->total_itens = $itens->count();
        $this->itens_respondidos = $itens->whereNotNull('respondido_em')->count();
        $this->itens_nao_conformes = $naoConformes;
        $this->percentual_conformidade = $avaliados->isEmpty()
            ? null
            : round(($avaliados->count() - $naoConformes) / $avaliados->count() * 100, 2);
    }

    public function progresso(): int
    {
        return $this->total_itens > 0
            ? (int) floor($this->itens_respondidos / $this->total_itens * 100)
            : 0;
    }
}
