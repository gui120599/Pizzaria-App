<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'cliente_nome',
        'cliente_data_nascimento',
        'cliente_tipo',
        'cliente_cpf',
        'cliente_rg',
        'cliente_cnpj',
        'cliente_celular',
        'cliente_email',
        'cliente_endereco',
        'cliente_bairro',
        'cliente_cidade',
        'cliente_estado',
        'cliente_uf_estado',
        'cliente_numero_endereco',
        'cliente_cep',
        'cliente_foto', // Novo campo adicionado
        'cliente_limite_credito',
    ];

    protected $casts = [
        'cliente_data_nascimento' => 'date',
        'cliente_limite_credito' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function saveFoto($foto)
    {
        $nomeArquivo = time().'.'.$foto->getClientOriginalExtension();
        $caminho = public_path('/img/fotos_clientes');
        $foto->move($caminho, $nomeArquivo);
        $this->cliente_foto = $nomeArquivo;
        $this->save();
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'pedido_cliente_id');
    }

    public function vendas()
    {
        return $this->hasMany(Venda::class, 'venda_cliente_id');
    }

    public function lancamentosReceber()
    {
        return $this->hasMany(Lancamento::class, 'cliente_id');
    }

    /** Soma do saldo em aberto (Pendente/Parcial) dos títulos a receber deste cliente. */
    public function saldoDevedor(): float
    {
        return (float) $this->lancamentosReceber()
            ->receber()
            ->pendentes()
            ->withSum('pagamentos', 'valor')
            ->get()
            ->sum(fn (Lancamento $lancamento) => $lancamento->valor_restante);
    }

    /** Quanto ainda cabe em fiado dado o limite cadastrado. Null = sem crédito liberado (nenhum limite configurado). */
    public function limiteCreditoDisponivel(): ?float
    {
        if ($this->cliente_limite_credito === null) {
            return null;
        }

        return max(0.0, (float) $this->cliente_limite_credito - $this->saldoDevedor());
    }
}
