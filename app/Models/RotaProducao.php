<?php

namespace App\Models;

use App\Models\Concerns\TemHistoricoCozinha;
use App\Observers\CozinhaAuditoriaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Uma etapa da rota de produção de um produto ou de uma categoria (que vale
 * para as descendentes). Exatamente um entre produto e categoria.
 */
#[ObservedBy([CozinhaAuditoriaObserver::class])]
class RotaProducao extends Model
{
    use HasFactory, TemHistoricoCozinha;

    protected $table = 'rotas_producao';

    protected $fillable = [
        'produto_id',
        'categoria_id',
        'setor_producao_id',
        'etapa',
        'tempo_previsto_min',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'etapa' => 'integer',
            'tempo_previsto_min' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $rota): void {
            if (($rota->produto_id === null) === ($rota->categoria_id === null)) {
                throw new InvalidArgumentException('A rota de produção pertence a um produto ou a uma categoria, nunca aos dois nem a nenhum.');
            }

            if ($rota->etapa < 1) {
                throw new InvalidArgumentException('A etapa da rota de produção começa em 1.');
            }
        });
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function setor(): BelongsTo
    {
        return $this->belongsTo(SetorProducao::class, 'setor_producao_id');
    }

    public function scopeAtivas(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }
}
