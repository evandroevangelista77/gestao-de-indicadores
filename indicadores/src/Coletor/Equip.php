<?php

namespace GlpiPlugin\Indicadores\Coletor;

/** Controle de Equipamentos: entrega de equipamentos a novos colaboradores e devolução de desligados. */
class Equip extends Coletor
{
    public function codigo(): string { return 'equip'; }

    public function nome(): string { return 'Controle de Equipamentos'; }

    public function icone(): string { return 'ti ti-device-laptop'; }

    public function cor(): string { return '#0ea5e9'; }

    public function descricao(): string { return 'Entrega antes do início do colaborador e devoluções em dia'; }

    protected function tabelas(): array { return ['glpi_plugin_controleequipamentos_entregas', 'glpi_plugin_controleequipamentos_devolucoes']; }

    public function indicadores(): array
    {
        return [
            'equip.antes_inicio'  => $this->def('Entregas prontas antes do início', '%', 'up', 90, 75, 3, false, 'Entregas concluídas no período até a data de início do colaborador'),
            'equip.tempo'         => $this->def('Tempo médio de entrega', 'd', 'down', 5, 10, 2, false, 'Da abertura à conclusão, nas entregas concluídas no período'),
            'equip.entregas_atraso' => $this->def('Entregas abertas com início vencido', 'n', 'down', 0, 3, 3, true, 'Entregas ainda abertas cuja data de início do colaborador já passou'),
            'equip.devolucoes'    => $this->def('Devoluções abertas há mais de 15 dias', 'n', 'down', 0, 3, 2, true, 'Devoluções de equipamentos de desligados paradas'),
            'equip.entregas'      => $this->def('Entregas abertas no período', 'n', 'info', null, null, 0, false, 'Novas entregas de equipamentos'),
            'equip.devolvidos'    => $this->def('Devoluções concluídas', 'n', 'info', null, null, 0, false, 'Devoluções concluídas no período'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $e = 'glpi_plugin_controleequipamentos_entregas';
        $d = 'glpi_plugin_controleequipamentos_devolucoes';
        $out = [];

        $r = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN data_inicio IS NULL OR DATE(date_mod) <= data_inicio THEN 1 ELSE 0 END) AS ok,
            AVG(DATEDIFF(date_mod, date_creation)) AS dias FROM $e WHERE is_deleted = 0 AND status = 'concluida' AND date_mod BETWEEN '$i' AND '$f'");
        $out['equip.antes_inicio'] = $this->pct((float) ($r['ok'] ?? 0), (float) ($r['t'] ?? 0));
        $out['equip.tempo'] = isset($r['dias']) && $r['dias'] !== null ? round((float) $r['dias'], 1) : null;
        $out['equip.entregas'] = $this->numero("SELECT COUNT(*) FROM $e WHERE is_deleted = 0 AND date_creation BETWEEN '$i' AND '$f'");
        $out['equip.devolvidos'] = $this->numero("SELECT COUNT(*) FROM $d WHERE is_deleted = 0 AND status = 'concluida' AND date_mod BETWEEN '$i' AND '$f'");

        if ($vivo) {
            $out['equip.entregas_atraso'] = $this->numero("SELECT COUNT(*) FROM $e WHERE is_deleted = 0 AND status = 'aberta' AND data_inicio IS NOT NULL AND data_inicio < CURDATE()");
            $out['equip.devolucoes'] = $this->numero("SELECT COUNT(*) FROM $d WHERE is_deleted = 0 AND status = 'aberta' AND date_creation < NOW() - INTERVAL 15 DAY");
        }
        return $out;
    }
}
