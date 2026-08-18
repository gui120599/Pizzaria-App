<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empresa extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'empresa_razao_social',
        'empresa_nome_fantasia',
        'empresa_cnpj',
        'empresa_regime_tributario',
        'empresa_endereco_uf_estado',
        'empresa_endereco_cidade_id_ibge',
        'empresa_endereco_rua',
        'empresa_endereco_numero_endereco',
        'empresa_endereco_cep',
        'empresa_endereco_complemento_endereco',
        'empresa_endereco_bairro',
        'empresa_endereco',
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
    ];

    protected $casts = [
        'empresa_regime_tributario' => 'string',
        'empresa_status' => 'string',
        'empresa_certificado_senha' => 'encrypted',
        'empresa_certificado_validade' => 'date',
        'empresa_certificado_atualizado_em' => 'datetime',
        'empresa_sefaz_ultima_consulta_em' => 'datetime',
        'empresa_sefaz_auto_importacao_ativa' => 'boolean',
        'empresa_sefaz_ultima_revisao_em' => 'datetime',
    ];

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
