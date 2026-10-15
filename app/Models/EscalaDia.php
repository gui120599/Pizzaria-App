<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um dia da semana da escala. `saida` menor ou igual a `entrada` significa
 * que o turno vira a meia-noite (18:00–01:00).
 */
class EscalaDia extends Model
{
    use HasFactory;

    protected $table = 'escala_dias';

    protected $fillable = [
        'escala_id',
        'dia_semana',
        'folga',
        'entrada',
        'saida',
        'intervalo_minutos',
    ];

    protected function casts(): array
    {
        return [
            'dia_semana' => 'integer',
            'folga' => 'boolean',
            'intervalo_minutos' => 'integer',
        ];
    }

    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class, 'escala_id');
    }

    public function trabalha(): bool
    {
        return ! $this->folga && $this->entrada !== null && $this->saida !== null;
    }

    public function viraMeiaNoite(): bool
    {
        return $this->trabalha() && self::minutosDoDia($this->saida) <= self::minutosDoDia($this->entrada);
    }

    /** Jornada prevista: saída − entrada (+24h se vira a meia-noite) − intervalo. */
    public function minutosPrevistos(): int
    {
        if (! $this->trabalha()) {
            return 0;
        }

        $duracao = self::minutosDoDia($this->saida) - self::minutosDoDia($this->entrada);

        if ($duracao <= 0) {
            $duracao += 24 * 60;
        }

        return max(0, $duracao - $this->intervalo_minutos);
    }

    /** Entrada prevista no dia operacional informado. */
    public function entradaEm(CarbonInterface $diaOperacional): ?CarbonImmutable
    {
        return $this->trabalha()
            ? CarbonImmutable::instance($diaOperacional)->startOfDay()->setTimeFromTimeString($this->entrada)
            : null;
    }

    /** Saída prevista no dia operacional informado (dia seguinte se vira a meia-noite). */
    public function saidaEm(CarbonInterface $diaOperacional): ?CarbonImmutable
    {
        if (! $this->trabalha()) {
            return null;
        }

        $saida = CarbonImmutable::instance($diaOperacional)->startOfDay()->setTimeFromTimeString($this->saida);

        return $this->viraMeiaNoite() ? $saida->addDay() : $saida;
    }

    private static function minutosDoDia(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }
}
