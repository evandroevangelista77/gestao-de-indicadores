<?php

namespace GlpiPlugin\Indicadores\Coletor;

use Config;
use Throwable;

/**
 * Sistemas Internos: chamados da entidade configurada no plugin Sistemas Internos (e das filhas)
 * e as ações de automação feitas nos hosts do Zabbix pelo GLPI.
 */
class Si extends Coletor
{
    public function codigo(): string { return 'si'; }

    public function nome(): string { return 'Sistemas Internos'; }

    public function icone(): string { return 'ti ti-building-community'; }

    public function cor(): string { return '#3769B2'; }

    public function descricao(): string { return 'Atendimento da entidade de Sistemas Internos e automações no Zabbix'; }

    protected function tabelas(): array { return ['glpi_tickets', 'glpi_plugin_sistemasinternos_logs']; }

    public function indicadores(): array
    {
        return [
            'si.sla'       => $this->def('Chamados solucionados no prazo', '%', 'up', 90, 80, 3, false, 'Chamados solucionados no período dentro do prazo de solução'),
            'si.tempo'     => $this->def('Tempo médio de solução', 'h', 'down', 24, 72, 2, false, 'Da abertura à solução, nos chamados solucionados no período'),
            'si.vencidos'  => $this->def('Chamados em aberto vencidos', 'n', 'down', 0, 5, 3, true, 'Chamados em aberto com o prazo de solução vencido'),
            'si.backlog'   => $this->def('Chamados em aberto', 'n', 'down', 15, 40, 1, true, 'Fila de chamados que ainda não foram solucionados'),
            'si.falhas'    => $this->def('Falhas nas ações de automação', '%', 'down', 2, 10, 1, false, 'Ações feitas nos hosts do Zabbix pelo GLPI que terminaram com erro'),
            'si.abertos'   => $this->def('Chamados abertos', 'n', 'info', null, null, 0, false, 'Chamados abertos no período'),
            'si.acoes'     => $this->def('Ações no Zabbix pelo GLPI', 'n', 'info', null, null, 0, false, 'Hosts incluídos, alterados ou removidos pelo GLPI'),
        ];
    }

    /** Entidade configurada no plugin Sistemas Internos e as filhas. */
    private function entidades(): array
    {
        $cfg = Config::getConfigurationValues('plugin:sistemasinternos', ['entities_id']);
        $id = (int) ($cfg['entities_id'] ?? 0);
        try {
            $ids = array_map('intval', array_values(getSonsOf('glpi_entities', $id)));
        } catch (Throwable) {
            $ids = [$id];
        }
        return $ids ?: [$id];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $ents = implode(',', $this->entidades());
        $t = "FROM glpi_tickets WHERE is_deleted = 0 AND entities_id IN ($ents)";
        $out = [];

        $r = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN solvedate <= time_to_resolve THEN 1 ELSE 0 END) AS ok
            $t AND solvedate BETWEEN '$i' AND '$f' AND time_to_resolve IS NOT NULL");
        $out['si.sla'] = $this->pct((float) ($r['ok'] ?? 0), (float) ($r['t'] ?? 0));
        $out['si.tempo'] = $this->media($this->numero("SELECT AVG(TIMESTAMPDIFF(MINUTE, date, solvedate)) / 60 $t AND solvedate BETWEEN '$i' AND '$f'"));
        $out['si.abertos'] = $this->numero("SELECT COUNT(*) $t AND date BETWEEN '$i' AND '$f'");

        $r = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN result <> 'ok' THEN 1 ELSE 0 END) AS falhas
            FROM glpi_plugin_sistemasinternos_logs WHERE date_creation BETWEEN '$i' AND '$f'");
        $out['si.acoes'] = (float) ($r['t'] ?? 0);
        $out['si.falhas'] = $this->pct((float) ($r['falhas'] ?? 0), (float) ($r['t'] ?? 0));

        if ($vivo) {
            $out['si.backlog'] = $this->numero("SELECT COUNT(*) $t AND status IN (1, 2, 3, 4)");
            $out['si.vencidos'] = $this->numero("SELECT COUNT(*) $t AND status IN (1, 2, 3, 4) AND time_to_resolve IS NOT NULL AND time_to_resolve < NOW()");
        }
        return $out;
    }
}
