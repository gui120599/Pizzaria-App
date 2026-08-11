<?php

namespace App\Services\Sefaz;

use App\Enums\SefazNotaRecebidaStatusEnum;
use App\Exceptions\NfeXmlInvalidoException;
use App\Exceptions\SefazDocumentoAindaNaoDisponivelException;
use App\Exceptions\SefazDocumentoNaoLocalizadoException;
use App\Exceptions\SefazIndisponivelException;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\SefazDocumentoPendente;
use App\Models\SefazNotaRecebida;
use App\Services\Nfe\Dto\NfeParseada;
use App\Services\Nfe\NfeImportService;
use App\Services\Nfe\NfeXmlParser;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazDocumentoCompleto;
use App\Services\Sefaz\Dto\SefazPollingResultado;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use App\Services\Sefaz\Dto\SefazSincronizacaoResultado;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Orquestrador da integração SEFAZ: busca avulsa por chave, polling
 * agendado por NSU (auto-importação) e a caixa de entrada de revisão manual
 * (sincronizarNotasRecebidas/visualizarNota/importarNota/ignorarNota). Só
 * produz XML — quem persiste Compra/CompraItem continua sendo o
 * NfeImportService já existente (nenhuma lógica de fornecedor/produto/
 * estoque é duplicada aqui).
 */
class SefazDistribuicaoService
{
    public function __construct(
        private SefazClient $client,
        private NfeImportService $importador,
        private NfeXmlParser $parser,
    ) {}

    /**
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazIndisponivelException
     */
    public function buscarPorChave(string $chave, ?int $userId = null): Compra
    {
        return $this->importador->importar($this->obterXmlCompleto($chave), $userId);
    }

    /**
     * Manifesta ciência (se necessário) e retorna o XML completo — núcleo
     * compartilhado por buscarPorChave() e visualizarNota(). Não persiste
     * nada; quem decide o que fazer com o XML é o chamador.
     *
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazIndisponivelException
     */
    private function obterXmlCompleto(string $chave): string
    {
        $resultado = $this->tentarConsultar($chave);

        if ($resultado instanceof SefazDocumentoCompleto) {
            return $resultado->xmlCompleto;
        }

        // Resumo sem XML completo, ou "nenhum documento localizado" (cStat
        // 137 — típico de nota nunca manifestada por este CNPJ): manifesta
        // ciência e tenta de novo com retry curto (ação síncrona disparada
        // por usuário — aceitável aguardar um pouco).
        $this->client->manifestarCiencia($chave);

        foreach ($this->intervalosRetry() as $segundos) {
            sleep($segundos);

            $resultado = $this->tentarConsultar($chave);
            if ($resultado instanceof SefazDocumentoCompleto) {
                return $resultado->xmlCompleto;
            }
        }

        throw new SefazDocumentoAindaNaoDisponivelException(
            'Nota manifestada, mas a SEFAZ ainda não liberou o XML completo. Tente novamente em alguns minutos.',
        );
    }

    /** Trata "nenhum documento localizado" (cStat 137) como "ainda sem XML completo", não como erro fatal. */
    private function tentarConsultar(string $chave): SefazResumoDocumento|SefazDocumentoCompleto|null
    {
        try {
            return $this->client->consultarPorChave($chave);
        } catch (SefazDocumentoNaoLocalizadoException) {
            return null;
        }
    }

    /**
     * Importa uma nota da caixa de entrada (ação do usuário, não automática).
     * Reaproveita o XML já buscado por visualizarNota(), se o usuário abriu
     * a pré-visualização antes — evita manifestar/consultar a SEFAZ de novo.
     *
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazIndisponivelException
     */
    public function importarNota(SefazNotaRecebida $nota, ?int $userId = null): Compra
    {
        $compra = $this->importador->importar($this->obterXmlDaNota($nota), $userId);

        $nota->forceFill([
            'snr_status' => SefazNotaRecebidaStatusEnum::IMPORTADA,
            'snr_compra_id' => $compra->id,
        ])->save();

        return $compra;
    }

    /** Marca a nota como ignorada — decisão do usuário, sem nenhuma chamada à SEFAZ. */
    public function ignorarNota(SefazNotaRecebida $nota): void
    {
        $nota->forceFill(['snr_status' => SefazNotaRecebidaStatusEnum::IGNORADA])->save();
    }

