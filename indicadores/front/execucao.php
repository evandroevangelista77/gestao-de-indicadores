<?php

/** Resultado de uma execução de auditoria: nota, evolução, nota de cada área e os achados. */

use GlpiPlugin\Indicadores\Execucao;
use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Periodo;
use GlpiPlugin\Indicadores\Score;

Session::checkRight(Menu::$rightname, READ);

$e = fn ($v) => Menu::e($v);
$x = new Execucao();
if (!$x->getFromDB((int) ($_GET['id'] ?? 0))) {
    Html::redirect(Menu::url('/front/auditoria.php'));
}
$f = $x->fields;
$r = json_decode((string) $f['resumo'], true) ?: ['areas' => [], 'achados' => []];
$delta = $f['score'] !== null && ($r['anterior'] ?? null) !== null ? round((float) $f['score'] - (float) $r['anterior'], 1) : null;

Menu::header('Auditoria');
echo "<div class='ind'>";
echo Menu::title($f['name'], 'Período avaliado: ' . Periodo::texto($f['period_from'], $f['period_to']) . ' · executada em ' . Html::convDateTime($f['date_run']) . ($f['origin'] === 'auto' ? ' (programada)' : ' (manual)'),
    Menu::button('Auditorias', Menu::url('/front/auditoria.php'), 'ti-clipboard-check', 'btn-outline-secondary')
    . Menu::button('Relatório executivo (PDF)', Menu::url('/front/relatorio.php?month=' . substr((string) $f['period_to'], 0, 7)), 'ti-file-type-pdf', 'btn-outline-primary')
    . ($f['tickets_id'] ? Menu::button('Chamado #' . (int) $f['tickets_id'], Ticket::getFormURLWithID((int) $f['tickets_id']), 'ti-ticket', 'btn-outline-primary') : ''));

echo "<div class='ind-hero ind-" . $e($f['status']) . "'><div><div class='ind-hero-label'>Nota da auditoria</div>"
    . "<div class='ind-hero-value'>" . ($f['score'] === null ? '—' : $e(number_format((float) $f['score'], 0, ',', '.'))) . "<small>/ 100</small></div>"
    . "<span class='ind-pill ind-" . $e($f['status']) . "'>" . $e(Score::label($f['status'])) . "</span>"
    . ($delta !== null ? " <span class='ind-delta " . ($delta >= 0 ? 'up' : 'down') . "'>" . ($delta >= 0 ? '▲ +' : '▼ −') . $e(number_format(abs($delta), 1, ',', '.')) . " vs. auditoria anterior</span>" : '')
    . "</div><div class='ind-hero-stats'><div><b>" . (int) $f['kpis_bad'] . "</b><span>críticos</span></div><div><b>" . (int) $f['kpis_warn'] . "</b><span>em atenção</span></div></div></div>";

echo "<div class='row g-3 mt-1'><div class='col-lg-4'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Nota por área</h3></div><div class='list-group list-group-flush'>";
foreach ($r['areas'] as $code => $a) {
    echo "<a class='list-group-item list-group-item-action ind-att' href='" . $e(Menu::url('/front/painel.php?area=' . $code . '&month=' . substr((string) $f['period_to'], 0, 7))) . "'>"
        . "<i class='ind-dot ind-" . $e($a['status']) . "'></i><span class='ind-att-main'><b>" . $e($a['nome']) . "</b><small>" . (int) $a['bad'] . " crítico(s) · " . (int) $a['warn'] . " em atenção</small></span>"
        . "<span class='ind-att-val'><b>" . ($a['score'] === null ? '—' : $e(number_format((float) $a['score'], 0, ',', '.'))) . "</b></span></a>";
}
if (!$r['areas']) {
    echo "<div class='list-group-item text-secondary text-center py-3'>Nenhuma área com dados.</div>";
}
echo "</div></div></div>";

echo "<div class='col-lg-8'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Achados</h3></div><div class='table-responsive'><table class='table table-hover mb-0'>"
    . "<thead><tr><th>Indicador</th><th>Área</th><th class='text-end'>Valor</th><th class='text-end'>Meta</th><th>Situação</th></tr></thead><tbody>";
if (!$r['achados']) {
    echo "<tr><td colspan='5' class='text-center text-secondary py-4'>Nenhum indicador em atenção ou crítico neste período. 🎉</td></tr>";
}
foreach ($r['achados'] as $a) {
    echo "<tr><td><b>" . $e($a['name']) . "</b>" . ($a['vivo'] ? "<div class='small text-secondary'>situação no momento da execução</div>" : '') . "</td><td>" . $e($a['area_nome']) . "</td>"
        . "<td class='text-end'><b>" . $e(Score::format($a['value'], $a['unit'])) . "</b></td><td class='text-end text-secondary'>" . $e(Score::format($a['meta'], $a['unit'])) . "</td>"
        . "<td><span class='ind-pill ind-" . $e($a['status']) . "'>" . $e(Score::label($a['status'])) . "</span></td></tr>";
}
echo "</tbody></table></div></div></div></div></div>";
Html::footer();
