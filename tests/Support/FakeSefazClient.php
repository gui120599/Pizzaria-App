<?php

namespace Tests\Support;

use App\Exceptions\SefazConsumoIndevidoException;
use App\Exceptions\SefazDocumentoNaoLocalizadoException;
use App\Exceptions\SefazIndisponivelException;
use App\Exceptions\SefazManifestacaoForaDoPrazoException;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazDocumentoCompleto;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;

/**
 * Fake de SefazClient pra testes de feature — o SOAP real não é mockável de
 * forma confiável, então os testes trocam o binding do contrato por isto.
 */
class FakeSefazClient implements SefazClient
{
    /** @var array<string, SefazResumoDocumento|SefazDocumentoCompleto> */
    private array $porChave = [];

    /** @var array<string, SefazDocumentoCompleto> */
    private array $liberarAposManifestar = [];

    /** @var array<string, true> */
    private array $naoLocalizadas = [];

    private ?SefazLoteDistribuicao $lote = null;

    /** @var array<string, true> */
    private array $cienciaForaDoPrazo = [];

    /** @var string[] */
    public array $chavesManifestadas = [];

    /** @var string[] */
    public array $chavesConfirmadas = [];

    private bool $indisponivel = false;

    /** Quantas consultas (por chave + por NSU) aceitar antes de responder cStat 656; null = nunca. */
    private ?int $consumoIndevidoAposConsultas = null;

    /** Total de chamadas ao NFeDistribuicaoDFe (consultarPorChave + consultarPorNsu). */
    public int $consultas = 0;

    public function comDocumentoCompleto(string $chave, string $xml): static
    {
        $this->porChave[$chave] = new SefazDocumentoCompleto($chave, $xml);

        return $this;
    }

    public function comResumoPendente(string $chave, ?string $cnpjEmitente = null): static
    {
        $this->porChave[$chave] = new SefazResumoDocumento(nsu: 1, chaveAcesso: $chave, isEvento: false, cnpjEmitente: $cnpjEmitente);

        return $this;
    }

    /** Simula a propagação: só libera o XML completo depois de manifestarCiencia($chave). */
    public function comDocumentoAposManifestacao(string $chave, string $xml): static
    {
        $this->liberarAposManifestar[$chave] = new SefazDocumentoCompleto($chave, $xml);

        return $this;
    }

    /** Simula cStat 137 (nenhum documento localizado) permanentemente pra essa chave. */
    public function comNaoLocalizado(string $chave): static
    {
        $this->naoLocalizadas[$chave] = true;

        return $this;
    }

    /** Simula cStat 596: a SEFAZ recusa a ciência (nota com mais de 10 dias), mas aceita a confirmação. */
    public function comCienciaForaDoPrazo(string $chave): static
    {
        $this->cienciaForaDoPrazo[$chave] = true;

        return $this;
    }

    public function comLote(SefazLoteDistribuicao $lote): static
    {
        $this->lote = $lote;

        return $this;
    }

    /** Simula o bloqueio por Consumo Indevido (cStat 656) a partir da consulta N+1. */
    public function comConsumoIndevidoApos(int $consultas): static
    {
        $this->consumoIndevidoAposConsultas = $consultas;

        return $this;
    }

    public function lancarIndisponivel(): static
    {
        $this->indisponivel = true;

        return $this;
    }

    public function consultarPorChave(string $chave): SefazResumoDocumento|SefazDocumentoCompleto
    {
        $this->contarConsulta();

        if ($this->indisponivel) {
            throw new SefazIndisponivelException('Indisponível (fake).');
        }

        if (isset($this->porChave[$chave])) {
            return $this->porChave[$chave];
        }

        // Ainda não manifestada (ou manifestada sem nunca liberar, no caso de comNaoLocalizado):
        // simula o cStat 137 real da SEFAZ em vez do "não configurada" genérico.
        if (isset($this->liberarAposManifestar[$chave]) || isset($this->naoLocalizadas[$chave])) {
            throw new SefazDocumentoNaoLocalizadoException("Nenhum documento localizado (fake) para {$chave}.");
        }

        throw new SefazIndisponivelException("Chave {$chave} não configurada no fake.");
    }

    public function consultarPorNsu(int $ultNsu): SefazLoteDistribuicao
    {
        $this->contarConsulta();

        if ($this->indisponivel) {
            throw new SefazIndisponivelException('Indisponível (fake).');
        }

        return $this->lote ?? new SefazLoteDistribuicao(itens: [], ultNsuRetornado: $ultNsu, maxNsu: $ultNsu, cStat: '137');
    }

    public function manifestarCiencia(string $chave): void
    {
        if ($this->indisponivel) {
            throw new SefazIndisponivelException('Indisponível (fake).');
        }

        if (isset($this->cienciaForaDoPrazo[$chave])) {
            throw new SefazManifestacaoForaDoPrazoException('Ciência fora do prazo (fake).');
        }

        $this->chavesManifestadas[] = $chave;
        $this->liberarSeManifestada($chave);
    }

    public function confirmarOperacao(string $chave): void
    {
        if ($this->indisponivel) {
            throw new SefazIndisponivelException('Indisponível (fake).');
        }

        $this->chavesConfirmadas[] = $chave;
        $this->liberarSeManifestada($chave);
    }

    private function contarConsulta(): void
    {
        $this->consultas++;

        if ($this->consumoIndevidoAposConsultas !== null && $this->consultas > $this->consumoIndevidoAposConsultas) {
            throw new SefazConsumoIndevidoException('Rejeição: Consumo Indevido (fake).');
        }
    }

    private function liberarSeManifestada(string $chave): void
    {
        if (isset($this->liberarAposManifestar[$chave])) {
            $this->porChave[$chave] = $this->liberarAposManifestar[$chave];
        }
    }
}
