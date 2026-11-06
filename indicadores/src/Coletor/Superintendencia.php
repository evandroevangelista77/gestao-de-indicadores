<?php

namespace GlpiPlugin\Indicadores\Coletor;

use GlpiPlugin\Indicadores\KpiMotor;

/**
 * Superintendência Operacional no painel executivo: um indicador por área do painel de KPIs (Operações, OTC,
 * PMO, Sistemas Internos...), com o resultado ponderado dos KPIs da área no mês e a meta da superintendência.
 */
class Superintendencia extends Coletor
{
    public function codigo(): string { return 'so'; }

    public function nome(): string
    {
        return (string) (KpiMotor::raiz()['name'] ?? 'Superintendência Operacional');
    }

    public function icone(): string { return 'ti ti-building-factory-2'; }

    public function cor(): string { return '#0f766e'; }

    public function descricao(): string { return 'Resultado dos KPIs de cada área da superintendência'; }

    protected function tabelas(): array { return ['glpi_plugin_indicadores_kpiareas', 'glpi_plugin_indicadores_kpis']; }

    public function disponivel(): bool
    {
        return parent::disponivel() && KpiMotor::areas() !== [];
    }

    public function indicadores(): array
    {
        $meta = (float) (KpiMotor::raiz()['meta'] ?? 90);
        $out = [];
        foreach (KpiMotor::areas() as $a) {
            $out['so.a' . (int) $a['id']] = $this->def('Resultado dos KPIs · ' . $a['name'], '%', 'up', $meta, max(0, $meta - 10), 1, false, 'Atingimento ponderado dos KPIs da área no mês (painel de KPIs)');
        }
        return $out;
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $ym = substr($de, 0, 7);
        $d = KpiMotor::ano((int) substr($ym, 0, 4));
        $out = [];
        foreach ($d['areas'] as $a) {
            $out['so.a' . (int) $a['id']] = $d['resultados'][(int) $a['id']][$ym] ?? null;
        }
        return $out;
    }
}
