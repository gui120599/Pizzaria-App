<?php

namespace App\Models;

use App\Enums\OperadoraMaquininha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Maquininha extends Model
{
    use SoftDeletes;

    protected $table = 'maquininhas';

    protected $fillable = [
        'nome',
        'operadora',
        'numero_serie',
        'identificador_externo',
        'maquininha_padrao',
    ];

    protected function casts(): array
    {
        return [
            'operadora' => OperadoraMaquininha::class,
            'maquininha_padrao' => 'boolean',
        ];
    }

    /** Só uma maquininha padrão por vez. */
    protected static function booted(): void
    {
        static::saved(function (Maquininha $maquininha): void {
            if ($maquininha->maquininha_padrao && ($maquininha->wasRecentlyCreated || $maquininha->wasChanged('maquininha_padrao'))) {
                static::whereKeyNot($maquininha->getKey())->where('maquininha_padrao', true)->update(['maquininha_padrao' => false]);
            }
        });
    }

    public function taxas(): HasMany
    {
        return $this->hasMany(MaquininhaTaxa::class, 'mt_maquininha_id');
    }

    /**
     * Copia para esta maquininha as taxas de outra (mesmo tipo e bandeira).
     * Substituindo, apaga as taxas atuais antes; sem substituir, só acrescenta
     * as combinações que ainda não existem. Retorna quantas taxas foram gravadas.
     */
    public function copiarTaxasDe(Maquininha $origem, bool $substituir = true): int
    {
        if ($origem->is($this)) {
            return 0;
        }

        return DB::transaction(function () use ($origem, $substituir): int {
            if ($substituir) {
                $this->taxas()->delete();
            }

            $copiadas = 0;

            foreach ($origem->taxas()->get() as $taxa) {
                $existente = $this->taxas()
                    ->where('mt_tipo', $taxa->mt_tipo)
                    ->when($taxa->mt_cartao_id, fn ($q) => $q->where('mt_cartao_id', $taxa->mt_cartao_id), fn ($q) => $q->whereNull('mt_cartao_id'))
                    ->exists();

                if ($existente) {
                    continue;
                }

                $this->taxas()->create($taxa->only(['mt_cartao_id', 'mt_tipo', 'mt_percentual', 'mt_prazo_recebimento_dias']));
                $copiadas++;
            }

            return $copiadas;
        });
    }

    /** Maquininha usada quando o pagamento não informa qual foi. */
    public static function padrao(): ?self
    {
        return static::where('maquininha_padrao', true)->first();
    }

    /**
     * Maquininhas aptas a receber um pedido Stone: operadora Stone e com
     * número de série cadastrado (é o devices_serial_number que endereça o
     * POST /orders ao aparelho físico — sem ele a Stone não sabe pra onde
     * mandar). Critério único usado em todo ponto de disparo (PDV, balcão,
     * entregador, mesa) — ver OperarVenda::maquininhasStone() (origem deste
     * scope, extraído para reaproveitar fora do PDV).
     */
    public function scopeStoneDisponivel(Builder $query): Builder
    {
        return $query->where('operadora', OperadoraMaquininha::Stone->value)
            ->whereNotNull('numero_serie');
    }
}
