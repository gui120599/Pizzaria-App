<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

    /** @var list<string> */
    protected $appends = ['adicional_foto_url'];

    protected function casts(): array
    {
        return [
            'adicional_valor' => 'decimal:2',
        ];
    }

    public function ap_adicional_id()
    {
        return $this->hasMany(AdicionaisProduto::class, 'ap_adicional_id');
    }

    /** Produtos com este adicional vinculado (ignora vínculos inativados pelo legado). */
    public function produtos(): BelongsToMany
    {
        return $this->belongsToMany(Produto::class, 'adicionais_produtos', 'ap_adicional_id', 'ap_produto_id')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function saveFoto(UploadedFile $foto): void
    {
        $nomeArquivo = time().'.'.$foto->getClientOriginalExtension();
        $foto->storeAs('fotos_adicionais', $nomeArquivo, 'public');
        $this->adicional_foto = 'fotos_adicionais/'.$nomeArquivo;
        $this->save();
    }

    /**
     * URL da foto. Fotos novas ficam no disk `public` (`fotos_adicionais/x.jpg`);
     * as antigas guardam só o nome do arquivo em `public/img/fotos_adicionais`.
     */
    public function getImagemUrl(): string
    {
        $foto = $this->adicional_foto;

        if (! $foto) {
            return asset('Sem Imagem.png');
        }

        if (str_contains($foto, '/')) {
            return Storage::disk('public')->exists($foto)
                ? Storage::disk('public')->url($foto)
                : asset('Sem Imagem.png');
        }

        return file_exists(public_path('img/fotos_adicionais/'.$foto))
            ? asset('img/fotos_adicionais/'.$foto)
            : asset('Sem Imagem.png');
    }

    public function getAdicionalFotoUrlAttribute(): string
    {
        return $this->getImagemUrl();
    }
}
