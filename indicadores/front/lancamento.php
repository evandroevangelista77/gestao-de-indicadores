<?php

/** Lançamento dos valores mensais dos indicadores de uma área manual (últimos 6 meses numa grade). */

use GlpiPlugin\Indicadores\Area;
use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Motor;
use GlpiPlugin\Indicadores\Periodo;

Session::checkRight(Menu::$rightname, UPDATE);

global $DB;
$e = fn ($v) => Menu::e($v);
$area = new Area();
if (!$area->getFromDB((int) ($_GET['area'] ?? $_POST['area'] ?? 0)) || $area->fields['type'] !== 'manual') {
    Html::redirect(Menu::url('/front/area.php'));
}
$f = $area->fields;
$kpis = Area::kpis((int) $f['id']);
$ate = Periodo::mesValido($_GET['ate'] ?? $_POST['ate'] ?? null) && ($_GET['ate'] ?? $_POST['ate']) <= date('Y-m') ? ($_GET['ate'] ?? $_POST['ate']) : date('Y-m');
$meses = Periodo::ultimosMeses($ate, 6);
$self = Menu::url('/front/lancamento.php?area=' . (int) $f['id'] . '&ate=' . $ate);

if (isset($_POST['save'])) {
    $n = 0;
    foreach ($kpis as $k) {
        $code = 'x' . (int) $f['id'] . '.k' . (int) $k['id'];
        foreach ($meses as $ym) {
            $raw = $_POST['v'][$k['id']][$ym] ?? null;
            if ($raw === null) {
                continue;
            }
            $raw = str_replace(['.', ','], ['', '.'], trim((string) $raw)); // 1.234,5 => 1234.5
            $value = $raw === '' || !is_numeric($raw) ? null : (float) $raw;
            Motor::gravar($ym, [$code => $value]);
            $n++;
        }
    }
    Session::addMessageAfterRedirect('Valores salvos. O painel já reflete os lançamentos.');
    Html::redirect($self);
}

$fotos = Motor::fotos($meses);
Menu::header('Lançar valores');
echo "<div class='ind'>";
echo Menu::title('Lançar valores · ' . $f['name'], 'Digite o valor de cada indicador por mês. Deixe em branco o mês sem dados.',
    "<form method='get' class='d-flex gap-2 align-items-end m-0' action='" . $e(Menu::url('/front/lancamento.php')) . "'><input type='hidden' name='area' value='" . (int) $f['id'] . "'>"
    . "<div><label class='form-label small mb-0'>Até o mês</label><input type='month' class='form-control form-control-sm' name='ate' max='" . $e(date('Y-m')) . "' value='" . $e($ate) . "' onchange='this.form.submit()'></div></form>"
    . Menu::button('Indicadores', Menu::url('/front/area.php?id=' . (int) $f['id']), 'ti-list-details', 'btn-outline-secondary')
    . Menu::button('Painel', Menu::url('/front/painel.php'), 'ti-layout-dashboard', 'btn-outline-secondary'));

if (!$kpis) {
    echo "<div class='card'><div class='card-body text-center text-secondary py-4'>Esta área ainda não tem indicadores. <a href='" . $e(Menu::url('/front/area.php?id=' . (int) $f['id'])) . "'>Defina os indicadores</a> primeiro.</div></div></div>";
    Html::footer();
    return;
}
echo "<form method='post' class='card'>" . Menu::csrf() . "<input type='hidden' name='area' value='" . (int) $f['id'] . "'><input type='hidden' name='ate' value='" . $e($ate) . "'>";
echo "<div class='table-responsive'><table class='table mb-0 align-middle'><thead><tr><th>Indicador</th>";
foreach ($meses as $ym) {
    echo "<th class='text-center' style='min-width:110px'>" . $e(Periodo::mes($ym)) . "</th>";
}
echo "</tr></thead><tbody>";
foreach ($kpis as $k) {
    $code = 'x' . (int) $f['id'] . '.k' . (int) $k['id'];
    echo "<tr><td><b>" . $e($k['name']) . "</b><div class='small text-secondary'>" . $e($k['unit']) . ' · ' . $e(Area::DIRECOES[$k['dir']] ?? '') . "</div></td>";
    foreach ($meses as $ym) {
        $v = $fotos[$ym][$code] ?? null;
        echo "<td><input class='form-control form-control-sm text-end' inputmode='decimal' name='v[" . (int) $k['id'] . "][$ym]' value='" . ($v === null ? '' : $e(rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ','))) . "'></td>";
    }
    echo "</tr>";
}
echo "</tbody></table></div><div class='card-footer text-end'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar valores</button></div></form>";
echo "</div>";
Html::footer();
