<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SessaoMesa extends Model
{
    use HasFactory;

    protected $fillable = [
        'sessao_mesa_mesa_id',
        'sessao_mesa_cliente_id',
        'sessao_mesa_usuario_id',
        'sessao_mesa_status',
        'sessao_mesa_motivo_cancelamento',
        'sessao_mesa_pessoas',
        'sessao_mesa_taxa_servico_percentual',
        'sessao_mesa_conta_solicitada_em',
        'sessao_mesa_versao',
    ];

    protected $casts = [
        'sessao_mesa_status' => 'string',
        'sessao_mesa_pessoas' => 'integer',
        'sessao_mesa_taxa_servico_percentual' => 'decimal:2',
        'sessao_mesa_conta_solicitada_em' => 'datetime',
        'sessao_mesa_versao' => 'integer',
    ];

    public function mesa()
    {
        return $this->belongsTo(Mesa::class, 'sessao_mesa_mesa_id')->withDefault([
            'mesa_nome' => 'S/M',
        ]);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'sessao_mesa_cliente_id')->withDefault([
            'cliente_nome' => 'Não Informado',
        ]);
    }

    public function garcom()
    {
        return $this->belongsTo(User::class, 'sessao_mesa_usuario_id');
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'pedido_sessao_mesa_id');
    }

    public function clientes()
    {
        return $this->hasMany(SessaoMesaCliente::class, 'smc_sessao_mesa_id');
    }

    /** Celulares identificados nesta sessão pelo QR da mesa. */
    public function participantes(): HasMany
    {
        return $this->hasMany(MesaParticipante::class, 'mp_sessao_mesa_id');
    }

    public function chamados(): HasMany
    {
        return $this->hasMany(MesaChamado::class, 'mc_sessao_mesa_id');
    }

    public function autorizacoes(): MorphMany
    {
        return $this->morphMany(AutorizacaoGerente::class, 'autorizacao_auditavel');
    }

    public function temTaxaServico(): bool
    {
        return (float) $this->sessao_mesa_taxa_servico_percentual > 0;
    }

    public function contaSolicitada(): bool
    {
        return $this->sessao_mesa_conta_solicitada_em !== null;
    }
}
