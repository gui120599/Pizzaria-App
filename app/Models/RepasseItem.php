<?php

namespace App\Models;

use App\Enums\Ponto\NaturezaItem;
use App\Enums\Ponto\TipoItemRepasse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item do repasse. `valor` é sempre positivo; `tipo` define o sinal.
 * Automáticos são recriados a cada recálculo; manuais só no rascunho.
 */
class RepasseItem extends Model
{
    use HasFactory;

    protected $table = 'repasse_itens';

    protected $fillable = [
        'repasse_id',
        'tipo',
        'natureza',
        'descricao',
        'quantidade',
        'valor_unitario',
        'valor',
        'automatico',
        'adiantamento_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoItemRepasse::class,
            'natureza' => NaturezaItem::class,
            'quantidade' => 'decimal:2',
            'valor_unitario' => 'decimal:2',
            'valor' => 'decimal:2',
            'automatico' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // O tipo segue a natureza: um "INSS" nunca vira provento por engano.
        static::saving(function (RepasseItem $item): void {
            if ($item->natureza instanceof NaturezaItem) {
                $item->tipo = $item->natureza->tipo();
            }
        });
    }

    public function repasse(): BelongsTo
    {
        return $this->belongsTo(Repasse::class, 'repasse_id');
    }

    public function adiantamento(): BelongsTo
    {
        return $this->belongsTo(Adiantamento::class, 'adiantamento_id')->withTrashed();
    }

    public function valorComSinal(): float
    {
        return ($this->tipo === TipoItemRepasse::Desconto ? -1 : 1) * (float) $this->valor;
    }
}
