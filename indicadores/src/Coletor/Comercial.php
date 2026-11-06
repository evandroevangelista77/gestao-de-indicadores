<?php

namespace GlpiPlugin\Indicadores\Coletor;

/** Comercial: funil de vendas do plugin CRM (oportunidades, conversão, ciclo de venda, pipeline e prazos de edital). */
class Comercial extends Coletor
{
    public function codigo(): string { return 'comercial'; }

    public function nome(): string { return 'Comercial'; }

    public function icone(): string { return 'ti ti-trending-up'; }

    public function cor(): string { return '#eab308'; }

    public function descricao(): string { return 'Conversão do funil, ciclo de venda, pipeline e oportunidades paradas'; }

    protected function tabelas(): array { return ['glpi_plugin_crm_conversas', 'glpi_plugin_crm_etapas']; }

    public function indicadores(): array
    {
        return [
            'comercial.conversao' => $this->def('Taxa de conversão', '%', 'up', 40, 25, 3, false, 'Oportunidades ganhas entre as encerradas (ganhas e perdidas) no período'),
            'comercial.ciclo'     => $this->def('Ciclo médio de venda', 'd', 'down', 30, 60, 2, false, 'Da criação ao ganho, nas oportunidades ganhas no período'),
            'comercial.paradas'   => $this->def('Oportunidades paradas há mais de 7 dias', 'n', 'down', 0, 5, 2, true, 'Oportunidades abertas sem movimentação ou mensagem há mais de 7 dias'),
            'comercial.prazos'    => $this->def('Prazos de edital vencidos', 'n', 'down', 0, 2, 2, true, 'Oportunidades abertas cujo prazo (edital ou proposta) já passou'),
            'comercial.ganhas'    => $this->def('Oportunidades ganhas', 'n', 'info', null, null, 0, false, 'Oportunidades ganhas no período'),
            'comercial.valor'     => $this->def('Valor ganho', 'R$', 'info', null, null, 0, false, 'Soma das oportunidades ganhas no período'),
            'comercial.ticket'    => $this->def('Ticket médio ganho', 'R$', 'info', null, null, 0, false, 'Valor ganho dividido pelas oportunidades ganhas'),
            'comercial.novas'     => $this->def('Novas oportunidades', 'n', 'info', null, null, 0, false, 'Oportunidades criadas no período'),
            'comercial.pipeline'  => $this->def('Pipeline em aberto', 'R$', 'info', null, null, 0, true, 'Valor das oportunidades abertas'),
            'comercial.previsao'  => $this->def('Previsão ponderada', 'R$', 'info', null, null, 0, true, 'Pipeline aberto ponderado pela probabilidade da etapa'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $c = 'glpi_plugin_crm_conversas';
        $out = [];

        $r = $this->linha("SELECT SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS w, SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) AS l,
            SUM(CASE WHEN status = 2 THEN value ELSE 0 END) AS v, AVG(CASE WHEN status = 2 THEN DATEDIFF(date_closed, date_creation) END) AS dias
            FROM $c WHERE status IN (2, 3) AND date_closed BETWEEN '$i' AND '$f'");
        $w = (float) ($r['w'] ?? 0);
        $l = (float) ($r['l'] ?? 0);
        $out['comercial.ganhas'] = $w;
        $out['comercial.valor'] = (float) ($r['v'] ?? 0);
        $out['comercial.conversao'] = $this->pct($w, $w + $l);
        $out['comercial.ciclo'] = isset($r['dias']) && $r['dias'] !== null ? round((float) $r['dias'], 1) : null;
        $out['comercial.ticket'] = $w > 0 ? round((float) $r['v'] / $w, 2) : null;
        $out['comercial.novas'] = $this->numero("SELECT COUNT(*) FROM $c WHERE date_creation BETWEEN '$i' AND '$f'");

        if ($vivo) {
            $r = $this->linha("SELECT SUM(c.value) AS pipe, SUM(c.value * COALESCE(e.probability, 0) / 100) AS prev
                FROM $c c LEFT JOIN glpi_plugin_crm_etapas e ON e.id = c.plugin_crm_etapas_id WHERE c.status = 1");
            $out['comercial.pipeline'] = (float) ($r['pipe'] ?? 0);
            $out['comercial.previsao'] = round((float) ($r['prev'] ?? 0), 2);
            $out['comercial.paradas'] = $this->numero("SELECT COUNT(*) FROM $c WHERE status = 1 AND COALESCE(last_message_at, date_creation) < NOW() - INTERVAL 7 DAY");
            $out['comercial.prazos'] = $this->numero("SELECT COUNT(*) FROM $c WHERE status = 1 AND deadline IS NOT NULL AND deadline < CURDATE()");
        }
        return $out;
    }
}
