<?php

namespace App\Filament\Cozinha\Concerns;

use App\Services\Cozinha\CozinhaTempoReal;

/**
 * Tempo real por versão (spec §8.3): a view roda
 * `wire:poll.4s="verificarVersao"`; enquanto o contador dos setores (ou dos
 * pedidos) não muda, a resposta é vazia — uma leitura de cache, sem query.
 */
trait InteractsWithTempoRealCozinha
{
    public string $versaoTempoReal = '';

    /**
     * Setores que esta tela acompanha; vazio = contador geral de pedidos.
     *
     * @return list<int>
     */
    abstract protected function setoresTempoReal(): array;

    /** Limpa os #[Computed] e reage (som, toast) quando algo mudou. */
    abstract protected function aoMudarTempoReal(): void;

    public function mountInteractsWithTempoRealCozinha(): void
    {
        $this->versaoTempoReal = $this->versaoAtual();
    }

    public function verificarVersao(): void
    {
        $nova = $this->versaoAtual();

        if ($nova === $this->versaoTempoReal) {
            $this->skipRender();

            return;
        }

        $this->versaoTempoReal = $nova;
        $this->aoMudarTempoReal();
    }

    /** Depois de uma ação da própria tela: já re-renderiza, não precisa do próximo poll. */
    protected function sincronizarVersao(): void
    {
        $this->versaoTempoReal = $this->versaoAtual();
    }

    public function intervaloPolling(): string
    {
        return max(2, (int) config('cozinha.tempo_real.polling_segundos', 4)).'s';
    }

    private function versaoAtual(): string
    {
        $tempoReal = app(CozinhaTempoReal::class);
        $setores = $this->setoresTempoReal();

        return $setores === [] ? (string) $tempoReal->versaoPedidos() : $tempoReal->versaoSetores($setores);
    }
}
