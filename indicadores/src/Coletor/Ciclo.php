<?php

namespace GlpiPlugin\Indicadores\Coletor;

/** Ciclo Financeiro: da venda ao recebimento, fase a fase, com SLO por fase. */
class Ciclo extends Coletor
{
    public function codigo(): string { return 'ciclo'; }

    public function nome(): string { return 'Ciclo Financeiro'; }

    public function icone(): string { return 'ti ti-coin'; }

    public function cor(): string { return '#a855f7'; }

    public function descricao(): string { return 'Duração do ciclo, fases dentro do SLO e devoluções entre áreas'; }

    protected function tabelas(): array { return ['glpi_plugin_ciclo_ciclos', 'glpi_plugin_ciclo_fases']; }

    public function indicadores(): array
    {
        return [
            'ciclo.slo'        => $this->def('Fases concluídas dentro do SLO', '%', 'up', 85, 70, 3, false, 'Fases concluídas no período cujo tempo ficou dentro do SLO da fase'),
            'ciclo.duracao'    => $this->def('Duração média do ciclo', 'd', 'down', 30, 60, 2, false, 'Do fechamento da venda à conclusão, nos ciclos concluídos no período'),
            'ciclo.devolucoes' => $this->def('Devoluções por ciclo', 'n', 'down', 0.5, 2, 2, false, 'Quantas vezes, em média, uma fase foi devolvida à anterior nos ciclos concluídos'),
            'ciclo.fora'       => $this->def('Ciclos com fase fora do SLO', 'n', 'down', 0, 3, 3, true, 'Ciclos em andamento cuja fase atual já estourou o SLO'),
            'ciclo.concluidos' => $this->def('Ciclos concluídos', 'n', 'info', null, null, 0, false, 'Ciclos concluídos no período'),
            'ciclo.valor'      => $this->def('Valor dos ciclos concluídos', 'R$', 'info', null, null, 0, false, 'Soma das vendas concluídas no período'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $c = 'glpi_plugin_ciclo_ciclos';
        $out = [];

        $out['ciclo.slo'] = $this->sloDasFases('glpi_plugin_ciclo_fases', $de, $ate);
        $r = $this->linha("SELECT COUNT(*) AS t, AVG(DATEDIFF(date_end, date_closing)) AS dur, AVG(returns) AS dev, SUM(amount) AS valor FROM $c
            WHERE is_deleted = 0 AND status = 2 AND date_end BETWEEN '$i' AND '$f'");
        $out['ciclo.concluidos'] = (float) ($r['t'] ?? 0);
        $out['ciclo.duracao'] = isset($r['dur']) && $r['dur'] !== null ? round((float) $r['dur'], 1) : null;
        $out['ciclo.devolucoes'] = isset($r['dev']) && $r['dev'] !== null ? round((float) $r['dev'], 2) : null;
        $out['ciclo.valor'] = (float) ($r['valor'] ?? 0);

        if ($vivo) {
            $out['ciclo.fora'] = $this->numero("SELECT COUNT(DISTINCT f.plugin_ciclo_ciclos_id) FROM glpi_plugin_ciclo_fases f
                JOIN $c c ON c.id = f.plugin_ciclo_ciclos_id AND c.is_deleted = 0 AND c.status = 1
                WHERE f.status = 'active' AND f.slo_days > 0
                AND f.seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, f.date_start, NOW())) > f.slo_days * 86400");
        }
        return $out;
    }
}
