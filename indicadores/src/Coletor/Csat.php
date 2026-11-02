<?php

namespace GlpiPlugin\Indicadores\Coletor;

/** CSAT: satisfação dos solicitantes (pesquisas do plugin CSAT / e-NPS enviadas por chamado, projeto, evento e ciclo). */
class Csat extends Coletor
{
    public function codigo(): string { return 'csat'; }

    public function nome(): string { return 'CSAT'; }

    public function icone(): string { return 'ti ti-mood-smile'; }

    public function cor(): string { return '#22c55e'; }

    public function descricao(): string { return 'Nota média, avaliações positivas, taxa de resposta e NPS de atendimento'; }

    protected function tabelas(): array { return ['glpi_plugin_satisfacao_surveys', 'glpi_plugin_satisfacao_answers']; }

    public function indicadores(): array
    {
        return [
            'csat.nota'      => $this->def('Nota média', 'nota', 'up', 4.5, 4.0, 3, false, 'Média das avaliações por estrelas (1 a 5) respondidas no período'),
            'csat.positivas' => $this->def('Avaliações positivas', '%', 'up', 85, 70, 3, false, 'Avaliações por estrelas com nota 4 ou 5'),
            'csat.resposta'  => $this->def('Taxa de resposta', '%', 'up', 30, 15, 2, false, 'Pesquisas respondidas entre as enviadas no período'),
            'csat.nps'       => $this->def('NPS do atendimento', 'n', 'up', 50, 20, 2, false, 'Promotores (9 e 10) menos detratores (0 a 6), na pergunta de recomendação'),
            'csat.enviadas'  => $this->def('Pesquisas enviadas', 'n', 'info', null, null, 0, false, 'Pesquisas enviadas no período'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $s = 'glpi_plugin_satisfacao_surveys';
        $a = 'glpi_plugin_satisfacao_answers';
        $out = [];

        $r = $this->linha("SELECT AVG(a.score) AS nota, COUNT(*) AS n, SUM(CASE WHEN a.score >= 4 THEN 1 ELSE 0 END) AS pos
            FROM $a a JOIN $s s ON s.id = a.plugin_satisfacao_surveys_id
            WHERE a.type = 'rating' AND a.score IS NOT NULL AND s.status = 1 AND s.date_answered BETWEEN '$i' AND '$f'");
        $out['csat.nota'] = isset($r['nota']) && $r['nota'] !== null ? round((float) $r['nota'], 2) : null;
        $out['csat.positivas'] = $this->pct((float) ($r['pos'] ?? 0), (float) ($r['n'] ?? 0));

        $r = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS resp FROM $s WHERE date_sent BETWEEN '$i' AND '$f'");
        $out['csat.enviadas'] = (float) ($r['t'] ?? 0);
        $out['csat.resposta'] = $this->pct((float) ($r['resp'] ?? 0), (float) ($r['t'] ?? 0));

        $r = $this->linha("SELECT COUNT(*) AS n, SUM(CASE WHEN a.score >= 9 THEN 1 ELSE 0 END) AS p, SUM(CASE WHEN a.score <= 6 THEN 1 ELSE 0 END) AS d
            FROM $a a JOIN $s s ON s.id = a.plugin_satisfacao_surveys_id
            WHERE a.type = 'nps' AND a.score IS NOT NULL AND s.status = 1 AND s.date_answered BETWEEN '$i' AND '$f'");
        $out['csat.nps'] = !empty($r['n']) ? round(100 * ((float) $r['p'] - (float) $r['d']) / (float) $r['n'], 0) : null;
        return $out;
    }
}
