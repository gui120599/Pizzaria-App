<?php

namespace App\Services\Sefaz;

use App\Enums\SefazNotaRecebidaStatusEnum;
use App\Exceptions\NfeXmlInvalidoException;
use App\Exceptions\SefazConsultaEmEsperaException;
use App\Exceptions\SefazConsumoIndevidoException;
use App\Exceptions\SefazDocumentoAindaNaoDisponivelException;
use App\Exceptions\SefazDocumentoNaoLocalizadoException;
use App\Exceptions\SefazIndisponivelException;
use App\Exceptions\SefazManifestacaoForaDoPrazoException;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\SefazDocumentoPendente;
use App\Models\SefazNotaRecebida;
use App\Services\Nfe\Dto\NfeParseada;
use App\Services\Nfe\NfeImportService;
use App\Services\Nfe\NfeXmlParser;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazDocumentoCompleto;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
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
        private SefazControleConsumo $controle,
    ) {}

    /**
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazManifestacaoForaDoPrazoException se a nota já passou do prazo do evento usado
     * @throws SefazIndisponivelException
     */
    public function buscarPorChave(string $chave, ?int $userId = null, bool $confirmarOperacao = false): Compra
    {
        return $this->importador->importar($this->obterXmlCompleto($chave, $confirmarOperacao), $userId);
    }

    /**
     * Manifesta (se necessário) e retorna o XML completo — núcleo
     * compartilhado por buscarPorChave() e visualizarNota(). Não persiste
     * nada; quem decide o que fazer com o XML é o chamador.
     *
     * Por padrão manifesta Ciência da Operação, que a SEFAZ só aceita até 10
     * dias da autorização. Com $confirmarOperacao, registra a Confirmação da
     * Operação (conclusiva, até 90 dias) — só por decisão explícita do
     * usuário, porque declara ao Fisco que a mercadoria foi recebida.
     *
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazManifestacaoForaDoPrazoException se a nota já passou do prazo do evento usado
     * @throws SefazIndisponivelException
     */
    private function obterXmlCompleto(string $chave, bool $confirmarOperacao = false): string
    {
        $resultado = $this->tentarConsultar($chave);

        if ($resultado instanceof SefazDocumentoCompleto) {
            return $resultado->xmlCompleto;
        }

        // Resumo sem XML completo, ou "nenhum documento localizado" (cStat
        // 137 — típico de nota nunca manifestada por este CNPJ): manifesta
        // e tenta de novo com retry curto (ação síncrona disparada por
        // usuário — aceitável aguardar um pouco).
        if ($confirmarOperacao) {
            $this->client->confirmarOperacao($chave);
        } else {
            $this->client->manifestarCiencia($chave);
        }

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
            return $this->consultarChave($chave);
        } catch (SefazDocumentoNaoLocalizadoException) {
            return null;
        }
    }

    /**
     * Toda consulta por chave passa por aqui: respeita bloqueio do CNPJ e o
     * limite por chave antes de chamar, e registra o bloqueio se a SEFAZ
     * responder Consumo Indevido (cStat 656).
     *
     * @throws SefazConsultaEmEsperaException
     */
    private function consultarChave(string $chave): SefazResumoDocumento|SefazDocumentoCompleto
    {
        $this->controle->garantirLiberado();
        $this->controle->garantirChaveLiberada($chave);
        $this->controle->registrarConsultaChave($chave);

        try {
            return $this->client->consultarPorChave($chave);
        } catch (SefazConsumoIndevidoException $e) {
            throw SefazConsultaEmEsperaException::bloqueioSefaz($this->controle->registrarBloqueio(), $e);
        }
    }

    /** @throws SefazConsultaEmEsperaException */
    private function consultarNsu(int $ultNsu): SefazLoteDistribuicao
    {
        $this->controle->garantirLiberado();

        try {
            return $this->client->consultarPorNsu($ultNsu);
        } catch (SefazConsumoIndevidoException $e) {
            throw SefazConsultaEmEsperaException::bloqueioSefaz($this->controle->registrarBloqueio(), $e);
        }
    }

    /**
     * Importa uma nota da caixa de entrada (ação do usuário, não automática).
     * Reaproveita o XML já buscado por visualizarNota(), se o usuário abriu
     * a pré-visualização antes — evita manifestar/consultar a SEFAZ de novo.
     *
     * @throws SefazDocumentoAindaNaoDisponivelException se a SEFAZ não liberar o XML completo a tempo
     * @throws SefazManifestacaoForaDoPrazoException se a nota já passou do prazo do evento usado
     * @throws SefazIndisponivelException
     */
    public function importarNota(SefazNotaRecebida $nota, ?int $userId = null, bool $confirmarOperacao = false): Compra
    {
        $compra = $this->importador->importar($this->obterXmlDaNota($nota, $confirmarOperacao), $userId);

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
     * @throws SefazManifestacaoForaDoPrazoException se a nota já passou do prazo do evento usado
     * @throws SefazIndisponivelException
     */
    public function obterXmlDaNota(SefazNotaRecebida $nota, bool $confirmarOperacao = false): string
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
            $xml = $this->obterXmlCompleto($nota->snr_chave_acesso, $confirmarOperacao);
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

    /**
     * Polling agendado: reprocessa pendências, depois consulta novidades por
     * NSU (pulado enquanto a SEFAZ pede 1 hora de espera após "sem novidades").
     *
     * @throws SefazConsultaEmEsperaException se o CNPJ estiver bloqueado pela SEFAZ — interrompe tudo
     */
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

        if ($processados >= $cap || $this->controle->nsuLiberadoEm() !== null) {
            return $resultado;
        }

        $ultNsu = (int) $empresa->empresa_sefaz_ultimo_nsu;

        try {
            $this->percorrerNsu($ultNsu, $cap - $processados, function (SefazResumoDocumento $item) use ($userId, &$resultado): void {
                $resultado = $this->processarResumo($item, $userId, $resultado);
            });
        } finally {
            // Só avança o cursor até o último lote processado inteiro — um
            // bloqueio/erro no meio do caminho não perde NSU.
            $empresa->forceFill([
                'empresa_sefaz_ultimo_nsu' => $ultNsu,
                'empresa_sefaz_ultima_consulta_em' => now(),
            ])->save();
        }

        return $resultado;
    }

    /**
     * Percorre o stream de NSU a partir de $ultNsu, processando até $limite
     * itens. $ultNsu (por referência) sempre fica no cursor do último lote
     * processado por inteiro — mesmo se uma exceção interromper no meio. Ao
     * chegar ao fim (ultNSU = maxNSU), registra a espera de 1 hora exigida
     * pela SEFAZ antes da próxima consulta por NSU.
     *
     * @param  callable(SefazResumoDocumento): void  $processar
     *
     * @throws SefazConsultaEmEsperaException
     */
    private function percorrerNsu(int &$ultNsu, int $limite, callable $processar): void
    {
        $processados = 0;

        do {
            $lote = $this->consultarNsu($ultNsu);

            foreach ($lote->itens as $item) {
                if ($processados >= $limite) {
                    return;
                }
                $processar($item);
                $processados++;
            }

            $ultNsu = $lote->ultNsuRetornado;
        } while ($lote->temMais() && $processados < $limite);

        if (! $lote->temMais()) {
            $this->controle->registrarNsuSemNovidades();
        }
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
            $this->controle->garantirLiberado();
            $this->client->manifestarCiencia($item->chaveAcesso);
            $completo = $this->tentarConsultar($item->chaveAcesso);

            if ($completo instanceof SefazDocumentoCompleto) {
                $this->importador->importar($completo->xmlCompleto, $userId);

                return $resultado->comIncremento('importadas');
            }

            $this->registrarPendente($item->chaveAcesso, $item->cnpjEmitente);

            return $resultado->comIncremento('pendentes');
        } catch (SefazConsultaEmEsperaException $e) {
            if ($e->bloqueioGeral) {
                throw $e;
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
            $completo = $this->tentarConsultar($pendente->sdp_chave_acesso);

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
        } catch (SefazConsultaEmEsperaException $e) {
            if ($e->bloqueioGeral) {
                throw $e;
            }

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
     * mesmo stream de NSU da SEFAZ, cada um com seu bookmark. A espera de 1
     * hora após "sem novidades" é por CNPJ, então vale pros dois.
     *
     * @throws SefazConsultaEmEsperaException se o CNPJ estiver bloqueado ou ainda na espera de 1 hora
     */
    public function sincronizarNotasRecebidas(): SefazSincronizacaoResultado
    {
        $liberadoEm = $this->controle->nsuLiberadoEm();
        if ($liberadoEm !== null) {
            throw SefazConsultaEmEsperaException::semNovidades($liberadoEm);
        }

        $empresa = Empresa::firstOrFail();
        $resultado = new SefazSincronizacaoResultado;
        $ultNsu = (int) $empresa->empresa_sefaz_ultimo_nsu_revisao;

        try {
            $this->percorrerNsu($ultNsu, (int) config('sefaz.max_documentos_por_execucao', 50), function (SefazResumoDocumento $item) use (&$resultado): void {
                $resultado = $this->registrarResumo($item, $resultado);
            });
        } finally {
            $empresa->forceFill([
                'empresa_sefaz_ultimo_nsu_revisao' => $ultNsu,
                'empresa_sefaz_ultima_revisao_em' => now(),
            ])->save();
        }

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
