<?php

namespace App\Models;

use App\Enums\Ponto\NaturezaAdiantamento;
use App\Enums\Ponto\StatusAdiantamento;
use App\Models\Concerns\TemHistoricoPonto;
use App\Observers\PontoAuditoriaObserver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Adiantamento descontado no próximo repasse. Vale/outro saem de uma conta
 * financeira ("Sai de") e geram um título já pago (lancamentos.adiantamento_id);
 * consumo e saldo devedor não movimentam dinheiro.
 */
#[ObservedBy([PontoAuditoriaObserver::class])]
class Adiantamento extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoPonto;

    protected $table = 'adiantamentos';

    protected $fillable = [
        'colaborador_id',
        'data',
        'natureza',
        'valor',
        'descricao',
        'conta_financeira_id',
        'sessao_caixa_id',
        'repasse_id',
        'status',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'natureza' => NaturezaAdiantamento::class,
            'valor' => 'decimal:2',
            'status' => StatusAdiantamento::class,
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id')->withTrashed();
    }

    public function sessaoCaixa(): BelongsTo
    {
        return $this->belongsTo(SessaoCaixa::class, 'sessao_caixa_id');
    }

    public function repasse(): BelongsTo
    {
        return $this->belongsTo(Repasse::class, 'repasse_id')->withTrashed();
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class, 'adiantamento_id');
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoFinanceira::class, 'adiantamento_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function scopeAbertos(Builder $query): Builder
    {
        return $query->where('status', StatusAdiantamento::Aberto);
    }

    /** Abertos com data até o fim do período (entram como desconto no repasse). */
    public function scopeDescontaveisAte(Builder $query, CarbonInterface $fim): Builder
    {
        return $query->abertos()->whereDate('data', '<=', $fim->toDateString());
    }
}
