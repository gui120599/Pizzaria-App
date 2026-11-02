<?php

namespace App\Models;

use App\Enums\DestinoAvisoMesaEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class Empresa extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'empresa_razao_social',
        'empresa_nome_fantasia',
        'empresa_cnpj',
        'empresa_regime_tributario',
        'empresa_percentual_imposto_nfe',
        'empresa_endereco_uf_estado',
        'empresa_endereco_cidade_id_ibge',
        'empresa_endereco_rua',
        'empresa_endereco_numero_endereco',
        'empresa_endereco_cep',
        'empresa_endereco_complemento_endereco',
        'empresa_endereco_bairro',
        'empresa_endereco',
        'empresa_google_maps_url',
        'empresa_api_nfeio_conta_id',
        'empresa_api_nfeio_company_id',
        'empresa_api_nfeio_apikey',
        'empresa_api_nfeio_ambiente',
        'empresa_status',
        'empresa_certificado_path',
        'empresa_certificado_senha',
        'empresa_certificado_titular_cnpj',
        'empresa_certificado_titular_nome',
        'empresa_certificado_validade',
        'empresa_certificado_atualizado_em',
        'empresa_sefaz_ambiente',
        'empresa_sefaz_ultimo_nsu',
        'empresa_sefaz_ultima_consulta_em',
        'empresa_sefaz_auto_importacao_ativa',
        'empresa_sefaz_ultimo_nsu_revisao',
        'empresa_sefaz_ultima_revisao_em',
        'empresa_validade_link_ticket_horas',
        'empresa_push_destino_mesa',
        'empresa_push_repetir_ate_atender',
        'empresa_push_repetir_intervalo_min',
        'empresa_push_repetir_max',
        'empresa_plano_despesa_taxa_cartao_id',
        'empresa_plano_despesa_quebra_caixa_id',
        'empresa_plano_receita_sobra_caixa_id',
        'empresa_influ_teto_percentual',
        'empresa_influ_teto_valor_fixo',
        'empresa_influ_teto_usos_total',
        'empresa_influ_comissao_tipo',
        'empresa_influ_comissao_valor',
        'empresa_influ_plano_despesa_id',
        'empresa_influ_conta_pix_id',
    ];

    protected $casts = [
        'empresa_regime_tributario' => 'string',
        'empresa_percentual_imposto_nfe' => 'decimal:2',
        'empresa_status' => 'string',
        'empresa_certificado_senha' => 'encrypted',
        'empresa_certificado_validade' => 'date',
        'empresa_certificado_atualizado_em' => 'datetime',
        'empresa_sefaz_ultima_consulta_em' => 'datetime',
        'empresa_sefaz_auto_importacao_ativa' => 'boolean',
        'empresa_sefaz_ultima_revisao_em' => 'datetime',
        'empresa_influ_teto_percentual' => 'decimal:2',
        'empresa_influ_teto_valor_fixo' => 'decimal:2',
        'empresa_influ_comissao_valor' => 'decimal:2',
        'empresa_push_destino_mesa' => DestinoAvisoMesaEnum::class,
        'empresa_push_repetir_ate_atender' => 'boolean',
    ];

    public const VALIDADE_LINK_TICKET_PADRAO_HORAS = 24;

    protected static function booted(): void
    {
        static::saved(function (): void {
            Cache::driver('array')->forget('empresa.validade_link_ticket_horas');
            Cache::driver('array')->forget('empresa.configuracao_push');
        });
    }

    /**
     * Quantas horas o link assinado do ticket de pedido segue válido. Memoizada
     * por request (cache "array"): o painel monta um link por card.
     */
    public static function validadeLinkTicketHoras(): int
    {
        return (int) Cache::driver('array')->rememberForever(
            'empresa.validade_link_ticket_horas',
            fn (): int => (int) (static::query()->value('empresa_validade_link_ticket_horas') ?: self::VALIDADE_LINK_TICKET_PADRAO_HORAS),
        );
    }

    /**
     * Parâmetros do push do salão (seção "Notificações do salão" de
     * ConfiguracaoPedidos), lidos num ponto só. Sem empresa cadastrada vale o padrão.
     * Memoizada por request, como a validade do ticket.
     *
     * @return array{destino: DestinoAvisoMesaEnum, repetir: bool, intervalo_min: int, repetir_max: int}
     */
    public static function configuracaoPush(): array
    {
        return Cache::driver('array')->rememberForever('empresa.configuracao_push', function (): array {
            $empresa = static::query()->first();

            return [
                'destino' => $empresa?->empresa_push_destino_mesa ?? DestinoAvisoMesaEnum::GARCOM_DA_MESA,
                'repetir' => (bool) ($empresa?->empresa_push_repetir_ate_atender ?? true),
                'intervalo_min' => max(1, min(30, (int) ($empresa?->empresa_push_repetir_intervalo_min ?: 2))),
                'repetir_max' => max(1, min(60, (int) ($empresa?->empresa_push_repetir_max ?: 10))),
            ];
        });
    }

    /** Domínios aceitos no link da loja no Google Maps (ele aparece numa página pública). */
    public const DOMINIOS_GOOGLE_MAPS = ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl', 'goo.gl'];

    public static function urlGoogleMapsValida(?string $url): bool
    {
        $partes = parse_url((string) $url);

        return ($partes['scheme'] ?? null) === 'https'
            && in_array(strtolower($partes['host'] ?? ''), self::DOMINIOS_GOOGLE_MAPS, true);
    }

    /**
     * Onde retirar o pedido: "Rua, número – complemento – bairro – CEP", só
     * com as partes preenchidas. Sem rua não há endereço.
     */
    public function enderecoRetirada(): ?string
    {
        if (blank($this->empresa_endereco_rua)) {
            return null;
        }

        $logradouro = collect([$this->empresa_endereco_rua, $this->empresa_endereco_numero_endereco])
            ->filter(fn ($parte) => filled($parte))
            ->map(fn ($parte) => trim((string) $parte))
            ->implode(', ');

        return collect([
            $logradouro,
            $this->empresa_endereco_complemento_endereco,
            $this->empresa_endereco_bairro,
            $this->empresa_endereco_cep,
        ])
            ->filter(fn ($parte) => filled($parte))
            ->map(fn ($parte) => trim((string) $parte))
            ->implode(' – ');
    }

    /** Link "Como chegar": o cadastrado; sem ele, uma busca no Google Maps pelo endereço. */
    public function linkComoChegar(): ?string
    {
        if (self::urlGoogleMapsValida($this->empresa_google_maps_url)) {
            return $this->empresa_google_maps_url;
        }

        $endereco = $this->enderecoRetirada();

        return $endereco === null
            ? null
            : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($endereco);
    }

    /** Certificado A1 (.pfx) + senha já cadastrados, prontos pra autenticar na SEFAZ. */
    public function certificadoConfigurado(): bool
    {
        return filled($this->empresa_certificado_path) && filled($this->empresa_certificado_senha);
    }

    public function certificadoVencido(): bool
    {
        return $this->empresa_certificado_validade !== null
            && $this->empresa_certificado_validade->isPast();
    }

    /** Company id + api key da NFe.io já cadastrados, prontos pra emitir/consultar/cancelar NFC-e. */
    public function nfeIoConfigurado(): bool
    {
        return filled($this->empresa_api_nfeio_company_id) && filled($this->empresa_api_nfeio_apikey);
    }
}
