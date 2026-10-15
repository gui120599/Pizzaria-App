<?php

namespace App\Models;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Models\Concerns\TemHistoricoChecklist;
use App\Observers\ChecklistAuditoriaObserver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

/**
 * Modelo (template) de checklist montado pelo gestor. As execuções guardam um
 * snapshot dos itens: editar o modelo nunca altera o histórico.
 */
#[ObservedBy([ChecklistAuditoriaObserver::class])]
class ChecklistModelo extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoChecklist;

    /** Roles que enxergam e executam todos os modelos, sem depender de atribuição. */
    public const PAPEIS_GESTORES = ['Admin', 'Gerente'];

    protected $table = 'checklist_modelos';

    protected $fillable = [
        'nome',
        'descricao',
        'tipo',
        'setor',
        'dias_semana',
        'janela_inicio',
        'janela_fim',
        'tolerancia_minutos',
        'exige_aprovacao',
        'permite_multiplas_execucoes_dia',
        'versao',
        'ativo',
        'criado_por',
        'setor_producao_id',
    ];

    /** Painéis onde um checklist pode ser executado ou consultado (ver scopeParaPainel). */
    public const PAINEL_ADMIN = 'admin';

    public const PAINEL_GARCOM = 'garcom';

    public const PAINEL_COZINHA = 'cozinha';

    /**
     * Setores executados nos tablets da cozinha. Enquanto o módulo de cozinha
     * (config/cozinha.php) não existir, vale o padrão do spec.
     *
     * @return list<string>
     */
    public static function setoresDaCozinha(): array
    {
        return array_values((array) config('cozinha.checklists.setores_checklist', [ChecklistSetor::Cozinha->value]));
    }

    /**
     * Sem o painel cozinha instalado ou com o módulo desligado
     * (cozinha.ativo), os checklists da cozinha continuam no /garcom; com o
     * módulo ligado, o config do módulo de cozinha decide.
     */
    public static function cozinhaTambemNoGarcom(): bool
    {
        return ! config('cozinha.ativo', false) || (bool) config('cozinha.checklists.exibir_tambem_no_garcom', true);
    }

    /** @return array<string, string> painéis de execução, para filtros do admin */
    public static function opcoesDePainel(): array
    {
        return [self::PAINEL_GARCOM => 'Garçom', self::PAINEL_COZINHA => 'Cozinha'];
    }

    /** "Garçom", "Cozinha", "Cozinha — Bar" ou "… e Garçom" (coluna Painel do admin). */
    public function painelDescricao(): string
    {
        if (! $this->ehDaCozinha()) {
            return 'Garçom';
        }

        $cozinha = $this->setor_producao_id === null ? 'Cozinha' : 'Cozinha — '.($this->setorProducao?->nome ?? '?');

        return self::cozinhaTambemNoGarcom() ? "{$cozinha} e Garçom" : $cozinha;
    }

    /** Executado nos tablets: setor da cozinha ou direcionado a um setor de produção. */
    public function ehDaCozinha(): bool
    {
        return $this->setor_producao_id !== null || in_array($this->setor->value, self::setoresDaCozinha(), true);
    }

    /** Rótulos curtos dos dias ISO (1=seg … 7=dom). */
    public const DIAS_SEMANA = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    /**
     * dias_semana sempre como inteiros ordenados (o CheckboxList do Filament
     * devolve strings, e whereJsonContains com int não casa com "2"); vazio
     * vira null = sob demanda.
     */
    protected static function booted(): void
    {
        static::saving(function (self $modelo): void {
            $dias = array_values(array_unique(array_map('intval', $modelo->dias_semana ?? [])));
            sort($dias);

            $modelo->dias_semana = $dias === [] ? null : $dias;
        });
    }

    protected function casts(): array
    {
        return [
            'tipo' => ChecklistTipo::class,
            'setor' => ChecklistSetor::class,
            'dias_semana' => 'array',
            'tolerancia_minutos' => 'integer',
            'exige_aprovacao' => 'boolean',
            'permite_multiplas_execucoes_dia' => 'boolean',
            'versao' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function secoes(): HasMany
    {
        return $this->hasMany(ChecklistModeloSecao::class, 'checklist_modelo_id')->orderBy('ordem');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ChecklistModeloItem::class, 'checklist_modelo_id')->orderBy('ordem');
    }

    public function papeis(): HasMany
    {
        return $this->hasMany(ChecklistModeloPapel::class, 'checklist_modelo_id');
    }

    /** Mesma tabela de papeis(), como N-N — é o que o Select do Filament usa. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'checklist_modelo_papeis', 'checklist_modelo_id', 'role_id')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function execucoes(): HasMany
    {
        return $this->hasMany(ChecklistExecucao::class, 'checklist_modelo_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /** Tablet de destino (módulo de cozinha); null = todos os tablets da cozinha. */
    public function setorProducao(): BelongsTo
    {
        return $this->belongsTo(SetorProducao::class, 'setor_producao_id');
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    /**
     * Onde o modelo é executado (docs/modulo-cozinha.md §5.10):
     * - admin: todos;
     * - cozinha: os direcionados a um setor do tablet ($dispositivo) e os
     *   sem setor de produção cujo `setor` é da cozinha (todos os tablets).
     *   Sem dispositivo, todos os da cozinha;
     * - garçom: os demais; os da cozinha só com
     *   `cozinha.checklists.exibir_tambem_no_garcom`.
     */
    public function scopeParaPainel(Builder $query, string $painel, ?Model $dispositivo = null): Builder
    {
        $setores = self::setoresDaCozinha();

        return match ($painel) {
            self::PAINEL_ADMIN => $query,
            self::PAINEL_COZINHA => $query->where(function (Builder $q) use ($setores, $dispositivo): void {
                $q->where(fn (Builder $geral) => $geral->whereNull('setor_producao_id')->whereIn('setor', $setores));

                if ($dispositivo instanceof DispositivoCozinha) {
                    $q->orWhereIn('setor_producao_id', $dispositivo->setorIds());
                } elseif ($dispositivo === null) {
                    $q->orWhereNotNull('setor_producao_id');
                }
            }),
            self::PAINEL_GARCOM => self::cozinhaTambemNoGarcom()
                ? $query
                : $query->whereNull('setor_producao_id')->whereNotIn('setor', $setores),
            default => throw new InvalidArgumentException("Painel de checklist desconhecido: {$painel}."),
        };
    }

    /** Modelos que o usuário pode executar: gestores veem todos; os demais, só os atribuídos às suas roles. */
    public function scopeDisponiveisPara(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(self::PAPEIS_GESTORES)) {
            return $query;
        }

        return $query->whereHas('papeis', fn (Builder $papeis) => $papeis->whereIn('role_id', $user->roles()->pluck('id')));
    }

    /** Modelos agendados para o dia da semana da data informada (sob demanda ficam de fora). */
    public function scopeAgendadosPara(Builder $query, CarbonInterface $dia): Builder
    {
        return $query->whereJsonContains('dias_semana', $dia->dayOfWeekIso);
    }

    /**
     * Itens na ordem em que aparecem no checklist: ordem da seção, depois a do
     * item dentro dela (o Repeater do Filament numera os itens por seção).
     * Itens sem seção vão para o fim.
     *
     * @return Collection<int, ChecklistModeloItem>
     */
    public function itensOrdenados(): Collection
    {
        return $this->itens()->with('secao')->get()
            ->sortBy([
                fn (ChecklistModeloItem $a, ChecklistModeloItem $b): int => ($a->secao?->ordem ?? PHP_INT_MAX) <=> ($b->secao?->ordem ?? PHP_INT_MAX),
                fn (ChecklistModeloItem $a, ChecklistModeloItem $b): int => ($a->checklist_modelo_secao_id ?? PHP_INT_MAX) <=> ($b->checklist_modelo_secao_id ?? PHP_INT_MAX),
                fn (ChecklistModeloItem $a, ChecklistModeloItem $b): int => [$a->ordem, $a->id] <=> [$b->ordem, $b->id],
            ])
            ->values();
    }

    public function sobDemanda(): bool
    {
        return empty($this->dias_semana);
    }

    public function agendadoPara(CarbonInterface $dia): bool
    {
        return ! $this->sobDemanda()
            && in_array($dia->dayOfWeekIso, array_map('intval', $this->dias_semana), true);
    }

    /** Ativo e (sob demanda ou agendado para o dia operacional de hoje). */
    public function disponivelHoje(): bool
    {
        return $this->ativo
            && ($this->sobDemanda() || $this->agendadoPara(ChecklistExecucao::dataOperacional()));
    }

    public function podeSerExecutadoPor(User $user): bool
    {
        if ($user->hasAnyRole(self::PAPEIS_GESTORES)) {
            return true;
        }

        return $this->papeis()->whereIn('role_id', $user->roles()->pluck('id'))->exists();
    }

    /** "Todos os dias", "Seg–Sex", "Seg, Qua, Sex" ou "Sob demanda". */
    public function diasSemanaDescricao(): string
    {
        if ($this->sobDemanda()) {
            return 'Sob demanda';
        }

        $dias = array_map('intval', $this->dias_semana);

        if (count($dias) === 7) {
            return 'Todos os dias';
        }

        $consecutivos = count($dias) > 2 && max($dias) - min($dias) === count($dias) - 1;

        return $consecutivos
            ? self::DIAS_SEMANA[min($dias)].'–'.self::DIAS_SEMANA[max($dias)]
            : implode(', ', array_map(fn (int $dia): string => self::DIAS_SEMANA[$dia], $dias));
    }

    /** "16:00–18:30", ou null sem janela. */
    public function janelaDescricao(): ?string
    {
        if ($this->janela_inicio === null && $this->janela_fim === null) {
            return null;
        }

        return substr((string) $this->janela_inicio, 0, 5).'–'.substr((string) $this->janela_fim, 0, 5);
    }

    /** Início da janela no dia operacional informado (null = sem janela). */
    public function inicioDaJanela(CarbonInterface $dataReferencia): ?CarbonImmutable
    {
        if ($this->janela_inicio === null) {
            return null;
        }

        return CarbonImmutable::parse($dataReferencia->format('Y-m-d').' '.$this->janela_inicio);
    }

    /**
     * Fim da janela no dia operacional informado. Janela que cruza a
     * meia-noite (ex.: 22:30–01:30) termina no dia seguinte do calendário.
     */
    public function fimDaJanela(CarbonInterface $dataReferencia): ?CarbonImmutable
    {
        if ($this->janela_fim === null) {
            return null;
        }

        $fim = CarbonImmutable::parse($dataReferencia->format('Y-m-d').' '.$this->janela_fim);

        if ($this->janela_inicio !== null && $this->janela_fim < $this->janela_inicio) {
            $fim = $fim->addDay();
        }

        return $fim;
    }

    /** Momento a partir do qual a execução do dia expira (fim da janela + tolerância). */
    public function prazoExpiracao(CarbonInterface $dataReferencia): ?CarbonImmutable
    {
        return $this->fimDaJanela($dataReferencia)?->addMinutes($this->tolerancia_minutos);
    }

    /**
     * Copia modelo, seções, itens e papéis. A cópia nasce inativa e na versão 1.
     * Copia só os campos fillable: replicate() levaria junto atributos de
     * agregados carregados na listagem (ex.: itens_count).
     */
    public function duplicar(?User $por = null): self
    {
        return DB::transaction(function () use ($por): self {
            $copia = self::create([
                ...$this->only($this->getFillable()),
                'nome' => mb_substr($this->nome.' (cópia)', 0, 120),
                'versao' => 1,
                'ativo' => false,
                'criado_por' => $por?->id,
            ]);

            $secoesCopiadas = [];

            foreach ($this->secoes()->get() as $secao) {
                $secoesCopiadas[$secao->id] = $copia->secoes()->create($secao->only(['titulo', 'ordem']));
            }

            foreach ($this->itens()->get() as $item) {
                ChecklistModeloItem::create([
                    ...$item->only($item->getFillable()),
                    'checklist_modelo_id' => $copia->id,
                    'checklist_modelo_secao_id' => $secoesCopiadas[$item->checklist_modelo_secao_id]->id ?? null,
                ]);
            }

            foreach ($this->papeis()->get() as $papel) {
                $copia->papeis()->create(['role_id' => $papel->role_id]);
            }

            return $copia;
        });
    }
}
