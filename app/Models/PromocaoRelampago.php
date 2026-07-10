<?php

namespace App\Models;

use App\Enums\PromocaoStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromocaoRelampago extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'promocoes_relampago';

    protected $fillable = [
        'promocao_nome',
        'promocao_descricao',
        'promocao_ativa',
        'promocao_inicio',
        'promocao_fim',
        'promocao_qtd_total',
        'promocao_qtd_vendida',
        'promocao_limite_por_pedido',
        'promocao_exibe_contador',
        'promocao_limiar_escassez',
        'promocao_permite_sabores',
        'promocao_max_sabores',
        'promocao_ordem',
    ];

    protected function casts(): array
    {
        return [
            'promocao_ativa' => 'boolean',
            'promocao_exibe_contador' => 'boolean',
            'promocao_permite_sabores' => 'boolean',
            'promocao_inicio' => 'datetime',
            'promocao_fim' => 'datetime',
            'promocao_qtd_total' => 'integer',
            'promocao_qtd_vendida' => 'decimal:2',
            'promocao_limite_por_pedido' => 'integer',
            'promocao_limiar_escassez' => 'integer',
            'promocao_max_sabores' => 'integer',
        ];
    }

    public function promocaoProdutos(): HasMany
    {
        return $this->hasMany(PromocaoRelampagoProduto::class, 'prp_promocao_id');
    }

    public function produtos(): BelongsToMany
    {
        return $this->belongsToMany(
            Produto::class,
            'promocao_relampago_produtos',
            'prp_promocao_id',
            'prp_produto_id'
        )->withPivot(['id', 'prp_preco_promocional', 'prp_qtd_total', 'prp_qtd_vendida']);
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(PromocaoConsumo::class, 'consumo_promocao_id');
    }

    /** Ativa e dentro da janela de vigência. Não considera saldo. */
    public function scopeVigente(Builder $query, ?CarbonInterface $momento = null): Builder
    {
        $momento ??= now();

        return $query->where('promocao_ativa', true)
            ->where('promocao_inicio', '<=', $momento)
            ->where('promocao_fim', '>=', $momento);
    }

    /** Ainda tem unidades no pool. Promoção sem teto sempre passa. */
    public function scopeComSaldo(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('promocao_qtd_total')
                ->orWhereColumn('promocao_qtd_vendida', '<', 'promocao_qtd_total')
        );
    }

    /** Unidades restantes no pool; null quando a promoção não tem teto. */
    public function saldoDisponivel(): ?float
    {
        if ($this->promocao_qtd_total === null) {
            return null;
        }

        return max(0.0, (float) $this->promocao_qtd_total - (float) $this->promocao_qtd_vendida);
    }

    public function esgotada(): bool
    {
        $saldo = $this->saldoDisponivel();

        return $saldo !== null && $saldo <= 0;
    }

    public function vigente(?CarbonInterface $momento = null): bool
    {
        $momento ??= now();

        return $this->promocao_ativa
            && $this->promocao_inicio <= $momento
            && $this->promocao_fim >= $momento;
    }

    public function status(?CarbonInterface $momento = null): PromocaoStatusEnum
    {
        $momento ??= now();

        return match (true) {
            ! $this->promocao_ativa => PromocaoStatusEnum::Inativa,
            $this->promocao_fim < $momento => PromocaoStatusEnum::Encerrada,
            $this->promocao_inicio > $momento => PromocaoStatusEnum::Agendada,
            $this->esgotada() => PromocaoStatusEnum::Esgotada,
            default => PromocaoStatusEnum::Ativa,
        };
    }

    /**
     * Só mostra o contador ao cliente quando o admin pediu, a promoção tem teto
     * e o saldo já entrou no limiar de escassez. Sem limiar, mostra sempre —
     * "restam 38 de 40" não cria urgência nenhuma.
     */
    public function deveExibirContador(): bool
    {
        if (! $this->promocao_exibe_contador || $this->promocao_qtd_total === null) {
            return false;
        }

        if ($this->promocao_limiar_escassez === null) {
            return true;
        }

        return $this->saldoDisponivel() <= $this->promocao_limiar_escassez;
    }

    /**
     * Teto de sabores para um combo desta promoção. O limite da promoção nunca
     * pode exceder o da categoria, e produtos de categorias diferentes valem
     * pelo menor teto entre elas.
     *
     * @param  iterable<Produto>  $produtos
     */
    public function maxSaboresEfetivo(iterable $produtos): int
    {
        $tetos = [];

        foreach ($produtos as $produto) {
            $tetos[] = (int) ($produto->categoria?->categoria_max_sabores ?? 1);
        }

        if ($tetos === []) {
            return 1;
        }

        $tetoCategoria = min($tetos);

        return $this->promocao_max_sabores === null
            ? $tetoCategoria
            : min($this->promocao_max_sabores, $tetoCategoria);
    }
}
