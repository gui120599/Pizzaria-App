<?php

namespace App\Livewire;

use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Opção de entrega + forma de pagamento COMBINADA com o cliente no
 * atendimento do pedido (split em múltiplas linhas). É dado informativo —
 * ver migration create_pagamentos_pedidos_table — nunca uma cobrança real.
 *
 * $total precisa ser #[Reactive]: nunca dispatch() o total de dentro de
 * dehydrate() pra propagar pro filho — o listener de eventos do Livewire
 * coleta o que já foi disparado ANTES de dehydrate() rodar, então o filho
 * nunca captura (armadilha documentada no projeto irmão razelfood, que já
 * teve esse bug). A Page pai deve sempre passar 'total' => $this->totalPreview
 * no @livewire(...) a cada render.
 */
class EntregaPagamentoPicker extends Component
{
    public ?int $opcaoEntregaId = null;

    /** @var array<int, array{opcaoPagamentoId: ?int, valor: string, trocoPara: ?string}> */
    public array $pagamentos = [];

    #[Reactive]
    public float $total = 0;

    public float $ultimoTotalSincronizado = 0;

    public function mount(array $inicial = [], float $total = 0): void
    {
        $this->opcaoEntregaId = $inicial['opcaoEntregaId'] ?? null;
        $this->pagamentos = $inicial['pagamentos'] ?? [];
        $this->total = $total;
        $this->ultimoTotalSincronizado = $total;

        if (empty($this->pagamentos)) {
            $this->pagamentos[] = $this->linhaEmBranco();
        }
    }

    private function linhaEmBranco(): array
    {
        return ['opcaoPagamentoId' => null, 'valor' => '0,00', 'trocoPara' => null];
    }

    public function adicionarLinhaPagamento(): void
    {
        $this->pagamentos[] = $this->linhaEmBranco();
        $this->preencherRestante();
        $this->emitirMudanca();
    }

    public function removerLinhaPagamento(int $indice): void
    {
        unset($this->pagamentos[$indice]);
        $this->pagamentos = array_values($this->pagamentos);

        if (empty($this->pagamentos)) {
            $this->pagamentos[] = $this->linhaEmBranco();
        }

        $this->emitirMudanca();
    }

    public function updatedOpcaoEntregaId(): void
    {
        $this->emitirMudanca();
    }

    public function updatedPagamentos(): void
    {
        $this->emitirMudanca();
    }

    public function linhaEhDinheiro(int $indice): bool
    {
        $id = $this->pagamentos[$indice]['opcaoPagamentoId'] ?? null;

        return $id ? OpcoesPagamento::find($id)?->opcaopag_desc_nfe === 'cash' : false;
    }

    /** Preenche o saldo restante (total - soma já digitada) na 1ª linha em branco. */
    private function preencherRestante(): bool
    {
        $indiceEmBranco = null;

        foreach ($this->pagamentos as $indice => $linha) {
            if (($linha['valor'] ?? '') === '' || ($linha['valor'] ?? '0,00') === '0,00') {
                $indiceEmBranco = $indice;

                break;
            }
        }

        if ($indiceEmBranco === null) {
            return false;
        }

        $somaDigitada = $this->somaExceto($indiceEmBranco);
        $restante = max(0, $this->total - $somaDigitada);
        $this->pagamentos[$indiceEmBranco]['valor'] = $this->formatarValor($restante);

        return true;
    }

    /** Reaplica o total às linhas: 1 linha -> fixa; várias com uma em branco -> completa a em branco; todas preenchidas -> a última absorve a diferença. */
    private function aplicarTotalAosValores(): bool
    {
        if (count($this->pagamentos) === 1) {
            $this->pagamentos[0]['valor'] = $this->formatarValor($this->total);

            return true;
        }

        if ($this->preencherRestante()) {
            return true;
        }

        $ultimoIndice = array_key_last($this->pagamentos);
        $somaDigitada = $this->somaExceto($ultimoIndice);
        $this->pagamentos[$ultimoIndice]['valor'] = $this->formatarValor(max(0, $this->total - $somaDigitada));

        return true;
    }

    private function somaExceto(int $indiceExcluido): float
    {
        $soma = 0.0;

        foreach ($this->pagamentos as $indice => $linha) {
            if ($indice === $indiceExcluido) {
                continue;
            }

            $soma += $this->parseValor($linha['valor'] ?? '0,00');
        }

        return $soma;
    }

    private function parseValor(mixed $valor): float
    {
        $digitos = (int) str_replace(['.', ','], '', (string) ($valor ?? '0'));

        return $digitos / 100;
    }

    private function formatarValor(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }

    public function render()
    {
        $mudou = false;

        if (abs($this->total - $this->ultimoTotalSincronizado) > 0.001) {
            $this->ultimoTotalSincronizado = $this->total;
            $mudou = $this->aplicarTotalAosValores();
        } elseif ($this->preencherRestante()) {
            $mudou = true;
        }

        if ($mudou) {
            $this->emitirMudanca();
        }

        return view('livewire.entrega-pagamento-picker', [
            'opcoesEntrega' => OpcoesEntregas::query()->orderBy('opcaoentrega_nome')->get(),
            'opcoesPagamento' => OpcoesPagamento::query()->orderBy('opcaopag_nome')->get(),
        ]);
    }

    private function emitirMudanca(): void
    {
        $this->dispatch('pedido-entrega-pagamento-atualizado', dados: [
            'opcaoEntregaId' => $this->opcaoEntregaId,
            'pagamentos' => $this->pagamentos,
        ]);
    }
}
