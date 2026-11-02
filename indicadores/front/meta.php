<?php

/**
 * Metas e limites: para cada indicador, a meta (verde), o limite (onde passa de amarelo a vermelho),
 * o peso na nota da área e se o indicador conta. Quem lê "up" quer valores altos; "down", valores baixos.
 */

use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Meta;
use GlpiPlugin\Indicadores\Motor;
use GlpiPlugin\Indicadores\Score;

Session::checkRight(Menu::$rightname, UPDATE);

global $DB;
$e = fn ($v) => Menu::e($v);
$self = Menu::url('/front/meta.php');
$meta = new Meta();

if (isset($_POST['save']) && $meta->getFromDB((int) ($_POST['id'] ?? 0))) {
    $meta->update([
        'id'        => $meta->getID(),
        'meta'      => $_POST['meta'] ?? '',
        'limite'    => $_POST['limite'] ?? '',
        'peso'      => $_POST['peso'] ?? 1,
        'is_active' => $_POST['is_active'] ?? 0,
    ]);
    Motor::esquecerCatalogo();
    Session::addMessageAfterRedirect('Meta salva. A nota dos meses é recalculada na hora, a partir dos valores gravados.');
    Html::redirect($self . '#m' . (int) $_POST['id']);
}
if (isset($_POST['reset'])) {
    foreach (Motor::coletores() as $c) {
        foreach ($c->indicadores() as $code => $def) {
            $DB->update(Meta::getTable(), ['meta' => $def['meta'], 'limite' => $def['limite'], 'peso' => $def['peso'], 'is_active' => 1], ['code' => $code]);
        }
    }
    Motor::esquecerCatalogo();
    Session::addMessageAfterRedirect('Metas, limites e pesos voltaram ao padrão.');
    Html::redirect($self);
}

Meta::sync();
Menu::header('Metas e limites');
echo "<div class='ind'>";
echo Menu::title('Metas e limites', 'Ajuste a meta, o limite e o peso de cada indicador. Indicadores informativos aparecem no painel mas não entram na nota.',
    "<form method='post' class='m-0'>" . Menu::csrf() . "<button class='btn btn-outline-secondary' name='reset' value='1' onclick=\"return confirm('Restaurar as metas, limites e pesos padrão de todos os indicadores?')\"><i class='ti ti-restore me-1'></i>Restaurar padrões</button></form>"
    . Menu::button('Painel executivo', Menu::url('/front/painel.php'), 'ti-layout-dashboard', 'btn-outline-secondary'));

$dirs = ['up' => '↑ quanto maior, melhor', 'down' => '↓ quanto menor, melhor', 'info' => 'informativo'];
$byArea = [];
foreach (Meta::todos() as $row) {
    $byArea[$row['area']][] = $row;
}
foreach (Motor::coletores() as $area => $c) {
    if (empty($byArea[$area])) {
        continue;
    }
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='" . $e($c->icone()) . " me-2' style='color:" . $e($c->cor()) . "'></i>" . $e($c->nome())
        . "</h3>" . (!$c->disponivel() ? "<span class='badge bg-secondary-lt ms-2'>plugin não instalado</span>" : '') . "</div>"
        . "<div class='table-responsive'><table class='table table-hover mb-0 align-middle'><thead><tr><th>Indicador</th><th>Direção</th><th style='width:110px'>Meta</th><th style='width:110px'>Limite</th><th style='width:80px'>Peso</th><th>Conta</th><th></th></tr></thead><tbody>";
    foreach ($byArea[$area] as $row) {
        $id = (int) $row['id'];
        $info = $row['dir'] === 'info';
        $num = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
        $f = "form='f$id'";
        echo "<tr id='m$id'" . (!$row['is_active'] ? " class='text-secondary'" : '') . ">"
            . "<td><b>" . $e($row['name']) . "</b><div class='small text-secondary'>" . $e($row['dica']) . ($row['vivo'] ? ' · situação atual' : '') . "</div></td>"
            . "<td class='small'>" . $e($dirs[$row['dir']] ?? $row['dir']) . " <span class='text-secondary'>(" . $e($row['unit']) . ")</span></td>"
            . "<td>" . ($info ? '—' : "<input $f class='form-control form-control-sm' name='meta' value='" . $e($num($row['meta'])) . "'>") . "</td>"
            . "<td>" . ($info ? '—' : "<input $f class='form-control form-control-sm' name='limite' value='" . $e($num($row['limite'])) . "'>") . "</td>"
            . "<td>" . ($info ? '—' : "<input $f type='number' class='form-control form-control-sm' name='peso' min='0' max='10' value='" . (int) $row['peso'] . "'>") . "</td>"
            . "<td><label class='form-check mb-0'><input $f class='form-check-input' type='checkbox' name='is_active' value='1'" . ($row['is_active'] ? ' checked' : '') . "></label></td>"
            . "<td class='text-end'><form id='f$id' method='post' class='d-inline'>" . Menu::csrf() . "<input type='hidden' name='id' value='$id'></form>"
            . "<button $f class='btn btn-sm btn-outline-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></td></tr>";
    }
    echo "</tbody></table></div></div>";
}
echo "<p class='text-secondary small'>Verde quando o valor atinge a meta; amarelo entre a meta e o limite; vermelho além do limite. O peso define quanto o indicador pesa na nota da área (0 a 10).</p>";
echo "</div>";
Html::footer();
