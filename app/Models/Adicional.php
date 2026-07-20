<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Adicional extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'adicionais';

    protected $fillable = [
        'adicional_nome',
        'adicional_foto',
        'adicional_valor',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function ap_adicional_id()
    {
        return $this->hasMany(AdicionaisProduto::class, 'ap_adicional_id');
    }

    public function saveFoto($foto)
    {
        $nomeArquivo = time().'.'.$foto->getClientOriginalExtension();
        $caminho = public_path('/img/fotos_adicionais');

        // Criar diretório, se não existir
        if (! file_exists($caminho)) {
            mkdir($caminho, 0777, true);
        }

        $foto->move($caminho, $nomeArquivo);
        $this->adicional_foto = $nomeArquivo;
        $this->save();
    }
}
