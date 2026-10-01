<?php

namespace App\Models;

use App\Models\Concerns\TemSabores;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Opção de quantidade de sabores de uma categoria ("Sabor único", "Meia a
 * meia", "3 sabores"). O percentual de cada posição rege a baixa de estoque e
 * o rateio de adicionais por sabor — não o preço, que é sempre a média dos
 * sabores (PrecificadorService::precificarCombo()).
 */
class QuantidadeSabor extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'quantidades_sabores';

    protected $fillable = [
        'quantidade_sabor_categoria_id',
        'quantidade_sabor_descricao',
        'quantidade_sabor_quantidade',
        'quantidade_sabor_percentuais',
        'quantidade_sabor_rotulos',
        'quantidade_sabor_ordem',
    ];

    protected function casts(): array
    {
        return [
            'quantidade_sabor_quantidade' => 'integer',
            'quantidade_sabor_percentuais' => 'array',
            'quantidade_sabor_rotulos' => 'array',
            'quantidade_sabor_ordem' => 'integer',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'quantidade_sabor_categoria_id');
    }

    /**
     * Percentuais iguais para N sabores, somando exatamente 100 — o resto da
     * divisão vai para o último sabor (33,33 / 33,33 / 33,34).
     *
     * @return array<int, float>
     */
    public static function percentuaisIguais(int $quantidade): array
    {
        $quantidade = max(1, $quantidade);
        $base = round(100 / $quantidade, 2);
        $percentuais = array_fill(0, $quantidade - 1, $base);
        $percentuais[] = round(100 - array_sum($percentuais), 2);

        return $percentuais;
    }

    /**
     * Percentuais desta opção, caindo nos iguais quando o cadastro está
     * incompleto (quantidade de posições diferente da quantidade de sabores).
     *
     * @return array<int, float>
     */
    public function percentuais(): array
    {
        $percentuais = array_map('floatval', array_values($this->quantidade_sabor_percentuais ?? []));

        if (count($percentuais) !== $this->quantidade_sabor_quantidade) {
            return self::percentuaisIguais($this->quantidade_sabor_quantidade);
        }

        return $percentuais;
    }

    /**
     * Descrição de cada posição, exibida antes do nome do sabor. Posição sem
     * valor salvo cai no padrão por extenso (MEIA/TERÇO); string vazia salva
     * é respeitada — significa "só o nome do sabor".
     *
     * @return array<int, string>
     */
    public function rotulos(): array
    {
        $salvos = array_values($this->quantidade_sabor_rotulos ?? []);
        $padrao = self::rotulosPadrao($this->percentuais(), 'extenso');

        return array_map(
            fn (int $idx): string => array_key_exists($idx, $salvos) && $salvos[$idx] !== null
                ? trim((string) $salvos[$idx])
                : $padrao[$idx],
            array_keys($padrao),
        );
    }

    /**
     * Descrições sugeridas pelo atalho "Nomenclatura" do cadastro.
     *
     * @param  array<int, float|int|string>  $percentuais
     * @param  string  $nomenclatura  extenso (MEIA/TERÇO) | fracao (½/⅓) | nenhuma
     * @return array<int, string>
     */
    public static function rotulosPadrao(array $percentuais, string $nomenclatura): array
    {
        return array_map(fn ($percentual): string => match ($nomenclatura) {
            'fracao' => TemSabores::rotuloFracao((float) $percentual),
            'nenhuma' => '',
            default => TemSabores::fracaoPorExtenso((float) $percentual),
        }, array_values($percentuais));
    }
}
