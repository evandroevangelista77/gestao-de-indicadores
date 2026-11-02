<?php

/** Relatório executivo em PDF (todas as áreas, desvios e planos de ação) do mês escolhido. */

use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Periodo;
use GlpiPlugin\Indicadores\Relatorio;

Session::checkRight(Menu::$rightname, READ);

$month = Periodo::mesValido($_GET['month'] ?? null) && $_GET['month'] <= date('Y-m') ? $_GET['month'] : Relatorio::mesPadrao();

@set_time_limit(180);
$pdf = Relatorio::build($month);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
// ?view=1 abre o PDF no navegador; sem isso, baixa o arquivo
header('Content-Disposition: ' . (isset($_GET['view']) ? 'inline' : 'attachment') . '; filename="Relatorio-executivo-qualidade-' . $month . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
exit;
