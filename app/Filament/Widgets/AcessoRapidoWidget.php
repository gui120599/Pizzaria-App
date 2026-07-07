<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\FinanceiroDashboard;
use App\Filament\Pages\PedidosDashboard;
use App\Filament\Resources\AvaliacaoLinks\AvaliacaoLinksResource;
use App\Filament\Resources\Balancos\BalancoResource;
use App\Filament\Resources\Categorias\CategoriaResource;
use App\Filament\Resources\CentroCustos\CentroCustoResource;
use App\Filament\Resources\Clientes\ClienteResource;
use App\Filament\Resources\Compras\CompraResource;
use App\Filament\Resources\Fornecedores\FornecedorResource;
use App\Filament\Resources\HorarioFuncionamento\HorarioFuncionamentoResource;
use App\Filament\Resources\MovimentacaoProdutos\MovimentacaoProdutoResource;
use App\Filament\Resources\OpcoesEntregas\OpcoesEntregasResource;
use App\Filament\Resources\OpcoesPagamento\OpcoesPagamentoResource;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Resources\Prestadors\PrestadorResource;
use App\Filament\Resources\Produtos\ProdutoResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Vendas\VendaResource;
use Filament\Widgets\Widget;

/**
 * Espelha os mesmos grupos/itens da barra lateral (ver AdminPanelProvider)
 * como cartões de acesso rápido. Rótulo, ícone e URL vêm direto de cada
 * Resource/Page — mudou lá, muda aqui também, sem duplicar a definição.
 */
class AcessoRapidoWidget extends Widget
{
    protected string $view = 'filament.widgets.acesso-rapido';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, array{grupo: string, icon: string, itens: array<int, array{label: string, icon: mixed, url: string}>}>
     */
    public function getModulos(): array
    {
        // Ícone de cabeçalho de cada seção. Fica só aqui (não na sidebar, onde
        // o Filament proíbe ícone no grupo + nos itens ao mesmo tempo).
        $grupos = [
            'Indicadores' => [
                'icon' => 'heroicon-o-chart-bar',
                'classes' => [FinanceiroDashboard::class, PedidosDashboard::class],
            ],
            'Comercial' => [
                'icon' => 'heroicon-o-shopping-cart',
                'classes' => [VendaResource::class, PedidoResource::class, ClienteResource::class],
            ],
            'Cardápio' => [
                'icon' => 'heroicon-o-cube',
                'classes' => [ProdutoResource::class, CategoriaResource::class],
            ],
            'Estoque' => [
                'icon' => 'heroicon-o-archive-box',
                'classes' => [
                    FornecedorResource::class,
                    CompraResource::class,
                    MovimentacaoProdutoResource::class,
                    BalancoResource::class,
                    CentroCustoResource::class,
                ],
            ],
            'Pessoas' => [
                'icon' => 'heroicon-o-user-group',
                'classes' => [PrestadorResource::class, UserResource::class],
            ],
            'Configurações' => [
                'icon' => 'heroicon-o-cog-6-tooth',
                'classes' => [
                    HorarioFuncionamentoResource::class,
                    OpcoesEntregasResource::class,
                    OpcoesPagamentoResource::class,
                    AvaliacaoLinksResource::class,
                ],
            ],
        ];

        return collect($grupos)
            ->map(fn (array $config, string $grupo): array => [
                'grupo' => $grupo,
                'icon' => $config['icon'],
                'itens' => collect($config['classes'])
                    ->map(fn (string $class): array => [
                        'label' => $class::getNavigationLabel(),
                        'icon' => $class::getNavigationIcon(),
                        'url' => $class::getUrl(),
                    ])
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
