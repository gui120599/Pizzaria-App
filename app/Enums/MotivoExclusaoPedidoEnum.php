<?php

namespace App\Enums;

/**
 * Motivos categorizados de exclusão (soft delete) de pedido.
 *
 * Mesmo padrão de MotivoCancelamentoEnum. A exclusão nunca é física: o motivo
 * e o autor ficam gravados no pedido e alimentam o relatório de exceções.
 */
enum MotivoExclusaoPedidoEnum: string
{
    case DUPLICADO = 'duplicado';
    case LANCADO_POR_ENGANO = 'lancado_por_engano';
    case TESTE_TREINAMENTO = 'teste_treinamento';
    case OUTRO = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::DUPLICADO => 'Pedido duplicado',
            self::LANCADO_POR_ENGANO => 'Lançado por engano',
            self::TESTE_TREINAMENTO => 'Teste / treinamento',
            self::OUTRO => 'Outro',
        };
    }

    /** Motivo de cancelamento usado quando é preciso cancelar antes de excluir. */
    public function motivoCancelamento(): MotivoCancelamentoEnum
    {
        return match ($this) {
            self::DUPLICADO => MotivoCancelamentoEnum::DUPLICADO,
            self::TESTE_TREINAMENTO => MotivoCancelamentoEnum::DUPLICADO,
            self::LANCADO_POR_ENGANO, self::OUTRO => MotivoCancelamentoEnum::ERRO_LANCAMENTO,
        };
    }

    /**
     * value => label, para popular <select> e validações.
     *
     * @return array<string, string>
     */
    public static function paraSelect(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $m) => [$m->value => $m->label()])
            ->all();
    }
}
