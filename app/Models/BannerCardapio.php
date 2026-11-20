<?php

namespace App\Models;

use App\Concerns\TemVigenciaRecorrente;
use App\Enums\AcaoBannerEnum;
use Carbon\CarbonInterface;
use Database\Factories\BannerCardapioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Banner do carrossel no topo do cardápio. A imagem (ou GIF) é gerida no
 * painel; a vigência segue o formato das promoções, com uma diferença: na
 * janela única, início e fim são opcionais (sem fim = fica no ar até
 * alguém desligar).
 */
class BannerCardapio extends Model
{
    /** @use HasFactory<BannerCardapioFactory> */
    use HasFactory;

    use SoftDeletes;
    use TemVigenciaRecorrente {
        vigente as vigenteRecorrencia;
    }

    protected $table = 'banners_cardapio';

    protected $fillable = [
        'banner_titulo',
        'banner_imagem',
        'banner_imagem_mobile',
        'banner_ativo',
        'banner_ordem',
        'banner_acao',
        'banner_categoria_id',
        'banner_produto_id',
        'banner_url',
        'banner_exibir_delivery',
        'banner_exibir_mesa',
        'banner_recorrente',
        'banner_inicio',
        'banner_fim',
        'banner_dias_semana',
        'banner_hora_inicio',
        'banner_hora_fim',
        'banner_data_final_recorrencia',
    ];

    protected function casts(): array
    {
        return [
            'banner_ativo' => 'boolean',
            'banner_ordem' => 'integer',
            'banner_acao' => AcaoBannerEnum::class,
            'banner_exibir_delivery' => 'boolean',
            'banner_exibir_mesa' => 'boolean',
            'banner_recorrente' => 'boolean',
            'banner_inicio' => 'datetime',
            'banner_fim' => 'datetime',
            'banner_dias_semana' => 'array',
            'banner_hora_inicio' => 'datetime:H:i:s',
            'banner_hora_fim' => 'datetime:H:i:s',
            'banner_data_final_recorrencia' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Banner novo entra no fim do carrossel.
        static::creating(function (BannerCardapio $banner): void {
            if (! $banner->banner_ordem) {
                $banner->banner_ordem = ((int) static::withTrashed()->max('banner_ordem')) + 1;
            }
        });
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'banner_categoria_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'banner_produto_id');
    }

    /**
     * No ar agora. Recorrente segue a regra das promoções; janela única
     * aceita início e fim em branco.
     */
    public function noAr(?CarbonInterface $momento = null): bool
    {
        $momento ??= now();

        if (! $this->banner_ativo) {
            return false;
        }

        if ($this->banner_recorrente) {
            return $this->vigenteRecorrencia($momento);
        }

        return ($this->banner_inicio === null || $this->banner_inicio <= $momento)
            && ($this->banner_fim === null || $this->banner_fim >= $momento);
    }

    /** Situação para a tabela do painel. */
    public function situacao(?CarbonInterface $momento = null): string
    {
        $momento ??= now();

        return match (true) {
            ! $this->banner_ativo => 'Desligado',
            $this->noAr($momento) => 'No ar',
            ! $this->banner_recorrente && $this->banner_fim !== null && $this->banner_fim < $momento => 'Encerrado',
            ! $this->banner_recorrente => 'Agendado',
            $this->banner_data_final_recorrencia !== null && $momento->toDateString() > $this->banner_data_final_recorrencia->toDateString() => 'Encerrado',
            default => 'Fora do horário',
        };
    }

    /**
     * Banners no ar para o cardápio, na ordem do carrossel.
     *
     * @return Collection<int, self>
     */
    public static function noArPara(bool $mesa = false): Collection
    {
        return static::query()
            ->where('banner_ativo', true)
            ->where($mesa ? 'banner_exibir_mesa' : 'banner_exibir_delivery', true)
            ->with(['categoria', 'produto'])
            ->orderBy('banner_ordem')
            ->orderBy('id')
            ->get()
            ->filter(fn (self $banner): bool => $banner->noAr())
            ->values();
    }

    /** Ordem do carrossel no painel. */
    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('banner_ordem')->orderBy('id');
    }

    /** URL pública da arte de computador (disco public). */
    public function urlImagem(): ?string
    {
        return filled($this->banner_imagem) ? asset('storage/'.$this->banner_imagem) : null;
    }

    /** URL pública da arte de celular, ou null sem ela (vale a de computador). */
    public function urlImagemMobile(): ?string
    {
        return filled($this->banner_imagem_mobile) ? asset('storage/'.$this->banner_imagem_mobile) : null;
    }

    /**
     * Destino do toque, já validado: categoria e produto só valem se ainda
     * estiverem no cardápio; link só http(s).
     *
     * @return array{tipo: string, alvo: int|string}|null
     */
    public function destino(): ?array
    {
        return match ($this->banner_acao) {
            AcaoBannerEnum::CATEGORIA => $this->categoria?->categoria_cardapio
                ? ['tipo' => 'categoria', 'alvo' => $this->categoria->categoria_pai_id ?? $this->categoria->id]
                : null,
            AcaoBannerEnum::PRODUTO => $this->produto?->visivelNoCardapio()
                ? ['tipo' => 'produto', 'alvo' => $this->produto->id]
                : null,
            AcaoBannerEnum::LINK => self::linkSeguro($this->banner_url)
                ? ['tipo' => 'link', 'alvo' => $this->banner_url]
                : null,
            default => null,
        };
    }

    public static function linkSeguro(?string $url): bool
    {
        return filled($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    // ── Implementação dos acessores de TemVigenciaRecorrente ────────────────

    protected function vigAtiva(): bool
    {
        return (bool) $this->banner_ativo;
    }

    protected function vigRecorrente(): bool
    {
        return (bool) $this->banner_recorrente;
    }

    protected function vigInicio(): ?CarbonInterface
    {
        return $this->banner_inicio;
    }

    protected function vigFim(): ?CarbonInterface
    {
        return $this->banner_fim;
    }

    protected function vigDiasSemana(): array
    {
        return $this->banner_dias_semana ?? [];
    }

    protected function vigHoraInicio(): ?CarbonInterface
    {
        return $this->banner_hora_inicio;
    }

    protected function vigHoraFim(): ?CarbonInterface
    {
        return $this->banner_hora_fim;
    }

    protected function vigDataFinalRecorrencia(): ?CarbonInterface
    {
        return $this->banner_data_final_recorrencia;
    }

    protected function vigUltimoResetEm(): ?CarbonInterface
    {
        return null;
    }

    protected function vigEsgotada(): bool
    {
        return false;
    }
}
