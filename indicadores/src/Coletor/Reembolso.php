<?php

namespace GlpiPlugin\Indicadores\Coletor;

/** Reembolso: despesas de técnicos em atendimento externo, aprovação e pagamento dos lotes. */
class Reembolso extends Coletor
{
    public function codigo(): string { return 'reembolso'; }

    public function nome(): string { return 'Reembolso'; }

    public function icone(): string { return 'ti ti-receipt-2'; }

    public function cor(): string { return '#14b8a6'; }

    public function descricao(): string { return 'Velocidade de aprovação e pagamento e qualidade das solicitações'; }

    protected function tabelas(): array { return ['glpi_plugin_reembolso_reembolsos', 'glpi_plugin_reembolso_lotes']; }

    public function indicadores(): array
    {
        return [
            'reembolso.aprovacao'  => $this->def('Tempo médio de aprovação', 'd', 'down', 2, 5, 3, false, 'Da solicitação à aprovação, nas solicitações aprovadas no período'),
            'reembolso.pagamento'  => $this->def('Tempo médio até o pagamento', 'd', 'down', 5, 10, 3, false, 'Da aprovação ao pagamento, nas solicitações pagas no período'),
            'reembolso.recusa'     => $this->def('Solicitações recusadas', '%', 'down', 5, 15, 2, false, 'Parcela das solicitações enviadas no período que foi recusada'),
            'reembolso.devolvidos' => $this->def('Lotes devolvidos ao técnico', '%', 'down', 5, 20, 2, false, 'Lotes criados no período que voltaram para correção'),
            'reembolso.parados'    => $this->def('Solicitações paradas há mais de 5 dias', 'n', 'down', 0, 5, 2, true, 'Solicitações aguardando aprovação há mais de 5 dias'),
            'reembolso.volume'     => $this->def('Solicitações enviadas', 'n', 'info', null, null, 0, false, 'Solicitações enviadas no período'),
            'reembolso.valor'      => $this->def('Valor pago', 'R$', 'info', null, null, 0, false, 'Reembolsos pagos no período'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $r = 'glpi_plugin_reembolso_reembolsos';
        $out = [];

        $out['reembolso.aprovacao'] = $this->media($this->numero("SELECT AVG(TIMESTAMPDIFF(HOUR, date_submit, date_approval)) / 24 FROM $r
            WHERE is_deleted = 0 AND date_approval BETWEEN '$i' AND '$f' AND date_submit IS NOT NULL"));
        $out['reembolso.pagamento'] = $this->media($this->numero("SELECT AVG(DATEDIFF(date_payment, DATE(date_approval))) FROM $r
            WHERE is_deleted = 0 AND date_payment BETWEEN '" . substr($i, 0, 10) . "' AND '" . substr($f, 0, 10) . "' AND date_approval IS NOT NULL"));
        $s = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) AS rec FROM $r
            WHERE is_deleted = 0 AND date_submit BETWEEN '$i' AND '$f'");
        $out['reembolso.volume'] = (float) ($s['t'] ?? 0);
        $out['reembolso.recusa'] = $this->pct((float) ($s['rec'] ?? 0), (float) ($s['t'] ?? 0));
        $out['reembolso.valor'] = (float) ($this->numero("SELECT SUM(total) FROM $r WHERE is_deleted = 0 AND status = 5
            AND date_payment BETWEEN '" . substr($i, 0, 10) . "' AND '" . substr($f, 0, 10) . "'") ?? 0);
        $l = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN status = 7 THEN 1 ELSE 0 END) AS dev FROM glpi_plugin_reembolso_lotes
            WHERE is_deleted = 0 AND date_creation BETWEEN '$i' AND '$f'");
        $out['reembolso.devolvidos'] = $this->pct((float) ($l['dev'] ?? 0), (float) ($l['t'] ?? 0));

        if ($vivo) {
            $out['reembolso.parados'] = $this->numero("SELECT COUNT(*) FROM $r WHERE is_deleted = 0 AND status = 2 AND date_submit < NOW() - INTERVAL 5 DAY");
        }
        return $out;
    }
}
