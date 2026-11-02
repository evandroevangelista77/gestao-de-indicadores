<?php

namespace GlpiPlugin\Indicadores\Coletor;

/**
 * e-NPS: clima da equipe (campanhas do plugin CSAT / e-NPS). As respostas são anônimas; para
 * proteger o anonimato, o índice só aparece quando o período reúne pelo menos 5 respostas.
 */
class Enps extends Coletor
{
    private const MIN = 5;

    public function codigo(): string { return 'enps'; }

    public function nome(): string { return 'e-NPS'; }

    public function icone(): string { return 'ti ti-heart-handshake'; }

    public function cor(): string { return '#6366f1'; }

    public function descricao(): string { return 'Clima da equipe: promotores, neutros e detratores'; }

    protected function tabelas(): array { return ['glpi_plugin_satisfacao_enps_answers']; }

    public function indicadores(): array
    {
        return [
            'enps.score'       => $this->def('e-NPS', 'n', 'up', 50, 10, 4, false, 'Promotores (9 e 10) menos detratores (0 a 6); exige 5 respostas no período'),
            'enps.detratores'  => $this->def('Detratores', '%', 'down', 10, 25, 1, false, 'Parcela das respostas com nota de 0 a 6'),
            'enps.respostas'   => $this->def('Respostas recebidas', 'n', 'info', null, null, 0, false, 'Respostas anônimas recebidas no período'),
        ];
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        $r = $this->linha("SELECT COUNT(*) AS n, SUM(CASE WHEN score >= 9 THEN 1 ELSE 0 END) AS p, SUM(CASE WHEN score <= 6 THEN 1 ELSE 0 END) AS d
            FROM glpi_plugin_satisfacao_enps_answers WHERE date_answered BETWEEN '" . $this->day($de) . "' AND '" . $this->day($ate) . "'");
        $n = (float) ($r['n'] ?? 0);
        $ok = $n >= self::MIN;
        return [
            'enps.respostas'  => $n,
            'enps.score'      => $ok ? round(100 * ((float) $r['p'] - (float) $r['d']) / $n, 0) : null,
            'enps.detratores' => $ok ? round(100 * (float) $r['d'] / $n, 1) : null,
        ];
    }
}
