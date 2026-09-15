<?php

namespace App\Concerns;

use App\Enums\PromocaoStatusEnum;
use Carbon\CarbonInterface;

/**
 * Vigência com janela única OU recorrência por dia da semana + horário do
 * dia (inclusive overnight, ex.: 22:00–02:00), reaproveitada entre
 * PromocaoRelampago e PromocaoAdicional. Cada model expõe seus próprios
 * campos (prefixo de coluna diferente) através dos métodos abstratos abaixo
 * — a lógica de janela/recorrência em si vive só aqui.
 */
trait TemVigenciaRecorrente
{
    abstract protected function vigAtiva(): bool;

    abstract protected function vigRecorrente(): bool;

    abstract protected function vigInicio(): ?CarbonInterface;

    abstract protected function vigFim(): ?CarbonInterface;

    /** @return array<int, int> */
    abstract protected function vigDiasSemana(): array;

    abstract protected function vigHoraInicio(): ?CarbonInterface;

    abstract protected function vigHoraFim(): ?CarbonInterface;

    abstract protected function vigDataFinalRecorrencia(): ?CarbonInterface;

    abstract protected function vigUltimoResetEm(): ?CarbonInterface;

    /** Esgotamento é um conceito por model (pool, sublimite, etc.) — delega. */
    abstract protected function vigEsgotada(): bool;

    /**
     * Ativa e dentro da janela de vigência (única ou recorrente). Não
     * considera saldo/esgotamento.
     */
    public function vigente(?CarbonInterface $momento = null): bool
    {
        $momento ??= now();

        if (! $this->vigAtiva()) {
            return false;
        }

        if ($this->vigRecorrente()) {
            return $this->vigenteRecorrente($momento);
        }

        return $this->vigInicio() <= $momento && $this->vigFim() >= $momento;
    }

    public function status(?CarbonInterface $momento = null): PromocaoStatusEnum
    {
        $momento ??= now();

        if (! $this->vigAtiva()) {
            return PromocaoStatusEnum::Inativa;
        }

        if ($this->vigRecorrente()) {
            return $this->statusRecorrente($momento);
        }

        return match (true) {
            $this->vigFim() < $momento => PromocaoStatusEnum::Encerrada,
            $this->vigInicio() > $momento => PromocaoStatusEnum::Agendada,
            $this->vigEsgotada() => PromocaoStatusEnum::Esgotada,
            default => PromocaoStatusEnum::Ativa,
        };
    }

    /**
     * A recorrência acabou de abrir uma nova ocorrência e ainda não resetou o
     * contador hoje? Comparação por dia (não por horário exato) para tolerar
     * atraso do scheduler: assim que rodar depois do horário de início, reseta.
     */
    public function deveResetarAgora(?CarbonInterface $momento = null): bool
    {
        $momento ??= now();

        if (! $this->vigAtiva() || ! $this->vigRecorrente()) {
            return false;
        }

        if ($this->vigUltimoResetEm()?->isSameDay($momento)) {
            return false;
        }

        if (! $this->dentroDoIntervaloDeDatas($momento) || ! $this->diaDaSemanaBate($momento)) {
            return false;
        }

        if ($this->vigHoraInicio() && $momento->format('H:i:s') < $this->vigHoraInicio()->format('H:i:s')) {
            return false;
        }

        return true;
    }

    /**
     * Ocorrência atual: dentro do intervalo de datas da recorrência, no dia da
     * semana certo e (se houver janela) dentro do horário. Sem hora_inicio/fim
     * configurados, vale o dia inteiro nos dias marcados.
     *
     * Janela que atravessa a meia-noite (ex.: 22:00–02:00) tem um cuidado: às
     * 01:30 de quarta a ocorrência ainda é a de TERÇA (que começou ontem) — o
     * dia da semana é checado contra ontem, não contra hoje, nesse trecho.
     */
    private function vigenteRecorrente(CarbonInterface $momento): bool
    {
        if (! $this->dentroDoIntervaloDeDatas($momento)) {
            return false;
        }

        if (! $this->vigHoraInicio() || ! $this->vigHoraFim()) {
            return $this->diaDaSemanaBate($momento);
        }

        $hora = $momento->format('H:i:s');
        $inicio = $this->vigHoraInicio()->format('H:i:s');
        $fim = $this->vigHoraFim()->format('H:i:s');

        if ($inicio <= $fim) {
            return $this->diaDaSemanaBate($momento) && $hora >= $inicio && $hora <= $fim;
        }

        // Atravessa meia-noite: madrugada (hora <= fim) é ocorrência de ontem;
        // noite (hora >= início) é a ocorrência de hoje.
        if ($hora <= $fim) {
            return $this->diaDaSemanaBate($momento->copy()->subDay());
        }

        return $hora >= $inicio && $this->diaDaSemanaBate($momento);
    }

    private function statusRecorrente(CarbonInterface $momento): PromocaoStatusEnum
    {
        if ($this->vigInicio() && $momento->lt($this->vigInicio())) {
            return PromocaoStatusEnum::Agendada;
        }

        if ($this->vigDataFinalRecorrencia() && $momento->toDateString() > $this->vigDataFinalRecorrencia()->toDateString()) {
            return PromocaoStatusEnum::Encerrada;
        }

        if (! $this->vigenteRecorrente($momento)) {
            return PromocaoStatusEnum::AguardandoJanela;
        }

        if ($this->vigEsgotada()) {
            return PromocaoStatusEnum::Esgotada;
        }

        return PromocaoStatusEnum::Ativa;
    }

    /** Início/fim da recorrência (datas), sem considerar dia da semana ou hora. */
    private function dentroDoIntervaloDeDatas(CarbonInterface $momento): bool
    {
        if ($this->vigInicio() && $momento->lt($this->vigInicio())) {
            return false;
        }

        if ($this->vigDataFinalRecorrencia() && $momento->toDateString() > $this->vigDataFinalRecorrencia()->toDateString()) {
            return false;
        }

        return true;
    }

    /** Vazio = todos os dias. */
    private function diaDaSemanaBate(CarbonInterface $momento): bool
    {
        $dias = $this->vigDiasSemana();

        return empty($dias) || in_array($momento->dayOfWeek, $dias, false);
    }
}
