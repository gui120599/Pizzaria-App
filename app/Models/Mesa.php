<?php

namespace App\Models;

use App\Enums\TipoMesaEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Mesa extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mesas';

    /**
     * Padrões que o banco também aplica — declarados aqui para a mesa recém-
     * criada (sem refresh) já saber que o pedido pelo celular vem ligado.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'mesa_pedido_cliente_ativo' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'mesa_nome',
        'mesa_status',
        'mesa_sessao_atual_id',
        'mesa_tipo',
        'mesa_numero',
        'mesa_capacidade',
        'mesa_area',
        'mesa_codigo_qr',
        'mesa_pedido_cliente_ativo',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'mesa_status' => 'string',
        'mesa_tipo' => TipoMesaEnum::class,
        'mesa_numero' => 'integer',
        'mesa_capacidade' => 'integer',
        'mesa_pedido_cliente_ativo' => 'boolean',
    ];

    /**
     * The values allowed for 'mesa_status'.
     *
     * @var array
     */
    protected $allowedStatus = ['LIBERADA', 'OCUPADA', 'INATIVA'];

    /**
     * Set the 'mesa_status' attribute.
     *
     * @param  string  $value
     * @return void
     */
    public function setMesaStatusAttribute($value)
    {
        $this->attributes['mesa_status'] = in_array($value, $this->allowedStatus) ? $value : 'LIBERADA';
    }

    protected static function booted(): void
    {
        // Toda mesa nasce com o código do QR (/mesa/{codigo}).
        static::creating(function (Mesa $mesa): void {
            $mesa->mesa_codigo_qr ??= self::novoCodigoQr();
        });
    }

    /** Código aleatório e único para o QR — nunca o id, para não ser adivinhado. */
    public static function novoCodigoQr(): string
    {
        do {
            $codigo = Str::lower(Str::random(10));
        } while (static::withTrashed()->where('mesa_codigo_qr', $codigo)->exists());

        return $codigo;
    }

    /** Troca o código: o QR impresso antes deixa de funcionar (ex.: foto vazada). */
    public function regenerarCodigoQr(): self
    {
        $this->update(['mesa_codigo_qr' => self::novoCodigoQr()]);

        return $this;
    }

    public function chamados(): HasMany
    {
        return $this->hasMany(MesaChamado::class, 'mc_mesa_id');
    }

    public function sessoes()
    {
        return $this->hasMany(SessaoMesa::class, 'sessao_mesa_mesa_id');
    }

    public function sessaoAtual(): BelongsTo
    {
        return $this->belongsTo(SessaoMesa::class, 'mesa_sessao_atual_id');
    }

    public function ehComanda(): bool
    {
        return $this->mesa_tipo === TipoMesaEnum::COMANDA;
    }
}
