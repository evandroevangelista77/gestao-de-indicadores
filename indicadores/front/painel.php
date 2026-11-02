<?php

/**
 * Painel executivo: nota de cada área mês a mês, com o índice geral. Ao passar o mouse numa célula abre
 * um balão com os indicadores do mês e a tendência; o clique abre o detalhe da área.
 */

use GlpiPlugin\Indicadores\Auditoria;
use GlpiPlugin\Indicadores\Execucao;
use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Motor;
use GlpiPlugin\Indicadores\Painel;
use GlpiPlugin\Indicadores\Periodo;
use GlpiPlugin\Indicadores\Score;

Session::checkRight(Menu::$rightname, READ);

global $DB;
$e = fn ($v) => Menu::e($v);
$hoje = date('Y-m');

if (isset($_POST['recalcular']) && Session::haveRight(Menu::$rightname, UPDATE)) {
    Motor::recalcular(12);
    Session::addMessageAfterRedirect('Indicadores recalculados (últimos 12 meses).');
    Html::redirect(Menu::url('/front/painel.php'));
}

// ---------------------------------------------------------------- detalhe de uma área
$area = (string) ($_GET['area'] ?? '');
if ($area !== '' && isset(Motor::disponiveis()[$area])) {
    $month = Periodo::mesValido($_GET['month'] ?? null) && $_GET['month'] <= $hoje ? $_GET['month'] : $hoje;
    $d = Painel::celula($area, $month);
    $back = Menu::url('/front/painel.php?ano=' . substr($month, 0, 4));
    Menu::header($d['titulo']);
    echo "<div class='ind'>";
    echo Menu::title($d['titulo'], Motor::coletores()[$area]->descricao(),
        "<form method='get' class='d-flex gap-2 align-items-end m-0' action='" . $e(Menu::url('/front/painel.php')) . "'><input type='hidden' name='area' value='" . $e($area) . "'>"
        . "<div><label class='form-label small mb-0'>Mês</label><input type='month' class='form-control form-control-sm' name='month' max='" . $e($hoje) . "' value='" . $e($month) . "' onchange='this.form.submit()'></div></form>"
        . Menu::button('Painel executivo', $back, 'ti-layout-dashboard', 'btn-outline-secondary'));

    $delta = $d['score'] !== null && $d['anterior'] !== null ? round($d['score'] - $d['anterior'], 1) : null;
    echo "<div class='ind-hero ind-" . $e($d['status']) . "'>"
        . "<div class='ind-hero-ico' style='--c:" . $e($d['cor']) . "'><i class='" . $e($d['icone']) . "'></i></div>"
        . "<div><div class='ind-hero-label'>Nota da área em " . $e($d['mes']) . "</div>"
        . "<div class='ind-hero-value'>" . ($d['score'] === null ? '—' : $e(number_format($d['score'], 0, ',', '.'))) . "<small>/ 100</small></div>"
        . "<span class='ind-pill ind-" . $e($d['status']) . "'>" . $e(Score::label($d['status'])) . "</span>"
        . ($delta !== null ? " <span class='ind-delta " . ($delta >= 0 ? 'up' : 'down') . "'>" . ($delta >= 0 ? '▲ +' : '▼ −') . $e(number_format(abs($delta), 1, ',', '.')) . " vs. mês anterior</span>" : '')
        . "</div><div class='ind-hero-spark' data-serie='" . $e(json_encode($d['serie'])) . "' data-meses='" . $e(json_encode($d['meses'])) . "'></div></div>";

    echo "<div class='ind-kpis' id='ind-kpis'></div>";
    echo "<script type='application/json' id='ind-detail'>" . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) . "</script>";

    // auditorias desta área
    $rows = array_filter(Execucao::recentes(null, 60), fn ($x) => $x['area'] === 'all' || $x['area'] === $area);
    echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>Auditorias que avaliaram esta área</h3></div><div class='table-responsive'><table class='table table-hover mb-0'>"
        . "<thead><tr><th>Auditoria</th><th>Período avaliado</th><th>Executada em</th><th class='text-end'>Nota</th><th></th></tr></thead><tbody>";
    if (!$rows) {
        echo "<tr><td colspan='5' class='text-center text-secondary py-3'>Nenhuma auditoria executada ainda.</td></tr>";
    }
    foreach (array_slice($rows, 0, 8) as $x) {
        $nota = $x['area'] === 'all' ? (json_decode((string) $x['resumo'], true)['areas'][$area]['score'] ?? null) : $x['score'];
        echo "<tr><td>" . $e($x['name']) . "</td><td>" . $e(Periodo::texto($x['period_from'], $x['period_to'])) . "</td><td>" . $e(Html::convDateTime($x['date_run'])) . "</td>"
            . "<td class='text-end'><b>" . ($nota === null ? '—' : $e(number_format((float) $nota, 0, ',', '.'))) . "</b></td>"
            . "<td class='text-end'><a class='btn btn-sm btn-outline-primary' href='" . $e(Menu::url('/front/execucao.php?id=' . (int) $x['id'])) . "'>Ver</a></td></tr>";
    }
    echo "</tbody></table></div></div></div>";
    echo Menu::script('painel.js');
    Html::footer();
    return;
}

