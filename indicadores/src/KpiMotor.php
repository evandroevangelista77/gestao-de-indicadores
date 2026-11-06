<?php

namespace GlpiPlugin\Indicadores;

/**
 * Cálculo dos KPIs por área: atingimento de cada KPI contra a meta, resultado ponderado da área no mês e
 * consolidado da Superintendência.
 *
 * Atingimento (0 a 100): "maior é melhor" = valor ÷ meta; "menor é melhor" = 100 quando o valor está na meta
 * ou abaixo e meta ÷ valor acima dela. O resultado da área no mês é a média dos atingimentos ponderada pelo
 * peso, considerando só os KPIs que têm valor naquele mês. O consolidado é a média simples das áreas.
 */
class KpiMotor
{
    public const VALORES = 'glpi_plugin_indicadores_kpivalores';

    /** Número digitado em português: "1.234,5" => 1234.5; "99.98" => 99.98; vazio => null. */
    public static function numero($v): ?float
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $v = str_contains($v, ',') ? str_replace(',', '.', str_replace('.', '', $v)) : $v;
        return is_numeric($v) ? (float) $v : null;
    }

    public static function raiz(): ?array
    {
        global $DB;
        if (!$DB->tableExists(KpiArea::getTable())) {
            return null;
        }
        return $DB->request(['FROM' => KpiArea::getTable(), 'WHERE' => ['kind' => 'root'], 'LIMIT' => 1])->current() ?: null;
    }

    /** Áreas debaixo da superintendência, na ordem. */
    public static function areas(bool $apenasAtivas = true): array
    {
        global $DB;
        if (!$DB->tableExists(KpiArea::getTable())) {
            return [];
        }
        return iterator_to_array($DB->request(['FROM' => KpiArea::getTable(), 'WHERE' => ['kind' => 'area'] + ($apenasAtivas ? ['is_active' => 1] : []), 'ORDER' => ['position', 'id']]), false);
    }

    public static function kpis(int $areaId, bool $apenasAtivos = true): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => Kpi::getTable(), 'WHERE' => ['plugin_indicadores_kpiareas_id' => $areaId] + ($apenasAtivos ? ['is_active' => 1] : []), 'ORDER' => ['position', 'id']]), false);
    }

    /** Valores lançados: kpi => [mês (YYYY-MM) => valor]. */
    public static function valores(array $kpiIds, array $meses): array
    {
        global $DB;
        $out = [];
        if (!$kpiIds || !$meses) {
            return $out;
        }
        foreach ($DB->request(['FROM' => self::VALORES, 'WHERE' => ['plugin_indicadores_kpis_id' => $kpiIds, 'period' => $meses]]) as $r) {
            $out[(int) $r['plugin_indicadores_kpis_id']][$r['period']] = $r['value'] === null ? null : (float) $r['value'];
        }
        return $out;
    }

    public static function salvar(int $kpiId, string $ym, ?float $valor): void
    {
        global $DB;
        $DB->updateOrInsert(self::VALORES, ['value' => $valor, 'date_mod' => date('Y-m-d H:i:s')], ['plugin_indicadores_kpis_id' => $kpiId, 'period' => $ym]);
    }

    public static function aplica(array $kpi, int $mes): bool
    {
        return in_array($mes, Kpi::MESES[$kpi['freq']] ?? Kpi::MESES['mensal'], true);
    }

    /** Atingimento do KPI (percentual da meta; o que passa de 100 conta como 100 no resultado, mas é mostrado). */
    public static function atingimento(array $kpi, ?float $valor): ?float
    {
        if ($valor === null || $kpi['meta'] === null) {
            return null;
        }
        $meta = (float) $kpi['meta'];
        if ($kpi['dir'] === 'down') {
            if ($valor <= $meta) {
                return 100.0;
            }
            return $valor > 0 ? round(100 * $meta / $valor, 1) : 100.0;
        }
        return $meta > 0 ? round(100 * $valor / $meta, 1) : ($valor >= 0 ? 100.0 : null);
    }

    /** Semáforo do atingimento: verde a partir de 100%, amarelo a partir de 90%, vermelho abaixo. */
    public static function status(?float $atingimento): string
    {
        return $atingimento === null ? Score::NONE : ($atingimento >= 100 ? Score::OK : ($atingimento >= 90 ? Score::WARN : Score::BAD));
    }

    /** Semáforo do resultado de uma área ou da superintendência contra a meta dela (verde na meta, amarelo até 10 pontos abaixo). */
    public static function statusResultado(?float $resultado, float $meta): string
    {
        return $resultado === null ? Score::NONE : ($resultado >= $meta ? Score::OK : ($resultado >= $meta - 10 ? Score::WARN : Score::BAD));
    }

    /**
     * Resultado da área num mês, a partir dos KPIs e valores já carregados.
     *
     * @param array<int, array> $kpis   KPIs da área
     * @param array<int, array> $valores kpi => mês => valor
     */
    public static function resultadoArea(array $kpis, array $valores, string $ym): ?float
    {
        $soma = 0.0;
        $pesos = 0;
        foreach ($kpis as $k) {
            $peso = (int) $k['peso'];
            if ($peso <= 0 || !self::aplica($k, (int) substr($ym, 5, 2))) {
                continue;
            }
            $at = self::atingimento($k, $valores[(int) $k['id']][$ym] ?? null);
            if ($at === null) {
                continue;
            }
            $soma += min(100.0, $at) * $peso;
            $pesos += $peso;
        }
        return $pesos ? round($soma / $pesos, 1) : null;
    }

    /**
     * Resultados de um ano: área => mês => resultado, mais o consolidado (média das áreas).
     *
     * @return array{areas: array, kpis: array, valores: array, resultados: array, consolidado: array}
     */
    public static function ano(int $ano): array
    {
        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $meses[] = sprintf('%04d-%02d', $ano, $m);
        }
        $areas = self::areas();
        $kpis = [];
        $ids = [];
        foreach ($areas as $a) {
            $kpis[(int) $a['id']] = self::kpis((int) $a['id']);
            $ids = array_merge($ids, array_map('intval', array_column($kpis[(int) $a['id']], 'id')));
        }
        $valores = self::valores($ids, $meses);
        $res = [];
        $cons = [];
        foreach ($meses as $ym) {
            $vals = [];
            foreach ($areas as $a) {
                $r = self::resultadoArea($kpis[(int) $a['id']], $valores, $ym);
                $res[(int) $a['id']][$ym] = $r;
                if ($r !== null) {
                    $vals[] = $r;
                }
            }
            $cons[$ym] = $vals ? round(array_sum($vals) / count($vals), 1) : null;
        }
        return ['areas' => $areas, 'kpis' => $kpis, 'valores' => $valores, 'resultados' => $res, 'consolidado' => $cons, 'meses' => $meses];
    }

    /** Média dos resultados mensais que existem (acumulado do ano). */
    public static function acumulado(array $porMes): ?float
    {
        $v = array_filter($porMes, fn ($x) => $x !== null);
        return $v ? round(array_sum($v) / count($v), 1) : null;
    }
}
