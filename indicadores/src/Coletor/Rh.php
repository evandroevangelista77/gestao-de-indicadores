<?php

namespace GlpiPlugin\Indicadores\Coletor;

/** RH: vagas, contratações, admissões e férias. */
class Rh extends Coletor
{
    public function codigo(): string { return 'rh'; }

    public function nome(): string { return 'RH'; }

    public function icone(): string { return 'ti ti-users-group'; }

    public function cor(): string { return '#ec4899'; }

    public function descricao(): string { return 'Tempo de contratação, vagas e férias dentro do prazo'; }

    protected function tabelas(): array { return ['glpi_plugin_rh_vagas', 'glpi_plugin_rh_candidaturas']; }

    public function indicadores(): array
    {
        return [
            'rh.preenchimento' => $this->def('Tempo médio de preenchimento de vagas', 'd', 'down', 45, 75, 3, false, 'Da abertura ao preenchimento, nas vagas preenchidas no período'),
            'rh.aprovacao'     => $this->def('Tempo médio de aprovação de vagas', 'd', 'down', 3, 7, 2, false, 'Do envio da vaga à abertura, nas vagas abertas no período'),
            'rh.vagas_atraso'  => $this->def('Vagas abertas fora do prazo', 'n', 'down', 0, 3, 3, true, 'Vagas abertas cujo prazo de preenchimento já passou'),
            'rh.ferias'        => $this->def('Férias aguardando aprovação há mais de 5 dias', 'n', 'down', 0, 3, 2, true, 'Solicitações de férias paradas no gestor'),
            'rh.admissoes'     => $this->def('Admissões com início vencido', 'n', 'down', 0, 2, 2, true, 'Admissões em andamento cuja data de início já passou'),
            'rh.contratacoes'  => $this->def('Contratações', 'n', 'info', null, null, 0, false, 'Candidatos contratados no período'),
            'rh.abertas'       => $this->def('Vagas abertas', 'n', 'info', null, null, 0, true, 'Vagas em processo de preenchimento'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        global $DB;
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $v = 'glpi_plugin_rh_vagas';
        $out = [];

        $out['rh.preenchimento'] = $this->media($this->numero("SELECT AVG(DATEDIFF(date_close, date_open)) FROM $v
            WHERE is_deleted = 0 AND status = 5 AND date_close BETWEEN '$i' AND '$f' AND date_open IS NOT NULL"));
        $out['rh.aprovacao'] = $this->media($this->numero("SELECT AVG(TIMESTAMPDIFF(HOUR, date_submit, date_open)) / 24 FROM $v
            WHERE is_deleted = 0 AND date_open BETWEEN '$i' AND '$f' AND date_submit IS NOT NULL"));
        $out['rh.contratacoes'] = $this->numero("SELECT COUNT(*) FROM glpi_plugin_rh_candidaturas WHERE status = 'hired' AND date_hired BETWEEN '$i' AND '$f'");

        if ($vivo) {
            $out['rh.abertas'] = $this->numero("SELECT COUNT(*) FROM $v WHERE is_deleted = 0 AND status = 4");
            $out['rh.vagas_atraso'] = $this->numero("SELECT COUNT(*) FROM $v WHERE is_deleted = 0 AND status = 4 AND deadline IS NOT NULL AND deadline < CURDATE()");
            $out['rh.ferias'] = $DB->tableExists('glpi_plugin_rh_ferias')
                ? $this->numero("SELECT COUNT(*) FROM glpi_plugin_rh_ferias WHERE is_deleted = 0 AND status = 2 AND date_creation < NOW() - INTERVAL 5 DAY")
                : null;
            $out['rh.admissoes'] = $DB->tableExists('glpi_plugin_rh_admissaos')
                ? $this->numero("SELECT COUNT(*) FROM glpi_plugin_rh_admissaos WHERE is_deleted = 0 AND status = 1 AND start_date IS NOT NULL AND start_date < CURDATE()")
                : null;
        }
        return $out;
    }
}