    /**
     * Prévia da nota (emitente + itens) sem importar nada — pro usuário
     * decidir antes de comprometer o rascunho de compra. O XML buscado fica
     * em cache (snr_xml_path) pra reabrir a prévia, ou importar em seguida,
     * sem gastar uma nova manifestação/consulta na SEFAZ.
     *
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazIndisponivelException
     * @throws NfeXmlInvalidoException se o XML devolvido pela SEFAZ não for uma NF-e válida
     */
    public function visualizarNota(SefazNotaRecebida $nota): NfeParseada
    {
        return $this->parser->parse($this->obterXmlDaNota($nota));
    }

    /**
     * XML da nota, na ordem mais barata/confiável primeiro: XML da Compra já
     * importada (definitivo) → cache de prévia (snr_xml_path) → busca na
     * SEFAZ (manifesta + retry), cacheando o resultado pra próxima chamada
     * (seja outra prévia, seja a importação em seguida).
     *
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazIndisponivelException
     */
    public function obterXmlDaNota(SefazNotaRecebida $nota): string
    {
        if ($nota->snr_status === SefazNotaRecebidaStatusEnum::IMPORTADA) {
            $xmlCompra = $nota->compra?->compra_xml_path
                ? Storage::disk('local')->get($nota->compra->compra_xml_path)
                : null;

            if ($xmlCompra !== null) {
                return $xmlCompra;
            }
        }

        $xml = $this->xmlEmCache($nota);

        if ($xml === null) {
            $xml = $this->obterXmlCompleto($nota->snr_chave_acesso);
            $nota->forceFill(['snr_xml_path' => $this->armazenarXmlPreview($xml, $nota->snr_chave_acesso)])->save();
        }

        return $xml;
    }

    private function xmlEmCache(SefazNotaRecebida $nota): ?string
    {
        if (! $nota->snr_xml_path) {
            return null;
        }

        return Storage::disk('local')->get($nota->snr_xml_path);
    }

    private function armazenarXmlPreview(string $xml, string $chave): string
    {
        $path = "sefaz_notas_preview/{$chave}.xml";
        Storage::disk('local')->put($path, $xml);

        return $path;
    }

    /** Polling agendado: reprocessa pendências, depois consulta novidades por NSU. */
    public function executarPolling(?int $userId = null): SefazPollingResultado
    {
        $empresa = Empresa::firstOrFail();
        $resultado = new SefazPollingResultado;
        $cap = (int) config('sefaz.max_documentos_por_execucao', 50);
        $processados = 0;

        foreach (SefazDocumentoPendente::query()->cursor() as $pendente) {
            if ($processados >= $cap) {
                break;
            }
            $resultado = $this->reprocessarPendente($pendente, $userId, $resultado);
            $processados++;
        }

        $ultNsu = (int) $empresa->empresa_sefaz_ultimo_nsu;

        do {
            $lote = $this->client->consultarPorNsu($ultNsu);

            foreach ($lote->itens as $item) {
                if ($processados >= $cap) {
                    break 2;
                }
                $resultado = $this->processarResumo($item, $userId, $resultado);
                $processados++;
            }

            $ultNsu = $lote->ultNsuRetornado;
        } while ($lote->temMais() && $processados < $cap);

        // Só avança o cursor depois de processar o lote inteiro sem exceção
        // fatal — um erro de infraestrutura no meio do caminho não perde NSU.
        $empresa->forceFill([
            'empresa_sefaz_ultimo_nsu' => $ultNsu,
            'empresa_sefaz_ultima_consulta_em' => now(),
        ])->save();

        return $resultado;
    }

    private function processarResumo(SefazResumoDocumento $item, ?int $userId, SefazPollingResultado $resultado): SefazPollingResultado
    {
        if ($item->isEvento) {
            return $resultado->comIncremento('puladas');
        }

        if (Compra::where('compra_chave_nfe', $item->chaveAcesso)->exists()) {
            return $resultado->comIncremento('puladas');
        }

        try {
            $this->client->manifestarCiencia($item->chaveAcesso);
            $completo = $this->client->consultarPorChave($item->chaveAcesso);

            if ($completo instanceof SefazDocumentoCompleto) {
                $this->importador->importar($completo->xmlCompleto, $userId);

                return $resultado->comIncremento('importadas');
            }

            $this->registrarPendente($item->chaveAcesso, $item->cnpjEmitente);

            return $resultado->comIncremento('pendentes');
        } catch (SefazIndisponivelException $e) {
            Log::channel('sefaz')->warning("Polling: erro pontual na chave {$item->chaveAcesso} — {$e->getMessage()}");

            return $resultado->comIncremento('erros');
        } catch (NfeXmlInvalidoException|ValidationException $e) {
            Log::channel('sefaz')->warning("Polling: nota {$item->chaveAcesso} rejeitada pelo importador — {$e->getMessage()}");

            return $resultado->comIncremento('erros');
        }
    }