// ---------------------------------------------------------------- painel executivo
$ano = (int) ($_GET['ano'] ?? date('Y'));
$ano = max((int) date('Y') - 4, min((int) date('Y'), $ano ?: (int) date('Y')));
$g = Painel::grade($ano);
$atual = $g['atual'];
$geral = $atual ? $g['geral'][$atual] : null;
$prevYm = $atual ? (new DateTimeImmutable($atual . '-01'))->modify('-1 month')->format('Y-m') : null;
$prevScore = $prevYm && isset($g['geral'][$prevYm]) ? $g['geral'][$prevYm]['score'] : null;
$delta = $geral && $geral['score'] !== null && $prevScore !== null ? round($geral['score'] - $prevScore, 1) : null;

$years = '';
for ($y = (int) date('Y'); $y >= (int) date('Y') - 4; $y--) {
    $years .= "<option value='$y'" . ($y === $ano ? ' selected' : '') . ">$y</option>";
}
$actions = "<form method='get' class='d-flex gap-2 align-items-end m-0' action='" . $e(Menu::url('/front/painel.php')) . "'>"
    . "<div><label class='form-label small mb-0'>Ano</label><select class='form-select form-select-sm' name='ano' onchange='this.form.submit()'>$years</select></div></form>";
if (Session::haveRight(Menu::$rightname, UPDATE)) {
    $actions .= "<form method='post' class='m-0'>" . Menu::csrf() . "<button class='btn btn-outline-secondary' name='recalcular' value='1' title='Recalcula os últimos 12 meses a partir dos dados atuais'><i class='ti ti-refresh me-1'></i>Recalcular</button></form>";
}
$actions .= "<form method='get' class='d-flex gap-1 align-items-end m-0' action='" . $e(Menu::url('/front/relatorio.php')) . "'>"
    . "<div><label class='form-label small mb-0'>Relatório do mês</label><input type='month' class='form-control form-control-sm' name='month' max='" . $e($hoje) . "' value='" . $e($atual ?: $hoje) . "' required></div>"
    . "<button class='btn btn-outline-primary' type='submit' title='Baixar o relatório executivo em PDF: todas as áreas, desvios e planos de ação'><i class='ti ti-file-type-pdf me-1'></i>Relatório executivo (PDF)</button></form>";
$actions .= Menu::button('Auditorias', Menu::url('/front/auditoria.php'), 'ti-clipboard-check', 'btn-primary');

Menu::header('Painel executivo');
echo "<div class='ind'>";
echo Menu::title('Painel executivo', 'Nota de qualidade de cada área, mês a mês. Passe o mouse numa célula para ver os indicadores do mês.', $actions);

if (!$g['areas']) {
    echo "<div class='card'><div class='card-body text-center text-secondary py-5'>Nenhuma área com plugin instalado. Instale os plugins (PMO, Reembolso, RH, Eventos, CSAT...) para o painel ter dados.</div></div></div>";
    Html::footer();
    return;
}

// resumo executivo
$sem = $geral['sem'] ?? 0;
echo "<div class='ind-summary'>";
echo "<div class='ind-hero ind-" . $e($geral['status'] ?? 'none') . "'><div><div class='ind-hero-label'>Índice geral de qualidade" . ($atual ? ' · ' . $e(Periodo::mes($atual)) : '') . "</div>"
    . "<div class='ind-hero-value'>" . (($geral['score'] ?? null) === null ? '—' : $e(number_format($geral['score'], 0, ',', '.'))) . "<small>/ 100</small></div>"
    . "<span class='ind-pill ind-" . $e($geral['status'] ?? 'none') . "'>" . $e(Score::label($geral['status'] ?? 'none')) . "</span>"
    . ($delta !== null ? " <span class='ind-delta " . ($delta >= 0 ? 'up' : 'down') . "'>" . ($delta >= 0 ? '▲ +' : '▼ −') . $e(number_format(abs($delta), 1, ',', '.')) . " vs. mês anterior</span>" : '')
    . "</div><div class='ind-hero-spark' data-serie='" . $e(json_encode(array_values(array_map(fn ($x) => $x['score'], $g['geral'])))) . "' data-meses='" . $e(json_encode(array_map([Periodo::class, 'mes'], $g['meses']))) . "'></div></div>";
