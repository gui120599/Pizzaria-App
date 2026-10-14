<?php

namespace App\Models;

use App\Enums\Cozinha\MotivoCancelamentoProducao;
use App\Enums\Cozinha\MotivoRefacao;
use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\PedidoOrigemEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Item de produção: uma unidade (ou o lote, em setor que não explode
 * unidades) de um item do pedido numa etapa da rota. As transições ficam no
 * ProducaoService; o model só sabe ler o próprio estado.
 */
class ProducaoItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'producao_itens';

    protected $fillable = [
        'pedido_id',
        'item_pedido_id',
        'setor_producao_id',
        'etapa',
        'etapa_total',
        'unidade_seq',
        'geracao',
        'quantidade',
        'status',
        'prioridade',
        'snapshot',
        'origem',
        'referencia',
        'ficha_tecnica_id',
        'ficha_versao',
        'tempo_previsto_min',
        'liberar_em',
        'liberado_em',
        'iniciado_em',
        'concluido_em',
        'expedido_em',
        'cancelado_em',
        'motivo_cancelamento',
        'cancelamento_ciente_em',
        'alterado_apos_inicio_em',
        'alteracao_ciente_em',
        'operador_id',
        'operador_nome',
        'iniciado_por',
        'concluido_por',
        'refeito_de_id',
        'motivo_refacao',
        'versao',
    ];

    protected function casts(): array
    {
        return [
            'etapa' => 'integer',
            'etapa_total' => 'integer',
            'unidade_seq' => 'integer',
            'geracao' => 'integer',
            'quantidade' => 'decimal:3',
            'status' => StatusItemProducao::class,
            'prioridade' => PrioridadeProducao::class,
            'snapshot' => 'array',
            'origem' => PedidoOrigemEnum::class,
            'ficha_versao' => 'integer',
            'tempo_previsto_min' => 'integer',
            'liberar_em' => 'datetime',
            'liberado_em' => 'datetime',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
            'expedido_em' => 'datetime',
            'cancelado_em' => 'datetime',
            'motivo_cancelamento' => MotivoCancelamentoProducao::class,
            'cancelamento_ciente_em' => 'datetime',
            'alterado_apos_inicio_em' => 'datetime',
            'alteracao_ciente_em' => 'datetime',
            'motivo_refacao' => MotivoRefacao::class,
            'versao' => 'integer',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function itemPedido(): BelongsTo
    {
        return $this->belongsTo(ItensPedido::class, 'item_pedido_id');
    }

    public function setor(): BelongsTo
    {
        return $this->belongsTo(SetorProducao::class, 'setor_producao_id');
    }

    public function fichaTecnica(): BelongsTo
    {
        return $this->belongsTo(FichaTecnica::class, 'ficha_tecnica_id');
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operador_id');
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function concluidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }

    public function refeitoDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refeito_de_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ProducaoEvento::class, 'producao_item_id')->orderBy('created_at')->orderBy('id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /** @param  int|list<int>  $setorIds */
    public function scopeDoSetor(Builder $query, int|array $setorIds): Builder
    {
        return $query->whereIn('setor_producao_id', (array) $setorIds);
    }

    /** Não terminais: tudo que ainda pode mudar de status. */
    public function scopeAtivos(Builder $query): Builder
    {
        return $query->whereNotIn('status', [StatusItemProducao::Expedido, StatusItemProducao::Cancelado]);
    }

    /** Ordem da fila: prioridade (urgente > alta > normal), depois o mais antigo. */
    public function scopeNaFila(Builder $query): Builder
    {
        return $query->where('status', StatusItemProducao::NaFila)
            ->orderByRaw("FIELD(prioridade, 'urgente', 'alta', 'normal')")
            ->orderBy('liberado_em')
            ->orderBy('id');
    }

    public function scopeEmPreparo(Builder $query): Builder
    {
        return $query->where('status', StatusItemProducao::EmPreparo)
            ->orderBy('iniciado_em')
            ->orderBy('id');
    }

    /** Prontos na janela de recall (config cozinha.janela_prontos_min), mais recentes primeiro. */
    public function scopeProntosRecentes(Builder $query, ?int $minutos = null): Builder
    {
        $minutos ??= (int) config('cozinha.janela_prontos_min', 10);

        return $query->where('status', StatusItemProducao::Pronto)
            ->where('concluido_em', '>=', now()->subMinutes($minutos))
            ->orderByDesc('concluido_em')
            ->orderByDesc('id');
    }

    /** Cards cancelados/alterados que ainda esperam o "Ciente" da cozinha. */
    public function scopeAguardandoCiencia(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $c) => $c
                ->where('status', StatusItemProducao::Cancelado)
                ->whereNotNull('iniciado_em')
                ->whereNull('cancelamento_ciente_em'))
            ->orWhere(fn (Builder $a) => $a
                ->whereNotNull('alterado_apos_inicio_em')
                ->whereNull('alteracao_ciente_em')));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Leitura de estado
    // ─────────────────────────────────────────────────────────────────────────

    public function podeTransitarPara(StatusItemProducao $destino): bool
    {
        return $this->status->podeTransitarPara($destino);
    }

    public function ehUltimaEtapa(): bool
    {
        return $this->etapa >= $this->etapa_total;
    }

    /** Idade do card: desde que entrou na fila (0 enquanto aguardando). */
    public function idadeSegundos(?CarbonInterface $agora = null): int
    {
        if ($this->liberado_em === null) {
            return 0;
        }

        $fim = $this->concluido_em ?? $agora ?? now();

        return max(0, (int) $this->liberado_em->diffInSeconds($fim, absolute: false));
    }

    /**
     * Cor do timer: verde abaixo do alerta, amarelo a partir do alerta,
     * vermelho a partir do alvo e "atrasado" (vermelho piscando) a partir de
     * alvo × cozinha.alerta_atraso_multiplicador.
     *
     * @return 'verde'|'amarelo'|'vermelho'|'atrasado'
     */
    public function corTimer(?CarbonInterface $agora = null): string
    {
        $setor = $this->setor;
        $minutos = $this->idadeSegundos($agora) / 60;
        $alvo = (int) ($setor?->tempo_alvo_min ?? 15);

        return match (true) {
            $minutos >= $alvo * (int) config('cozinha.alerta_atraso_multiplicador', 2) => 'atrasado',
            $minutos >= $alvo => 'vermelho',
            $minutos >= (int) ($setor?->tempo_alerta_min ?? 10) => 'amarelo',
            default => 'verde',
        };
    }

    /** Snapshot: lista de sabores com fração (vazio para item comum). */
    public function sabores(): array
    {
        return (array) ($this->snapshot['sabores'] ?? []);
    }
}
