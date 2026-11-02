<?php

/** Conteúdo do balão de uma célula do painel (JSON): indicadores de uma área e mês, ou o índice geral ("all"). */

use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Motor;
use GlpiPlugin\Indicadores\Painel;
use GlpiPlugin\Indicadores\Periodo;

Session::checkRight(Menu::$rightname, READ);

$area = (string) ($_GET['area'] ?? 'all');
$month = (string) ($_GET['month'] ?? date('Y-m'));

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=20');

if (!Periodo::mesValido($month) || $month > date('Y-m') || ($area !== 'all' && !isset(Motor::disponiveis()[$area]))) {
    http_response_code(400);
    echo json_encode(['tipo' => 'vazio', 'titulo' => 'Sem dados']);
    exit;
}
echo json_encode(Painel::celula($area, $month), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
exit;
