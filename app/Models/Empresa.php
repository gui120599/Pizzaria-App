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
        'empresa_status',
    ];

    protected $casts = [
        'empresa_regime_tributario' => 'string',
        'empresa_status' => 'string',
    ];
}
