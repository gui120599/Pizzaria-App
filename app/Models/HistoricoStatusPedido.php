<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma linha por transição de status de um pedido — ver a migration para o
 * porquê de existir. Só `created_at` (sem `updated_at`: o registro nunca é
 * alterado depois de criado).
 */
class HistoricoStatusPedido extends Model
{
    use HasFactory;

    protected $table = 'historico_status_pedidos';

    public const UPDATED_AT = null;

    protected $fillable = [
        'hsp_pedido_id',
        'hsp_status_de',
        'hsp_status_para',
        'hsp_user_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'hsp_pedido_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'hsp_user_id')->withDefault([
            'name_first' => 'Sistema',
        ]);
    }

    /**
     * Ponto único de gravação — usado pelo observer e pelos três caminhos que
     * escrevem pedido_status por query builder (sem disparar eventos Eloquent):
     * EntregaService::aceitar(), ConfirmacoesPedidos::confirmar() e o
     * updateQuietly() de VendaObserver.
     */
    public static function registrar(int $pedidoId, ?string $de, string $para, ?int $userId = null): void
    {
        // created_at NÃO entra no array: não está em $fillable (guard de mass
        // assignment) e não precisa — Model::save() grava o timestamp sozinho
        // mesmo com UPDATED_AT desligado, contanto que $timestamps continue true.
        static::create([
            'hsp_pedido_id' => $pedidoId,
            'hsp_status_de' => $de,
            'hsp_status_para' => $para,
            'hsp_user_id' => $userId,
        ]);
    }
}
