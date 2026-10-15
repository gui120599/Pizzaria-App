<?php

namespace App\Enums\Ponto;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Natureza do item do repasse; tipo() diz se é provento ou desconto.
 */
enum NaturezaItem: string implements HasColor, HasLabel
{
    case SalarioBase = 'salario_base';
    case HorasNormais = 'horas_normais';
    case Diarias = 'diarias';
    case Turnos = 'turnos';
    case HoraExtra50 = 'hora_extra_50';
    case HoraExtra100 = 'hora_extra_100';
    case AdicionalNoturno = 'adicional_noturno';
    case Entregas = 'entregas';
    case Gorjeta = 'gorjeta';
    case Bonificacao = 'bonificacao';
    case AjudaCusto = 'ajuda_custo';
    case OutrosProventos = 'outros_proventos';
    case Adiantamento = 'adiantamento';
    case Consumo = 'consumo';
    case Falta = 'falta';
    case Atraso = 'atraso';
    case Inss = 'inss';
    case Irrf = 'irrf';
    case OutrosDescontos = 'outros_descontos';

    public function getLabel(): string
    {
        return match ($this) {
            self::SalarioBase => 'Salário base',
            self::HorasNormais => 'Horas normais',
            self::Diarias => 'Diárias',
            self::Turnos => 'Turnos',
            self::HoraExtra50 => 'Hora extra 50%',
            self::HoraExtra100 => 'Hora extra 100%',
            self::AdicionalNoturno => 'Adicional noturno',
            self::Entregas => 'Entregas',
            self::Gorjeta => 'Gorjeta / taxa de serviço',
            self::Bonificacao => 'Bonificação',
            self::AjudaCusto => 'Ajuda de custo',
            self::OutrosProventos => 'Outros proventos',
            self::Adiantamento => 'Adiantamento / vale',
            self::Consumo => 'Consumo interno',
            self::Falta => 'Faltas',
            self::Atraso => 'Atrasos',
            self::Inss => 'INSS',
            self::Irrf => 'IRRF',
            self::OutrosDescontos => 'Outros descontos',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SalarioBase => 'success',
            self::HorasNormais => 'success',
            self::Diarias => 'success',
            self::Turnos => 'success',
            self::HoraExtra50 => 'success',
            self::HoraExtra100 => 'success',
            self::AdicionalNoturno => 'success',
            self::Entregas => 'success',
            self::Gorjeta => 'success',
            self::Bonificacao => 'success',
            self::AjudaCusto => 'success',
            self::OutrosProventos => 'success',
            self::Adiantamento => 'danger',
            self::Consumo => 'danger',
            self::Falta => 'danger',
            self::Atraso => 'danger',
            self::Inss => 'danger',
            self::Irrf => 'danger',
            self::OutrosDescontos => 'danger',
        };
    }

    public function tipo(): TipoItemRepasse
    {
        return in_array($this, [
            self::Adiantamento,
            self::Consumo,
            self::Falta,
            self::Atraso,
            self::Inss,
            self::Irrf,
            self::OutrosDescontos,
        ], true) ? TipoItemRepasse::Desconto : TipoItemRepasse::Provento;
    }

    /** @return array<string, string> naturezas de um tipo, para o Select de itens manuais */
    public static function opcoesDe(TipoItemRepasse $tipo): array
    {
        return collect(self::cases())
            ->filter(fn (self $natureza): bool => $natureza->tipo() === $tipo)
            ->mapWithKeys(fn (self $natureza): array => [$natureza->value => $natureza->getLabel()])
            ->all();
    }
}
