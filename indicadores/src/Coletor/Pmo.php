<?php

namespace GlpiPlugin\Indicadores\Coletor;

/** PMO: saúde dos projetos e das tarefas nativas do GLPI (e os riscos do plugin PMO, quando instalado). */
class Pmo extends Coletor
{
    public function codigo(): string { return 'pmo'; }

    public function nome(): string { return 'PMO · Projetos'; }

    public function icone(): string { return 'ti ti-timeline'; }

    public function cor(): string { return '#3b82f6'; }

    public function descricao(): string { return 'Saúde dos projetos, prazo das tarefas e riscos críticos'; }

    protected function tabelas(): array { return ['glpi_projects', 'glpi_projecttasks']; }

    public function indicadores(): array
    {
        return [
            'pmo.prazo'      => $this->def('Tarefas entregues no prazo', '%', 'up', 85, 70, 3, false, 'Tarefas com fim planejado no período, já vencido, que foram concluídas até a data planejada'),
            'pmo.saude'      => $this->def('Projetos saudáveis', '%', 'up', 80, 60, 3, true, 'Projetos ativos sem tarefa atrasada e dentro do prazo planejado'),
            'pmo.atrasadas'  => $this->def('Tarefas atrasadas em aberto', 'n', 'down', 0, 10, 2, true, 'Tarefas não concluídas com o fim planejado vencido'),
            'pmo.riscos'     => $this->def('Riscos críticos abertos', 'n', 'down', 0, 3, 2, true, 'Riscos abertos com probabilidade × impacto igual ou maior que 15 (plugin PMO)'),
            'pmo.concluidas' => $this->def('Tarefas concluídas', 'n', 'info', null, null, 0, false, 'Tarefas concluídas no período'),
            'pmo.projetos'   => $this->def('Projetos ativos', 'n', 'info', null, null, 0, true, 'Projetos que ainda não foram finalizados'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        global $DB;
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $tasks = "FROM glpi_projecttasks t JOIN glpi_projects p ON p.id = t.projects_id AND p.is_deleted = 0 AND p.is_template = 0
                  WHERE t.is_deleted = 0 AND t.is_template = 0";
        $out = [];

        $r = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN t.percent_done >= 100 AND (t.real_end_date IS NULL OR t.real_end_date <= t.plan_end_date) THEN 1 ELSE 0 END) AS ok
            $tasks AND t.plan_end_date BETWEEN '$i' AND '$f' AND t.plan_end_date <= NOW()");
        $out['pmo.prazo'] = $this->pct((float) ($r['ok'] ?? 0), (float) ($r['t'] ?? 0));
        $out['pmo.concluidas'] = $this->numero("SELECT COUNT(*) $tasks AND t.percent_done >= 100 AND t.real_end_date BETWEEN '$i' AND '$f'");

        if ($vivo) {
            $out['pmo.atrasadas'] = $this->numero("SELECT COUNT(*) $tasks AND t.percent_done < 100 AND t.plan_end_date < NOW()");
            $r = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN (p.plan_end_date IS NULL OR p.plan_end_date >= NOW())
                    AND NOT EXISTS (SELECT 1 FROM glpi_projecttasks x WHERE x.projects_id = p.id AND x.is_deleted = 0 AND x.is_template = 0 AND x.percent_done < 100 AND x.plan_end_date < NOW())
                    THEN 1 ELSE 0 END) AS ok
                FROM glpi_projects p LEFT JOIN glpi_projectstates s ON s.id = p.projectstates_id
                WHERE p.is_deleted = 0 AND p.is_template = 0 AND (s.id IS NULL OR s.is_finished = 0)");
            $out['pmo.projetos'] = (float) ($r['t'] ?? 0);
            $out['pmo.saude'] = $this->pct((float) ($r['ok'] ?? 0), (float) ($r['t'] ?? 0));
            $out['pmo.riscos'] = $DB->tableExists('glpi_plugin_pmo_risks')
                ? $this->numero("SELECT COUNT(*) FROM glpi_plugin_pmo_risks WHERE status = 'open' AND probability * impact >= 15")
                : null;
        }
        return $out;
    }
}
