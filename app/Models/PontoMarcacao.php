<?php

namespace App\Models;

use App\Enums\Ponto\OrigemMarcacao;
use App\Enums\Ponto\TipoMarcacao;
use App\Exceptions\RegistroImutavelException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Marcação de ponto — append-only. Nunca é editada nem apagada: correção é
 * só por ajuste (PontoAjuste). NSR e hash encadeado são gerados pelo
 * PontoService sob lock em ponto_sequencias.
 */
class PontoMarcacao extends Model
{
    use HasFactory;

    protected $table = 'ponto_marcacoes';

    public const UPDATED_AT = null;

    protected $fillable = [
        'nsr',
        'colaborador_id',
        'registrada_em',
        'data_referencia',
        'tipo_sugerido',
        'origem',
        'latitude',
        'longitude',
        'precisao_m',
        'dentro_da_cerca',
        'ip',
        'user_agent',
        'foto_caminho',
        'hash_anterior',
        'hash',
        'registrada_por',
    ];

    protected function casts(): array
    {
        return [
            'nsr' => 'integer',
            'registrada_em' => 'datetime',
            'data_referencia' => 'date',
            'tipo_sugerido' => TipoMarcacao::class,
            'origem' => OrigemMarcacao::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'precisao_m' => 'integer',
            'dentro_da_cerca' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw RegistroImutavelException::alteracao('A marcação de ponto', 'Faça um ajuste de ponto.');
        });

        static::deleting(function (): void {
            throw RegistroImutavelException::alteracao('A marcação de ponto', 'Faça um ajuste de ponto.');
        });
    }

    /**
     * sha256(nsr|colaborador_id|registrada_em|origem|lat|lng|hash_anterior).
     * Lat/lng com 7 casas e data como gravada no banco, para o hash ser
     * reproduzível a partir da linha lida (ponto:verificar-integridade).
     */
    public static function calcularHash(self $marcacao): string
    {
        $coordenada = fn ($valor): string => $valor === null ? '' : number_format((float) $valor, 7, '.', '');

        return hash('sha256', implode('|', [
            $marcacao->nsr,
            $marcacao->colaborador_id,
            $marcacao->registrada_em->format('Y-m-d H:i:s'),
            $marcacao->origem->value,
            $coordenada($marcacao->latitude),
            $coordenada($marcacao->longitude),
            $marcacao->hash_anterior ?? '',
        ]));
    }

    public function hashConfere(): bool
    {
        return hash_equals($this->hash, self::calcularHash($this));
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }

    public function registradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrada_por');
    }

    /** Ajustes que desconsideram esta marcação. */
    public function ajustes(): HasMany
    {
        return $this->hasMany(PontoAjuste::class, 'ponto_marcacao_id');
    }
}