$totalAreas = count($g['areas']);
$descr = [
    'ok'   => ['Áreas saudáveis', 'ti-circle-check', 'Nota igual ou acima de ' . (int) Menu::config()['green_min'] . ': operação dentro do esperado.'],
    'warn' => ['Em atenção', 'ti-alert-triangle', 'Nota entre ' . (int) Menu::config()['yellow_min'] . ' e ' . ((int) Menu::config()['green_min'] - 1) . ': acompanhar de perto.'],
    'bad'  => ['Críticas', 'ti-alert-octagon', 'Nota abaixo de ' . (int) Menu::config()['yellow_min'] . ': exigem plano de ação.'],
];
foreach ($descr as $k => [$label, $icon, $texto]) {
    $serie = array_values(array_map(fn ($x) => $x[$k], $g['geral']));
    $antes = $prevYm && isset($g['geral'][$prevYm]) ? $g['geral'][$prevYm][$k] : null;
    $agora = (int) ($geral[$k] ?? 0);
    $dv = $antes === null ? null : $agora - $antes;
    $melhor = $k === 'ok' ? $dv > 0 : $dv < 0;
    echo "<div class='ind-stat ind-$k'><i class='ti $icon ind-stat-ico'></i><div class='ind-stat-main'><span class='ind-stat-label'>$label</span>"
        . "<div class='ind-stat-num'>$agora<small>de $totalAreas</small></div>"
        . ($dv === null ? '' : "<span class='ind-delta " . ($dv === 0 ? 'flat' : ($melhor ? 'up' : 'down')) . "'>" . ($dv === 0 ? '= igual ao mês anterior' : ($dv > 0 ? '▲ +' : '▼ −') . abs($dv) . ' vs. mês anterior') . "</span>")
        . "<div class='ind-stat-desc'>" . $e($texto) . "</div></div>"
        . "<div class='ind-hero-spark ind-stat-spark' data-serie='" . $e(json_encode($serie)) . "' data-meses='" . $e(json_encode(array_map([Periodo::class, 'mes'], $g['meses']))) . "' data-cor='" . ['ok' => '#22c55e', 'warn' => '#f59e0b', 'bad' => '#ef4444'][$k] . "' data-min='0' data-max='" . max(1, $totalAreas) . "'></div></div>";
}
echo "</div>";

