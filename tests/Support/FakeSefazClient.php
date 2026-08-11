<?php

namespace Tests\Support;

use App\Exceptions\SefazDocumentoNaoLocalizadoException;
use App\Exceptions\SefazIndisponivelException;
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

    /** @var string[] */
    public array $chavesManifestadas = [];

    private bool $indisponivel = false;

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

    public function comLote(SefazLoteDistribuicao $lote): static
    {
        $this->lote = $lote;

        return $this;
    }

    public function lancarIndisponivel(): static
    {
        $this->indisponivel = true;

        return $this;
    }

    public function consultarPorChave(string $chave): SefazResumoDocumento|SefazDocumentoCompleto
    {
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

        $this->chavesManifestadas[] = $chave;

        if (isset($this->liberarAposManifestar[$chave])) {
            $this->porChave[$chave] = $this->liberarAposManifestar[$chave];
        }
    }
}
