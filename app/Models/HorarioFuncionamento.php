<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class HorarioFuncionamento extends Model
{
    protected $table = 'horarios_funcionamento';

    protected $fillable = [
        'horario_dia_semana',
        'horario_abertura',
        'horario_fechamento',
        'horario_ativo',
    ];

    protected $casts = [
        'horario_ativo' => 'boolean',
    ];

    public static function estaAberto(): bool
    {
        $now       = Carbon::now();
        $dia       = $now->dayOfWeek;
        $horaAtual = $now->format('H:i:s');

        return static::where('horario_dia_semana', $dia)
            ->where('horario_ativo', true)
            ->where('horario_abertura', '<=', $horaAtual)
            ->where('horario_fechamento', '>=', $horaAtual)
            ->exists();
    }

    public static function proximoHorario(): ?string
    {
        $now  = Carbon::now();
        $dias = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

        // Hoje ainda tem abertura futura?
        $hojeProximo = static::where('horario_dia_semana', $now->dayOfWeek)
            ->where('horario_ativo', true)
            ->where('horario_abertura', '>', $now->format('H:i:s'))
            ->orderBy('horario_abertura')
            ->first();

        if ($hojeProximo) {
            return 'hoje às ' . substr($hojeProximo->horario_abertura, 0, 5);
        }

        // Próximos 7 dias
        for ($i = 1; $i <= 7; $i++) {
            $dia     = ($now->dayOfWeek + $i) % 7;
            $proximo = static::where('horario_dia_semana', $dia)
                ->where('horario_ativo', true)
                ->orderBy('horario_abertura')
                ->first();

            if ($proximo) {
                $label = $i === 1 ? 'amanhã' : 'na ' . $dias[$dia] . '-feira';
                if ($dia === 0) $label = $i === 1 ? 'amanhã' : 'no Domingo';
                if ($dia === 6) $label = $i === 1 ? 'amanhã' : 'no Sábado';

                return $label . ' às ' . substr($proximo->horario_abertura, 0, 5);
            }
        }

        return null;
    }
}
