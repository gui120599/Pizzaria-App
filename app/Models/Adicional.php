<?php

namespace App\Models;

use App\Services\MiniaturaProdutoService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'adicional_ordem',
        'adicional_quantidade_porcao',
        'adicional_unidade_porcao',
        'adicional_instrucao_preparo',
        'adicional_insumo_id',
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
            'adicional_ordem' => 'integer',
            'adicional_quantidade_porcao' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        // Adicional novo entra no fim da ordem geral (Cardápio › Adicionais).
        static::creating(function (Adicional $adicional): void {
            if (! $adicional->adicional_ordem) {
                $adicional->adicional_ordem = ((int) static::withTrashed()->max('adicional_ordem')) + 1;
            }
        });

        static::saved(function (Adicional $adicional): void {
            if (! $adicional->wasChanged('adicional_foto') && ! $adicional->wasRecentlyCreated) {
                return;
            }

            try {
                $adicional->gerarMiniatura();
            } catch (\Throwable $e) {
                report($e);
            }
        });
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
            ->withPivot('ap_ordem')
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

    public function temFoto(): bool
    {
        return $this->caminhoFotoOriginal() !== null;
    }

    /** Caminho absoluto da foto original no disco, se o arquivo existir. */
    public function caminhoFotoOriginal(): ?string
    {
        $foto = $this->adicional_foto;

        if (! $foto) {
            return null;
        }

        if (str_contains($foto, '/')) {
            return Storage::disk('public')->exists($foto) ? Storage::disk('public')->path($foto) : null;
        }

        $caminho = public_path('img/fotos_adicionais/'.$foto);

        return file_exists($caminho) ? $caminho : null;
    }

    /**
     * Gera a miniatura WebP da foto (só a que falta, ou de novo com $forcar).
     *
     * @return string|null caminho gravado no disk public
     */
    public function gerarMiniatura(bool $forcar = false): ?string
    {
        $origem = $this->caminhoFotoOriginal();

        return $origem
            ? app(MiniaturaProdutoService::class)->gerarAdicional($origem, $this->adicional_foto, $forcar)
            : null;
    }

    /**
     * Foto leve para o modal de montagem do cardápio: a miniatura, ou a
     * original enquanto ela não existir. Sem foto, null (o modal não mostra
     * a imagem padrão "Sem Imagem").
     */
    public function getMiniaturaUrl(): ?string
    {
        if (! $this->temFoto()) {
            return null;
        }

        $caminho = app(MiniaturaProdutoService::class)->caminhoAdicional($this->adicional_foto);

        return Storage::disk('public')->exists($caminho)
            ? Storage::disk('public')->url($caminho)
            : $this->getImagemUrl();
    }

    public function getAdicionalFotoUrlAttribute(): string
    {
        return $this->getImagemUrl();
    }

    /** Insumo da porção do adicional (para a ficha técnica viva). */
    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'adicional_insumo_id');
    }
}
