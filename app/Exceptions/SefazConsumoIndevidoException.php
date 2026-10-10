<?php

namespace App\Exceptions;

/**
 * SEFAZ respondeu cStat 656 (Consumo Indevido) no NFeDistribuicaoDFe: o CNPJ
 * passou do limite de consultas e fica bloqueado por 1 hora — qualquer nova
 * consulta antes disso reinicia a contagem (NT 2014.002). Lançada pelo
 * decoder; o SefazDistribuicaoService registra o bloqueio e a converte em
 * SefazConsultaEmEsperaException com o horário de liberação.
 */
class SefazConsumoIndevidoException extends SefazIndisponivelException {}
