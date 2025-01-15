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
    ];

    protected $casts = [
        'cliente_data_nascimento' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function saveFoto($foto)
    {
        $nomeArquivo = time() . '.' . $foto->getClientOriginalExtension();
        $caminho = public_path('/img/fotos_clientes');
        $foto->move($caminho, $nomeArquivo);
        $this->cliente_foto = $nomeArquivo;
        $this->save();
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'pedido_cliente_id');
    }
}
