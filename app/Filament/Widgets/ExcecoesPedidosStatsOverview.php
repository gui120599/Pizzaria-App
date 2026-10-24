<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\RelatorioCortesias;
use App\Filament\Widgets\Concerns\InteractsComExcecoesPedidos;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExcecoesPedidosStatsOverview extends BaseWidget
{
    use HasWidgetShield;
    use InteractsComExcecoesPedidos;
    use InteractsWithPageFilters;

    protected ?string $heading = 'Exceções do período';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $atual = $this->relatorio()->resumo();
        $anterior = $this->relatorio($this->periodoAnterior())->resumo();

        $cancelados = $atual['cancelados'];
        $removidos = $atual['itens_removidos'];
        $descontos = $atual['descontos'];
        $cortesias = $atual['cortesias'];
        $excluidos = $atual['excluidos'];
        $naoPagos = $atual['nao_pagos'];

        return [
            Stat::make('Pedidos cancelados', $this->brl($cancelados['valor']))
                ->description($this->descricaoComComparativo(
                    $this->plural($cancelados['quantidade'], 'pedido', 'pedidos')." · {$this->pct($cancelados['percentual'])} do valor dos pedidos",
                    $cancelados['valor'],
                    $anterior['cancelados']['valor'],
                ))
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($cancelados['percentual'] >= 10 ? 'danger' : ($cancelados['percentual'] >= 5 ? 'warning' : 'gray')),

            Stat::make('Itens removidos', (string) $removidos['quantidade'])
                ->description($this->descricaoComComparativo(
                    $this->brl($removidos['valor']).' em itens',
                    $removidos['quantidade'],
                    $anterior['itens_removidos']['quantidade'],
                ))
                ->descriptionIcon('heroicon-m-minus-circle')
                ->color($removidos['quantidade'] > 0 ? 'warning' : 'gray'),

            Stat::make('Descontos e cupons', $this->brl($descontos['valor']))
                ->description($this->descricaoComComparativo(
                    $this->plural($descontos['pedidos'], 'pedido', 'pedidos')." com desconto · {$this->pct($descontos['percentual'])} do valor bruto",
                    $descontos['valor'],
                    $anterior['descontos']['valor'],
                ))
                ->descriptionIcon('heroicon-m-tag')
                ->color($descontos['valor'] > 0 ? 'warning' : 'gray'),

            Stat::make('Cortesias', $this->brl($cortesias['valor']))
                ->description($this->descricaoComComparativo(
                    $this->plural($cortesias['itens'], 'item', 'itens')." · custo {$this->brl($cortesias['custo'])}",
                    $cortesias['valor'],
                    $anterior['cortesias']['valor'],
                ))
                ->descriptionIcon('heroicon-m-gift')
                ->color($cortesias['valor'] > 0 ? 'warning' : 'gray')
                ->url(RelatorioCortesias::canAccess() ? RelatorioCortesias::getUrl() : null),

            Stat::make('Pedidos excluídos', (string) $excluidos['quantidade'])
                ->description($this->descricaoComComparativo(
                    $this->brl($excluidos['valor']).' em pedidos',
                    $excluidos['quantidade'],
                    $anterior['excluidos']['quantidade'],
                ))
                ->descriptionIcon('heroicon-m-trash')
                ->color($excluidos['quantidade'] > 0 ? 'warning' : 'gray'),

            Stat::make('Entregues sem pagamento', (string) $naoPagos['quantidade'])
                ->description($this->descricaoComComparativo(
                    $this->brl($naoPagos['valor']).' a receber',
                    $naoPagos['quantidade'],
                    $anterior['nao_pagos']['quantidade'],
                ))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($naoPagos['quantidade'] > 0 ? 'danger' : 'gray'),
        ];
    }

    private function plural(int $quantidade, string $singular, string $plural): string
    {
        return $quantidade.' '.($quantidade === 1 ? $singular : $plural);
    }
}
