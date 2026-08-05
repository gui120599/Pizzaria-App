<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nota já manifestada (Ciência da Operação) na SEFAZ, mas cujo XML completo
 * ainda não propagou. O cursor de NSU do polling já consumiu o resumo dessa
 * nota, então não dá pra "pedir de novo" via distNSU — fica registrada aqui
 * pra ser reconsultada por chave nos próximos ciclos.
 */
class SefazDocumentoPendente extends Model
{
    protected $table = 'sefaz_documentos_pendentes';

    protected $fillable = [
        'sdp_chave_acesso',
        'sdp_cnpj_emitente',
        'sdp_manifestado_em',
        'sdp_tentativas',
        'sdp_ultima_tentativa_em',
    ];

    protected function casts(): array
    {
        return [
            'sdp_manifestado_em' => 'datetime',
            'sdp_ultima_tentativa_em' => 'datetime',
        ];
    }
}
