<?php

namespace GlpiPlugin\Indicadores;

use Throwable;

/**
 * Fontes de coleta automática dos KPIs: de onde o sistema tira o valor de um KPI sem digitação. Cada fonte
 * calcula, para um período, um valor (em geral um percentual) a partir dos dados do GLPI e dos plugins.
 *
 * Fontes de chamados usam o escopo da área (entidade com as filhas, grupo atribuído e categoria), definido
 * em Configuração. Valor nulo significa "sem dados no período": o KPI fica em branco, nunca zerado.
 */
class Fontes
{
    /** código => [rótulo, descrição, rótulo do parâmetro (ou null), dica do parâmetro] */
    public const CATALOGO = [
        'chamados_sla'        => ['Chamados solucionados no prazo', 'Percentual dos chamados solucionados no período dentro do prazo de solução (SLA do chamado).', 'Prazo padrão (horas)', 'Opcional: vale para chamado sem SLA. Ex.: 24'],
        'incidentes_sla'      => ['Incidentes solucionados no prazo', 'Igual ao anterior, só para incidentes.', 'Prazo padrão (horas)', 'Opcional. Ex.: 8'],
        'requisicoes_sla'     => ['Requisições solucionadas no prazo', 'Igual ao anterior, só para requisições.', 'Prazo padrão (horas)', 'Opcional. Ex.: 72'],
        'chamados_satisfacao' => ['Chamados com avaliação positiva', 'Percentual de avaliações por estrelas com nota 4 ou 5 (pesquisa CSAT dos chamados).', null, ''],
        'chamados_reclamacao' => ['Chamados com avaliação negativa', 'Percentual de avaliações por estrelas com nota 1 ou 2 (pesquisa CSAT dos chamados).', null, ''],
        'pmo_prazo'           => ['Tarefas de projeto entregues no prazo', 'Percentual das tarefas de projeto com fim planejado no período concluídas até a data planejada.', null, ''],
        'pmo_satisfacao'      => ['Projetos com avaliação positiva', 'Percentual de avaliações com nota 4 ou 5 na pesquisa CSAT dos projetos.', null, ''],
        'ciclo_fase'          => ['Fase do Ciclo Financeiro dentro do SLO', 'Percentual das fases concluídas no período, da fase escolhida, dentro do SLO.', 'Fase do ciclo', 'Escolha a fase'],
        'ciclo_devolucao'     => ['Fases do Ciclo Financeiro devolvidas', 'Percentual das fases concluídas no período que foram devolvidas pelo menos uma vez.', null, ''],
        'lancamento_prazo'    => ['KPIs da área lançados no prazo', 'Percentual dos KPIs manuais da área lançados até o dia 10 do mês seguinte.', null, ''],
        'zabbix_sli'          => ['Disponibilidade (SLI do Zabbix)', 'SLI do período numa regra de SLA do Zabbix (plugin Sistemas Internos configurado).', 'SLA do Zabbix', 'ID do SLA, ou ID:serviço. Ex.: 3 ou 3:12'],
    ];

    public static function rotulo(?string $code): string
    {
        return $code && isset(self::CATALOGO[$code]) ? self::CATALOGO[$code][0] : 'Manual (sem coleta)';
    }

    /** Fases do Ciclo Financeiro (código => nome), para o parâmetro da fonte "ciclo_fase". */
    public static function fasesCiclo(): array
    {
        global $DB;
        $out = [];
        if ($DB->tableExists('glpi_plugin_ciclo_etapas')) {
            foreach ($DB->request(['SELECT' => ['code', 'name'], 'FROM' => 'glpi_plugin_ciclo_etapas', 'ORDER' => ['position', 'id']]) as $r) {
                $out[$r['code']] = $r['name'];
            }
        }
        return $out;
    }

    /** Quais fontes dependem de plugins que não estão instalados (para avisar na tela). */
    public static function disponivel(string $code): bool
    {
        global $DB;
        return match ($code) {
            'chamados_satisfacao', 'chamados_reclamacao', 'pmo_satisfacao' => $DB->tableExists('glpi_plugin_satisfacao_surveys'),
            'ciclo_fase', 'ciclo_devolucao' => $DB->tableExists('glpi_plugin_ciclo_fases'),
            'zabbix_sli' => class_exists('GlpiPlugin\\Sistemasinternos\\Zabbix'),
            default => true,
        };
    }