// grade área × mês
echo "<div class='card ind-gridcard'><div class='table-responsive'><table class='ind-grid'><thead><tr><th class='ind-area-h'>Área</th>";
for ($m = 1; $m <= 12; $m++) {
    $ym = sprintf('%04d-%02d', $ano, $m);
    echo "<th class='" . ($ym === $hoje ? 'ind-now' : '') . "'>" . $e(Periodo::mesCurto($m)) . "</th>";
}
echo "</tr></thead><tbody>";
$cell = function (string $area, string $ym, ?array $c) use ($e, $hoje): string {
    if ($ym > $hoje) {
        return "<td class='ind-cell ind-future'></td>";
    }
    $score = $c['score'] ?? null;
    $status = $c['status'] ?? 'none';
    $flags = ($c['bad'] ?? 0) ? "<i class='ind-dot ind-bad' title='" . (int) $c['bad'] . " crítico(s)'></i>" : '';
    return "<td class='ind-cell ind-$status" . ($ym === $hoje ? ' ind-now' : '') . "' tabindex='0' data-area='" . $e($area) . "' data-month='$ym'>"
        . "<a href='" . $e(Menu::url('/front/painel.php?area=' . $area . '&month=' . $ym)) . "'>" . ($score === null ? '—' : $e(number_format($score, 0, ',', '.'))) . "</a>$flags</td>";
};
foreach ($g['areas'] as $code => $a) {
    echo "<tr><th class='ind-area'><span class='ind-area-ico' style='--c:" . $e($a['cor']) . "'><i class='" . $e($a['icone']) . "'></i></span>"
        . "<span><b>" . $e($a['nome']) . "</b><small>" . $e($a['descricao']) . "</small></span></th>";
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%04d-%02d', $ano, $m);
        echo $cell($code, $ym, $g['grade'][$code][$ym] ?? null);
    }
    echo "</tr>";
}
echo "<tr class='ind-total'><th class='ind-area'><span class='ind-area-ico' style='--c:#64748b'><i class='ti ti-gauge'></i></span><span><b>Índice geral</b><small>Média das áreas</small></span></th>";
for ($m = 1; $m <= 12; $m++) {
    $ym = sprintf('%04d-%02d', $ano, $m);
    $x = $g['geral'][$ym] ?? null;
    echo $ym > $hoje ? "<td class='ind-cell ind-future'></td>"
        : "<td class='ind-cell ind-" . $e($x['status'] ?? 'none') . ($ym === $hoje ? ' ind-now' : '') . "' tabindex='0' data-area='all' data-month='$ym'><b>" . (($x['score'] ?? null) === null ? '—' : $e(number_format($x['score'], 0, ',', '.'))) . "</b></td>";
}
echo "</tr></tbody></table></div>";
$cfg = Menu::config();
echo "<div class='ind-legend'><span><i class='ind-dot ind-ok'></i> Saudável (≥ " . (int) $cfg['green_min'] . ")</span><span><i class='ind-dot ind-warn'></i> Atenção (≥ " . (int) $cfg['yellow_min'] . ")</span>"
    . "<span><i class='ind-dot ind-bad'></i> Crítico</span><span><i class='ind-dot ind-none'></i> Sem dados</span>"
    . "<span class='ms-auto text-secondary'>Indicadores de situação atual (pendências e atrasos abertos) só entram no mês corrente e nos meses em que o plugin gravou a foto.</span></div></div>";

// pontos de atenção e auditorias
echo "<div class='row g-3 mt-1'><div class='col-lg-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Pontos de atenção" . ($atual ? ' · ' . $e(Periodo::mes($atual)) : '') . "</h3></div><div class='list-group list-group-flush'>";
$atencao = $atual ? Painel::atencao($atual, 8) : [];
if (!$atencao) {
    echo "<div class='list-group-item text-center text-secondary py-4'>Nenhum indicador em atenção ou crítico. 🎉</div>";
}
foreach ($atencao as $x) {
    echo "<a class='list-group-item list-group-item-action ind-att' href='" . $e(Menu::url('/front/painel.php?area=' . $x['area'] . '&month=' . $atual)) . "'>"
        . "<i class='ind-dot ind-" . $e($x['status']) . "'></i><span class='ind-att-main'><b>" . $e($x['nome']) . "</b><small style='color:" . $e($x['cor']) . "'>" . $e($x['area_nome']) . ($x['vivo'] ? ' · situação atual' : '') . "</small></span>"
        . "<span class='ind-att-val'><b>" . $e($x['fmt']) . "</b><small>meta " . $e($x['fmtMeta']) . "</small></span></a>";
}
echo "</div></div></div>";

$audits = iterator_to_array($DB->request(['FROM' => Auditoria::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'next_run', 'LIMIT' => 6]), false);
$last = Execucao::ultimas();
echo "<div class='col-lg-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'>Próximas auditorias</h3></div><div class='list-group list-group-flush'>";
if (!$audits) {
    echo "<div class='list-group-item text-center text-secondary py-4'>Nenhuma auditoria programada.</div>";
}
foreach ($audits as $a) {
    $ult = $last[(int) $a['id']] ?? null;
    echo "<a class='list-group-item list-group-item-action ind-att' href='" . $e(Menu::url('/front/auditoria.php')) . "'>"
        . "<i class='ind-dot ind-" . $e($ult['status'] ?? 'none') . "'></i><span class='ind-att-main'><b>" . $e($a['name']) . "</b><small>" . $e(Auditoria::opcoesArea()[$a['area']] ?? $a['area']) . ' · ' . $e(Periodo::rotulo($a['frequency'])) . "</small></span>"
        . "<span class='ind-att-val'><b>" . ($a['next_run'] ? $e(Html::convDate($a['next_run'])) : '—') . "</b><small>" . ($ult && $ult['score'] !== null ? 'última nota ' . $e(number_format((float) $ult['score'], 0, ',', '.')) : 'ainda não executada') . "</small></span></a>";
}
echo "</div></div></div></div></div>";

echo "<div class='ind-pop' id='ind-pop' hidden></div>";
echo Menu::script('painel.js');
Html::footer();