    private function reprocessarPendente(SefazDocumentoPendente $pendente, ?int $userId, SefazPollingResultado $resultado): SefazPollingResultado
    {
        try {
            $completo = $this->client->consultarPorChave($pendente->sdp_chave_acesso);

            if ($completo instanceof SefazDocumentoCompleto) {
                $this->importador->importar($completo->xmlCompleto, $userId);
                $pendente->delete();

                return $resultado->comIncremento('importadas');
            }

            $pendente->forceFill([
                'sdp_tentativas' => $pendente->sdp_tentativas + 1,
                'sdp_ultima_tentativa_em' => now(),
            ])->save();

            return $resultado->comIncremento('pendentes');
        } catch (SefazIndisponivelException|NfeXmlInvalidoException|ValidationException $e) {
            Log::channel('sefaz')->warning("Polling: erro reprocessando pendente {$pendente->sdp_chave_acesso} — {$e->getMessage()}");

            return $resultado->comIncremento('erros');
        }
    }

    /**
     * Alimenta a caixa de entrada de NF-e (sefaz_notas_recebidas) pra revisão
     * manual do usuário — nunca manifesta ciência nem importa nada sozinho.
     * Usa um cursor de NSU próprio (empresa_sefaz_ultimo_nsu_revisao),
     * independente do cursor do polling automático: os dois andam sobre o
     * mesmo stream de NSU da SEFAZ, cada um com seu bookmark, sem risco de
     * um atrapalhar o outro.
     */
    public function sincronizarNotasRecebidas(): SefazSincronizacaoResultado
    {
        $empresa = Empresa::firstOrFail();
        $resultado = new SefazSincronizacaoResultado;
        $cap = (int) config('sefaz.max_documentos_por_execucao', 50);
        $processados = 0;
        $ultNsu = (int) $empresa->empresa_sefaz_ultimo_nsu_revisao;

        do {
            $lote = $this->client->consultarPorNsu($ultNsu);

            foreach ($lote->itens as $item) {
                if ($processados >= $cap) {
                    break 2;
                }
                $resultado = $this->registrarResumo($item, $resultado);
                $processados++;
            }

            $ultNsu = $lote->ultNsuRetornado;
        } while ($lote->temMais() && $processados < $cap);

        $empresa->forceFill([
            'empresa_sefaz_ultimo_nsu_revisao' => $ultNsu,
            'empresa_sefaz_ultima_revisao_em' => now(),
        ])->save();

        return $resultado;
    }

    private function registrarResumo(SefazResumoDocumento $item, SefazSincronizacaoResultado $resultado): SefazSincronizacaoResultado
    {
        if ($item->isEvento) {
            return $resultado->comIncremento('puladas');
        }

        $compra = Compra::where('compra_chave_nfe', $item->chaveAcesso)->first();

        $nota = SefazNotaRecebida::updateOrCreate(
            ['snr_chave_acesso' => $item->chaveAcesso],
            [
                'snr_nsu' => $item->nsu,
                'snr_cnpj_emitente' => $item->cnpjEmitente,
                'snr_nome_emitente' => $item->nomeEmitente,
                'snr_valor' => $item->valor,
                'snr_data_emissao' => $item->dataEmissao,
                'snr_encontrada_em' => now(),
                // Se já existe uma Compra com essa chave (importada por qualquer
                // via — busca por chave, auto-importação, upload manual de XML),
                // reflete isso aqui. Senão não mexe no status: preserva uma
                // decisão prévia do usuário (ex.: "ignorada").
                ...($compra ? [
                    'snr_status' => SefazNotaRecebidaStatusEnum::IMPORTADA,
                    'snr_compra_id' => $compra->id,
                ] : []),
            ],
        );

        return $nota->wasRecentlyCreated
            ? $resultado->comIncremento('novas')
            : $resultado->comIncremento('atualizadas');
    }

    private function registrarPendente(string $chave, ?string $cnpjEmitente): void
    {
        SefazDocumentoPendente::updateOrCreate(
            ['sdp_chave_acesso' => $chave],
            [
                'sdp_cnpj_emitente' => $cnpjEmitente,
                'sdp_manifestado_em' => now(),
            ],
        );
    }

    /** @return int[] */
    private function intervalosRetry(): array
    {
        return config('sefaz.intervalo_pos_manifestacao', [2, 5, 10]);
    }
}