    /** Valor da fonte para o período [de, ate] (Y-m-d). */
    public static function valor(string $fonte, array $area, array $kpi, string $de, string $ate): ?float
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) || !self::disponivel($fonte)) {
            return null;
        }
        $i = "$de 00:00:00";
        $f = "$ate 23:59:59";
        $param = trim((string) ($kpi['source_param'] ?? ''));
        try {
            return match ($fonte) {
                'chamados_sla'        => self::sla($area, $i, $f, null, $param),
                'incidentes_sla'      => self::sla($area, $i, $f, 1, $param),
                'requisicoes_sla'     => self::sla($area, $i, $f, 2, $param),
                'chamados_satisfacao' => self::csat($area, $i, $f, 'Ticket', true),
                'chamados_reclamacao' => self::csat($area, $i, $f, 'Ticket', false),
                'pmo_satisfacao'      => self::csat(null, $i, $f, 'Project', true),
                'pmo_prazo'           => self::pmoPrazo($i, $f),
                'ciclo_fase'          => self::cicloFase($i, $f, $param),
                'ciclo_devolucao'     => self::cicloDevolucao($i, $f),
                'lancamento_prazo'    => self::lancamento($area, $kpi, $ate),
                'zabbix_sli'          => self::zabbix($de, $ate, $param),
                default               => null,
            };
        } catch (Throwable $e) {
            trigger_error('Indicadores: fonte ' . $fonte . ' falhou: ' . $e->getMessage(), E_USER_WARNING);
            return null;
        }
    }

    // ------------------------------------------------------------------ consultas

    private static function linha(string $sql): array
    {
        global $DB;
        $res = $DB->doQuery($sql);
        return ($res ? $res->fetch_assoc() : null) ?: [];
    }

    private static function pct($parte, $total): ?float
    {
        return $total > 0 ? round(100 * (float) $parte / (float) $total, 1) : null;
    }

    /** Filtro SQL dos chamados da área; null quando a área não tem escopo configurado. */
    public static function filtroChamados(array $area, string $a = ''): ?string
    {
        if ($area['entities_id'] === null) {
            return null;
        }
        $p = $a === '' ? '' : $a . '.';
        $ents = [(int) $area['entities_id']];
        try {
            $ents = array_map('intval', array_values(getSonsOf('glpi_entities', (int) $area['entities_id'])));
        } catch (Throwable) {
        }
        $sql = "{$p}is_deleted = 0 AND {$p}entities_id IN (" . implode(',', $ents ?: [0]) . ')';
        if ((int) ($area['groups_id'] ?? 0) > 0) {
            $sql .= " AND {$p}id IN (SELECT tickets_id FROM glpi_groups_tickets WHERE type = 2 AND groups_id = " . (int) $area['groups_id'] . ')';
        }
        if ((int) ($area['itilcategories_id'] ?? 0) > 0) {
            $cats = [(int) $area['itilcategories_id']];
            try {
                $cats = array_map('intval', array_values(getSonsOf('glpi_itilcategories', (int) $area['itilcategories_id'])));
            } catch (Throwable) {
            }
            $sql .= " AND {$p}itilcategories_id IN (" . implode(',', $cats ?: [0]) . ')';
        }
        return $sql;
    }

    private static function sla(array $area, string $i, string $f, ?int $tipo, string $param): ?float
    {
        $filtro = self::filtroChamados($area);
        if ($filtro === null) {
            return null;
        }
        $horas = is_numeric($param) && (float) $param > 0 ? (float) $param : null;
        $minutos = $horas === null ? 'NULL' : (string) round($horas * 60);
        $r = self::linha("SELECT COUNT(*) AS n,
            SUM(CASE WHEN time_to_resolve IS NOT NULL OR $minutos IS NOT NULL THEN 1 ELSE 0 END) AS base,
            SUM(CASE WHEN (time_to_resolve IS NOT NULL AND solvedate <= time_to_resolve)
                       OR (time_to_resolve IS NULL AND $minutos IS NOT NULL AND TIMESTAMPDIFF(MINUTE, date, solvedate) <= $minutos) THEN 1 ELSE 0 END) AS ok
            FROM glpi_tickets WHERE $filtro" . ($tipo ? " AND type = $tipo" : '') . " AND solvedate BETWEEN '$i' AND '$f'");
        return self::pct($r['ok'] ?? 0, $r['base'] ?? 0);
    }

    /** Percentual de avaliações positivas (nota 4 ou 5) ou negativas (1 ou 2) da pesquisa CSAT de chamados ou projetos. */
    private static function csat(?array $area, string $i, string $f, string $itemtype, bool $positivas): ?float
    {
        $where = '';
        if ($itemtype === 'Ticket') {
            if ($area === null || $area['entities_id'] === null) {
                return null;
            }
            // a entidade vem da própria pesquisa; grupo e categoria exigem olhar o chamado
            $ents = [(int) $area['entities_id']];
            try {
                $ents = array_map('intval', array_values(getSonsOf('glpi_entities', (int) $area['entities_id'])));
            } catch (Throwable) {
            }
            $where = ' AND s.entities_id IN (' . implode(',', $ents ?: [0]) . ')';
            if ((int) ($area['groups_id'] ?? 0) > 0 || (int) ($area['itilcategories_id'] ?? 0) > 0) {
                $where .= ' AND s.items_id IN (SELECT t.id FROM glpi_tickets t WHERE ' . self::filtroChamados($area, 't') . ')';
            }
        }
        $r = self::linha("SELECT COUNT(*) AS n, SUM(CASE WHEN a.score >= 4 THEN 1 ELSE 0 END) AS pos, SUM(CASE WHEN a.score <= 2 THEN 1 ELSE 0 END) AS neg
            FROM glpi_plugin_satisfacao_answers a JOIN glpi_plugin_satisfacao_surveys s ON s.id = a.plugin_satisfacao_surveys_id
            WHERE a.type = 'rating' AND a.score IS NOT NULL AND s.status = 1 AND s.itemtype = '$itemtype' AND s.date_answered BETWEEN '$i' AND '$f' $where");
        return self::pct($positivas ? ($r['pos'] ?? 0) : ($r['neg'] ?? 0), $r['n'] ?? 0);
    }

    private static function pmoPrazo(string $i, string $f): ?float
    {
        $r = self::linha("SELECT COUNT(*) AS n, SUM(CASE WHEN t.percent_done >= 100 AND (t.real_end_date IS NULL OR t.real_end_date <= t.plan_end_date) THEN 1 ELSE 0 END) AS ok
            FROM glpi_projecttasks t JOIN glpi_projects p ON p.id = t.projects_id AND p.is_deleted = 0 AND p.is_template = 0
            WHERE t.is_deleted = 0 AND t.is_template = 0 AND t.plan_end_date BETWEEN '$i' AND '$f' AND t.plan_end_date <= NOW()");
        return self::pct($r['ok'] ?? 0, $r['n'] ?? 0);
    }

    private static function cicloFase(string $i, string $f, string $code): ?float
    {
        global $DB;
        if ($code === '') {
            return null;
        }
        $c = $DB->escape($code);
        $r = self::linha("SELECT COUNT(*) AS n, SUM(CASE WHEN seconds <= slo_days * 86400 THEN 1 ELSE 0 END) AS ok FROM glpi_plugin_ciclo_fases
            WHERE status = 'done' AND slo_days > 0 AND code = '$c' AND date_end BETWEEN '$i' AND '$f'");
        return self::pct($r['ok'] ?? 0, $r['n'] ?? 0);
    }

    private static function cicloDevolucao(string $i, string $f): ?float
    {
        $r = self::linha("SELECT COUNT(*) AS n, SUM(CASE WHEN returns > 0 THEN 1 ELSE 0 END) AS dev FROM glpi_plugin_ciclo_fases WHERE status = 'done' AND date_end BETWEEN '$i' AND '$f'");
        return self::pct($r['dev'] ?? 0, $r['n'] ?? 0);
    }

    /** Dos KPIs manuais da área apurados no mês, quantos foram lançados até o dia 10 do mês seguinte. */
    private static function lancamento(array $area, array $kpi, string $ate): ?float
    {
        global $DB;
        $ym = substr($ate, 0, 7);
        $limite = date('Y-m-10 23:59:59', strtotime("$ym-01 +1 month"));
        if (date('Y-m-d H:i:s') < $limite && $ym >= date('Y-m')) {
            return null; // o prazo ainda não fechou
        }
        $mes = (int) substr($ym, 5, 2);
        $ids = [];
        foreach (KpiMotor::kpis((int) $area['id']) as $k) {
            if ((int) $k['id'] !== (int) $kpi['id'] && (int) $k['peso'] > 0 && empty($k['source']) && KpiMotor::aplica($k, $mes)) {
                $ids[] = (int) $k['id'];
            }
        }
        if (!$ids) {
            return null;
        }
        $ok = 0;
        $lancados = iterator_to_array($DB->request(['FROM' => KpiMotor::VALORES, 'WHERE' => ['plugin_indicadores_kpis_id' => $ids, 'period' => $ym, 'origin' => 'manual', 'NOT' => ['value' => null]]]), false);
        if (!$lancados) {
            return null; // ninguém lançou nada neste mês: sem base para medir o prazo (o mês é anterior ao uso da planilha)
        }
        foreach ($lancados as $r) {
            if ($r['date_mod'] && $r['date_mod'] <= $limite) {
                $ok++;
            }
        }
        return self::pct($ok, count($ids));
    }

    /** SLI do Zabbix para o período (plugin Sistemas Internos). Parâmetro: "slaid" ou "slaid:serviceid". */
    private static function zabbix(string $de, string $ate, string $param): ?float
    {
        if (!preg_match('/^(\d+)(?::(\d+))?$/', $param, $m)) {
            return null;
        }
        $params = ['slaid' => $m[1], 'period_from' => strtotime($de . ' 00:00:00'), 'period_to' => strtotime($ate . ' 23:59:59'), 'periods' => 1];
        if (!empty($m[2])) {
            $params['serviceids'] = [$m[2]];
        }
        $res = \GlpiPlugin\Sistemasinternos\Zabbix::call('sla.getsli', $params);
        $sli = $res['sli'][0][0]['sli'] ?? null;
        return is_numeric($sli) ? round((float) $sli, 2) : null;
    }
}
