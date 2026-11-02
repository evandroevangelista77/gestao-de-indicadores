<?php

namespace GlpiPlugin\Indicadores\Coletor;

/** Gestão de Eventos: triagem, etapas com SLO, devoluções e datas críticas. */
class Eventos extends Coletor
{
    public function codigo(): string { return 'eventos'; }

    public function nome(): string { return 'Gestão de Eventos'; }

    public function icone(): string { return 'ti ti-calendar-event'; }

    public function cor(): string { return '#f97316'; }

    public function descricao(): string { return 'Triagem, etapas dentro do SLO, devoluções e datas críticas'; }

    protected function tabelas(): array { return ['glpi_plugin_gestaoeventos_eventos', 'glpi_plugin_gestaoeventos_fases']; }

    public function indicadores(): array
    {
        return [
            'eventos.slo'       => $this->def('Etapas concluídas dentro do SLO', '%', 'up', 85, 70, 3, false, 'Etapas concluídas no período cujo tempo ficou dentro do SLO da etapa'),
            'eventos.triagem'   => $this->def('Tempo médio de triagem', 'd', 'down', 2, 5, 2, false, 'Do pedido à triagem, nos eventos triados no período'),
            'eventos.devolucoes' => $this->def('Devoluções por evento', 'n', 'down', 0.5, 2, 2, false, 'Quantas vezes, em média, uma etapa voltou à anterior nos eventos concluídos'),
            'eventos.fora'      => $this->def('Eventos com etapa fora do SLO', 'n', 'down', 0, 3, 3, true, 'Eventos em andamento cuja etapa atual já estourou o SLO'),
            'eventos.criticos'  => $this->def('Eventos com data crítica vencida', 'n', 'down', 0, 2, 2, true, 'Eventos em andamento cuja data crítica já passou'),
            'eventos.concluidos' => $this->def('Eventos concluídos', 'n', 'info', null, null, 0, false, 'Eventos concluídos no período'),
            'eventos.andamento' => $this->def('Eventos em andamento', 'n', 'info', null, null, 0, true, 'Eventos que ainda não foram concluídos nem cancelados'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $e = 'glpi_plugin_gestaoeventos_eventos';
        $out = [];

        $out['eventos.slo'] = $this->sloDasFases('glpi_plugin_gestaoeventos_fases', $de, $ate);
        $out['eventos.triagem'] = $this->media($this->numero("SELECT AVG(TIMESTAMPDIFF(HOUR, date_request, date_triage)) / 24 FROM $e
            WHERE is_deleted = 0 AND is_draft = 0 AND date_triage BETWEEN '$i' AND '$f' AND date_request IS NOT NULL"));
        $r = $this->linha("SELECT COUNT(*) AS t, AVG(returns) AS dev FROM $e WHERE is_deleted = 0 AND status = 2 AND date_end BETWEEN '$i' AND '$f'");
        $out['eventos.concluidos'] = (float) ($r['t'] ?? 0);
        $out['eventos.devolucoes'] = isset($r['dev']) && $r['dev'] !== null ? round((float) $r['dev'], 2) : null;

        if ($vivo) {
            $out['eventos.andamento'] = $this->numero("SELECT COUNT(*) FROM $e WHERE is_deleted = 0 AND is_draft = 0 AND status = 1");
            $out['eventos.criticos'] = $this->numero("SELECT COUNT(*) FROM $e WHERE is_deleted = 0 AND is_draft = 0 AND status = 1 AND date_critical IS NOT NULL AND date_critical < CURDATE()");
            $fase = 'glpi_plugin_gestaoeventos_fases';
            $out['eventos.fora'] = $this->numero("SELECT COUNT(DISTINCT f.plugin_gestaoeventos_eventos_id) FROM $fase f
                JOIN $e e ON e.id = f.plugin_gestaoeventos_eventos_id AND e.is_deleted = 0 AND e.status = 1
                WHERE f.status = 'active' AND f.slo_days > 0
                AND f.seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(f.date_clock, f.date_start), NOW())) > f.slo_days * 86400");
        }
        return $out;
    }
}
