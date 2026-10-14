<?php

namespace App\Models;

use App\Enums\Cozinha\ModoExibicaoCozinha;
use App\Enums\Cozinha\TamanhoFonteCozinha;
use App\Models\Concerns\TemHistoricoCozinha;
use App\Observers\CozinhaAuditoriaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tablet da cozinha pareado a um ou mais setores, logado na conta técnica
 * da estação. O token de pareamento fica só como hash (uso único).
 */
#[ObservedBy([CozinhaAuditoriaObserver::class])]
class DispositivoCozinha extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoCozinha;

    protected $table = 'dispositivos_cozinha';

    /** Sem ping há mais que isso, o admin mostra o tablet como offline. */
    public const ONLINE_SEGUNDOS = 60;

    protected $fillable = [
        'nome',
        'user_id',
        'setores',
        'modo_exibicao',
        'tamanho_fonte',
        'som_ativo',
        'token_hash',
        'token_expira_em',
        'pareado_em',
        'ultimo_ping_em',
        'ultimo_ip',
        'ativo',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'setores' => 'array',
            'modo_exibicao' => ModoExibicaoCozinha::class,
            'tamanho_fonte' => TamanhoFonteCozinha::class,
            'som_ativo' => 'boolean',
            'token_expira_em' => 'datetime',
            'pareado_em' => 'datetime',
            'ultimo_ping_em' => 'datetime',
            'ativo' => 'boolean',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ProducaoEvento::class, 'dispositivo_cozinha_id');
    }

    /** Chave do container com o tablet da requisição (IdentificaDispositivoCozinha). */
    public const ATUAL = 'cozinha.dispositivo';

    /** Tablet que fez a requisição atual, se for um tablet pareado. */
    public static function atual(): ?self
    {
        return app()->bound(self::ATUAL) ? app(self::ATUAL) : null;
    }

    /** @return list<int> */
    public function setorIds(): array
    {
        return array_values(array_map('intval', (array) $this->setores));
    }

    /** @return Collection<int, SetorProducao> */
    public function setoresProducao(): Collection
    {
        return SetorProducao::query()->whereKey($this->setorIds())->ordenados()->get();
    }

    public function atendeSetor(int $setorProducaoId): bool
    {
        return in_array($setorProducaoId, $this->setorIds(), true);
    }

    public function estaOnline(): bool
    {
        return $this->ultimo_ping_em !== null
            && $this->ultimo_ping_em->gt(now()->subSeconds(self::ONLINE_SEGUNDOS));
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    /** Dispositivos que atendem o setor (coluna JSON de ids). */
    public function scopeDoSetor(Builder $query, int $setorProducaoId): Builder
    {
        return $query->whereJsonContains('setores', $setorProducaoId);
    }
}
