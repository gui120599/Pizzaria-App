<?php

namespace App\Models;

use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\Cozinha\TipoSetorProducao;
use App\Models\Concerns\TemHistoricoCozinha;
use App\Observers\CozinhaAuditoriaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Setor de produção da cozinha (Pizzaria, Cozinha, Bar) ou o passe
 * (Expedição). Cada tablet vê só os itens dos seus setores.
 */
#[ObservedBy([CozinhaAuditoriaObserver::class])]
class SetorProducao extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoCozinha;

    protected $table = 'setores_producao';

    protected $fillable = [
        'nome',
        'slug',
        'tipo',
        'cor',
        'icone',
        'ordem',
        'tempo_alerta_min',
        'tempo_alvo_min',
        'explode_unidades',
        'permite_pular_inicio',
        'exige_operador',
        'exibir_ficha_automatica',
        'som_novo_item',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoSetorProducao::class,
            'ordem' => 'integer',
            'tempo_alerta_min' => 'integer',
            'tempo_alvo_min' => 'integer',
            'explode_unidades' => 'boolean',
            'permite_pular_inicio' => 'boolean',
            'exige_operador' => 'boolean',
            'exibir_ficha_automatica' => 'boolean',
            'som_novo_item' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    public function rotas(): HasMany
    {
        return $this->hasMany(RotaProducao::class, 'setor_producao_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ProducaoItem::class, 'setor_producao_id');
    }

    /** Itens que ocupam a estação agora (na fila + em preparo). */
    public function itensAtivos(): HasMany
    {
        return $this->itens()->whereIn('status', StatusItemProducao::valoresAtivosNaEstacao());
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function scopeDeProducao(Builder $query): Builder
    {
        return $query->where('tipo', TipoSetorProducao::Producao);
    }

    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('ordem')->orderBy('nome');
    }

    public function ehExpedicao(): bool
    {
        return $this->tipo === TipoSetorProducao::Expedicao;
    }
}
