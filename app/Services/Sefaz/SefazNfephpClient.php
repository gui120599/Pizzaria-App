<?php

namespace App\Services\Sefaz;

use App\Exceptions\SefazAutenticacaoException;
use App\Exceptions\SefazIndisponivelException;
use App\Models\Empresa;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazDocumentoCompleto;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\CertificateException;
use NFePHP\NFe\Tools;
use Throwable;

/**
 * Implementação real de SefazClient sobre a lib nfephp-org/sped-nfe (SOAP +
 * certificado). A montagem do Tools (que já abre o certificado) é preguiçosa
 * — só acontece na primeira chamada de verdade, não na construção — assim
 * resolver esta classe via container nunca falha antes da hora (o chamador
 * decide quando checar Empresa::certificadoConfigurado()/certificadoVencido()).
 */
class SefazNfephpClient implements SefazClient
{
    private ?Tools $tools = null;

    public function __construct(private Empresa $empresa, private SefazRespostaDecoder $decoder) {}

    public function consultarPorChave(string $chave): SefazResumoDocumento|SefazDocumentoCompleto
    {
        $resposta = $this->chamar(
            fn () => $this->tools()->sefazDistDFe(chave: $chave),
            "consultarPorChave({$chave})",
        );

        return $this->decoder->decodeConsultaChave($resposta);
    }

    public function consultarPorNsu(int $ultNsu): SefazLoteDistribuicao
    {
        $resposta = $this->chamar(
            fn () => $this->tools()->sefazDistDFe(ultNSU: $ultNsu),
            "consultarPorNsu({$ultNsu})",
        );

        return $this->decoder->decodeLote($resposta);
    }

    public function manifestarCiencia(string $chave): void
    {
        $resposta = $this->chamar(
            fn () => $this->tools()->sefazManifesta($chave, Tools::EVT_CIENCIA),
            "manifestarCiencia({$chave})",
        );

        $this->conferirManifestacao($resposta, $chave);
    }

    /** @throws SefazAutenticacaoException se o certificado não abrir com a senha salva */
    private function tools(): Tools
    {
        return $this->tools ??= $this->montarTools($this->empresa);
    }

    /** @throws SefazAutenticacaoException */
    private function montarTools(Empresa $empresa): Tools
    {
        $conteudoPfx = Storage::disk('local')->get((string) $empresa->empresa_certificado_path);
        if ($conteudoPfx === null) {
            throw new SefazAutenticacaoException('Certificado digital não encontrado no disco.');
        }

        try {
            $certificado = Certificate::readPfx($conteudoPfx, (string) $empresa->empresa_certificado_senha);
        } catch (CertificateException $e) {
            throw new SefazAutenticacaoException('Certificado digital inválido ou senha incorreta.', previous: $e);
        }

        $configJson = json_encode([
            'atualizacao' => now()->toDateTimeString(),
            'tpAmb' => $empresa->empresa_sefaz_ambiente === 'producao' ? 1 : 2,
            'razaosocial' => (string) $empresa->empresa_razao_social,
            'cnpj' => preg_replace('/\D/', '', (string) $empresa->empresa_cnpj) ?? '',
            'siglaUF' => (string) $empresa->empresa_endereco_uf_estado,
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
        ]);

        $tools = new Tools($configJson, $certificado);
        $tools->model(55);

        return $tools;
    }

    /**
     * @throws SefazIndisponivelException
     */
    private function chamar(callable $chamada, string $operacao): string
    {
        try {
            $resposta = $chamada();
            Log::channel('sefaz')->info("SEFAZ {$operacao}: ok");

            return $resposta;
        } catch (Throwable $e) {
            Log::channel('sefaz')->warning("SEFAZ {$operacao}: falhou — {$e->getMessage()}");

            throw new SefazIndisponivelException("Falha ao comunicar com a SEFAZ ({$operacao}): {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Confere o cStat do evento de manifestação. Idempotente: cStat 573
     * (Duplicidade de Evento) significa que a ciência já tinha sido
     * registrada antes — não é erro.
     */
    private function conferirManifestacao(string $resposta, string $chave): void
    {
        $semNamespace = preg_replace('/xmlns="[^"]*"/', '', $resposta);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($semNamespace);
        libxml_clear_errors();

        $cStat = trim((string) ($xml?->xpath('retEvento/infEvento/cStat')[0] ?? $xml?->cStat ?? ''));
        $xMotivo = trim((string) ($xml?->xpath('retEvento/infEvento/xMotivo')[0] ?? $xml?->xMotivo ?? ''));

        $sucesso = in_array($cStat, ['135', '136', '155', '573'], true);

        Log::channel('sefaz')->info("Manifestação de ciência ({$chave}): cStat {$cStat} — {$xMotivo}");

        if (! $sucesso) {
            throw new SefazIndisponivelException("Falha ao manifestar ciência da nota: {$xMotivo} (cStat {$cStat}).");
        }
    }
}
