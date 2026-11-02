<?php

/**
 * Áreas do painel: inclui áreas além das que vêm com o plugin. Manual (indicadores definidos aqui e valores
 * lançados mês a mês) ou chamados do GLPI (calculada a partir de uma entidade, grupo ou categoria).
 */

use GlpiPlugin\Indicadores\Area;
use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Meta;
use GlpiPlugin\Indicadores\Motor;

Session::checkRight(Menu::$rightname, UPDATE);

global $DB;
$e = fn ($v) => Menu::e($v);
$self = Menu::url('/front/area.php');
$area = new Area();

// ---------------------------------------------------------------- ações
if (isset($_POST['save'])) {
    $input = [
        'name'              => $_POST['name'] ?? '',
        'description'       => $_POST['description'] ?? '',
        'type'              => $_POST['type'] ?? 'manual',
        'icon'              => $_POST['icon'] ?? 'ti ti-chart-bar',
        'color'             => $_POST['color'] ?? '#64748b',
        'entities_id'       => (int) ($_POST['entities_id'] ?? 0),
        'groups_id'         => (int) ($_POST['groups_id'] ?? 0),
        'itilcategories_id' => (int) ($_POST['itilcategories_id'] ?? 0),
        'is_active'         => $_POST['is_active'] ?? 0,
    ];
    if (!empty($_POST['id'])) {
        if ($area->getFromDB((int) $_POST['id'])) {
            unset($input['type']); // o tipo não muda depois de criada
            $area->update(['id' => $area->getID()] + $input);
            Area::atualizar($area->getID());
            Session::addMessageAfterRedirect('Área salva.');
        }
        Html::redirect($self);
    }
    $id = $area->add($input);
    if ($id) {
        Area::atualizar((int) $id);
        Session::addMessageAfterRedirect('Área criada e incluída no painel.' . ($input['type'] === 'manual' ? ' Agora defina os indicadores.' : ''));
        Html::redirect($input['type'] === 'manual' ? $self . '?id=' . (int) $id : $self);
    }
    Html::redirect($self);
}
if (isset($_POST['toggle']) && $area->getFromDB((int) $_POST['id'])) {
    $area->update(['id' => $area->getID(), 'is_active' => $area->fields['is_active'] ? 0 : 1]);
    Motor::esquecer();
    Html::redirect($self);
}
if (isset($_POST['delete']) && $area->getFromDB((int) $_POST['id'])) {
    Area::excluir($area->getID());
    Session::addMessageAfterRedirect('Área, indicadores e valores removidos.');
    Html::redirect($self);
}
// indicadores das áreas manuais
if (isset($_POST['kpi_save']) && $area->getFromDB((int) ($_POST['area_id'] ?? 0)) && $area->fields['type'] === 'manual') {
    $num = fn ($v) => ($v = str_replace(',', '.', trim((string) $v))) === '' || !is_numeric($v) ? null : (float) $v;
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 255);
    $unit = isset(Area::UNIDADES[$_POST['unit'] ?? '']) ? $_POST['unit'] : 'n';
    $dir = isset(Area::DIRECOES[$_POST['dir'] ?? '']) ? $_POST['dir'] : 'info';
    $meta = $dir === 'info' ? null : $num($_POST['meta'] ?? '');
    $limite = $dir === 'info' ? null : $num($_POST['limite'] ?? '');
    $peso = $dir === 'info' ? 0 : max(0, min(10, (int) ($_POST['peso'] ?? 1)));
    if ($name === '') {
        Session::addMessageAfterRedirect('Informe o nome do indicador.', false, ERROR);
    } elseif ($dir !== 'info' && ($meta === null || $limite === null)) {
        Session::addMessageAfterRedirect('Informe a meta e o limite (a partir de onde o indicador fica vermelho).', false, ERROR);
    } else {
        $row = ['name' => $name, 'unit' => $unit, 'dir' => $dir, 'meta' => $meta, 'limite' => $limite, 'peso' => $peso];
        if (!empty($_POST['kpi_id'])) {
            $DB->update(Area::KPIS, $row, ['id' => (int) $_POST['kpi_id'], 'plugin_indicadores_areas_id' => $area->getID()]);
            Motor::esquecer();
            Meta::sync();
            $DB->update(Meta::getTable(), ['meta' => $meta, 'limite' => $limite, 'peso' => $peso], ['code' => 'x' . $area->getID() . '.k' . (int) $_POST['kpi_id']]);
        } else {
            $DB->insert(Area::KPIS, $row + ['plugin_indicadores_areas_id' => $area->getID(), 'position' => (count(Area::kpis($area->getID())) + 1) * 10]);
            Motor::esquecer();
            Meta::sync();
        }
        Motor::esquecerCatalogo();
        Session::addMessageAfterRedirect('Indicador salvo.');
    }
    Html::redirect($self . '?id=' . $area->getID());
}
if (isset($_POST['kpi_delete']) && $area->getFromDB((int) ($_POST['area_id'] ?? 0))) {
    $kid = (int) ($_POST['kpi_id'] ?? 0);
    $code = 'x' . $area->getID() . '.k' . $kid;
    $DB->delete(Area::KPIS, ['id' => $kid, 'plugin_indicadores_areas_id' => $area->getID()]);
    $DB->delete(Motor::VALORES, ['code' => $code]);
    $DB->delete(Meta::getTable(), ['code' => $code]);
    Motor::esquecer();
    Session::addMessageAfterRedirect('Indicador removido.');
    Html::redirect($self . '?id=' . $area->getID());
}

// ---------------------------------------------------------------- tela
$opt = function (array $list, string $current) use ($e): string {
    $html = '';
    foreach ($list as $key => $label) {
        $html .= "<option value='" . $e($key) . "'" . ((string) $key === $current ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    return $html;
};

// indicadores de uma área manual
if (isset($_GET['id']) && $area->getFromDB((int) $_GET['id']) && $area->fields['type'] === 'manual') {
    $f = $area->fields;
    $kpis = Area::kpis((int) $f['id']);
    $kedit = null;
    foreach ($kpis as $k) {
        if (isset($_GET['kpi']) && (int) $_GET['kpi'] === (int) $k['id']) {
            $kedit = $k;
        }
    }
    $num = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
    Menu::header('Indicadores da área');
    echo "<div class='ind'>";
    echo Menu::title($f['name'], 'Defina os indicadores desta área e lance os valores de cada mês.',
        Menu::button('Lançar valores', Menu::url('/front/lancamento.php?area=' . (int) $f['id']), 'ti-edit', 'btn-primary')
        . Menu::button('Áreas do painel', $self, 'ti-layout-grid-add', 'btn-outline-secondary'));
    echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-hover mb-0 align-middle'><thead><tr><th>Indicador</th><th>Unidade</th><th>Direção</th><th class='text-end'>Meta</th><th class='text-end'>Limite</th><th class='text-end'>Peso</th><th></th></tr></thead><tbody>";
    if (!$kpis) {
        echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Nenhum indicador ainda. Use o formulário abaixo.</td></tr>";
    }
    foreach ($kpis as $k) {
        echo "<tr><td><b>" . $e($k['name']) . "</b></td><td>" . $e($k['unit']) . "</td><td class='small'>" . $e(Area::DIRECOES[$k['dir']] ?? $k['dir']) . "</td>"
            . "<td class='text-end'>" . ($k['dir'] === 'info' ? '—' : $e($num($k['meta']))) . "</td><td class='text-end'>" . ($k['dir'] === 'info' ? '—' : $e($num($k['limite']))) . "</td><td class='text-end'>" . (int) $k['peso'] . "</td>"
            . "<td class='text-end text-nowrap'><a class='btn btn-sm btn-outline-secondary' href='" . $e($self . '?id=' . (int) $f['id'] . '&kpi=' . (int) $k['id']) . "'><i class='ti ti-pencil'></i></a> "
            . "<form method='post' class='d-inline'>" . Menu::csrf() . "<input type='hidden' name='area_id' value='" . (int) $f['id'] . "'><input type='hidden' name='kpi_id' value='" . (int) $k['id'] . "'>"
            . "<button class='btn btn-sm btn-outline-danger' name='kpi_delete' value='1' onclick=\"return confirm('Remover este indicador e os valores lançados?')\"><i class='ti ti-trash'></i></button></form></td></tr>";
    }
    echo "</tbody></table></div></div>";
    $v = fn (string $k, $d = '') => $e($kedit[$k] ?? $d);
    echo "<form method='post' class='card'>" . Menu::csrf() . "<input type='hidden' name='area_id' value='" . (int) $f['id'] . "'>" . ($kedit ? "<input type='hidden' name='kpi_id' value='" . (int) $kedit['id'] . "'>" : '')
        . "<div class='card-header'><h3 class='card-title'>" . ($kedit ? 'Editar indicador' : 'Novo indicador') . "</h3></div><div class='card-body'><div class='row'>"
        . "<div class='col-md-5 mb-3'><label class='form-label'>Nome</label><input class='form-control' name='name' maxlength='255' required value='" . $v('name') . "' placeholder='Ex.: Disponibilidade do sistema'></div>"
        . "<div class='col-md-3 mb-3'><label class='form-label'>Unidade</label><select class='form-select' name='unit'>" . $opt(Area::UNIDADES, (string) ($kedit['unit'] ?? '%')) . "</select></div>"
        . "<div class='col-md-4 mb-3'><label class='form-label'>Direção</label><select class='form-select' name='dir'>" . $opt(Area::DIRECOES, (string) ($kedit['dir'] ?? 'up')) . "</select></div>"
        . "<div class='col-md-3 mb-3'><label class='form-label'>Meta (verde)</label><input class='form-control' name='meta' value='" . $e($num($kedit['meta'] ?? null)) . "'></div>"
        . "<div class='col-md-3 mb-3'><label class='form-label'>Limite (vermelho além dele)</label><input class='form-control' name='limite' value='" . $e($num($kedit['limite'] ?? null)) . "'></div>"
        . "<div class='col-md-2 mb-3'><label class='form-label'>Peso (0 a 10)</label><input type='number' class='form-control' name='peso' min='0' max='10' value='" . $v('peso', '1') . "'></div>"
        . "</div><p class='text-secondary small mb-0'>Quanto maior, melhor: a meta fica acima do limite (ex.: meta 99, limite 95). Quanto menor, melhor: a meta fica abaixo (ex.: meta 2, limite 10). Indicadores informativos aparecem no painel mas não entram na nota.</p>"
        . "</div><div class='card-footer text-end'>" . ($kedit ? "<a class='btn btn-link' href='" . $e($self . '?id=' . (int) $f['id']) . "'>Cancelar</a> " : '')
        . "<button class='btn btn-primary' name='kpi_save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar indicador</button></form>";
    echo "</div>";
    Html::footer();
    return;
}

// lista e formulário da área
$editing = null;
if (isset($_GET['edit']) && $area->getFromDB((int) $_GET['edit'])) {
    $editing = $area->fields;
}
$rows = Area::todas();

Menu::header('Áreas do painel');
echo "<div class='ind'>";
echo Menu::title('Áreas do painel', 'Inclua áreas além das nove que vêm com o plugin: elas entram no painel, nos relatórios em PDF e nas auditorias.',
    Menu::button('Painel executivo', Menu::url('/front/painel.php'), 'ti-layout-dashboard', 'btn-outline-secondary'));

echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-hover mb-0 align-middle'><thead><tr><th>Área</th><th>Tipo</th><th>Indicadores</th><th>Situação</th><th></th></tr></thead><tbody>";
if (!$rows) {
    echo "<tr><td colspan='5' class='text-center text-secondary py-4'>Nenhuma área personalizada. Crie a primeira abaixo.</td></tr>";
}
foreach ($rows as $a) {
    $id = (int) $a['id'];
    $n = $a['type'] === 'manual' ? count(Area::kpis($id)) : 5;
    echo "<tr" . (!$a['is_active'] ? " class='text-secondary'" : '') . "><td><span class='ind-area-ico me-2' style='--c:" . $e($a['color']) . "'><i class='" . $e($a['icon']) . "'></i></span><b>" . $e($a['name']) . "</b>"
        . ($a['description'] ? "<div class='small text-secondary'>" . $e($a['description']) . "</div>" : '') . "</td>"
        . "<td>" . ($a['type'] === 'manual' ? "<span class='badge bg-blue-lt'>Manual</span>" : "<span class='badge bg-purple-lt'>Chamados do GLPI</span>") . "</td>"
        . "<td>" . $n . "</td><td>" . ($a['is_active'] ? "<span class='badge bg-green-lt'>No painel</span>" : "<span class='badge bg-secondary-lt'>Oculta</span>") . "</td>"
        . "<td class='text-end text-nowrap'>";
    if ($a['type'] === 'manual') {
        echo "<a class='btn btn-sm btn-outline-primary' href='" . $e($self . '?id=' . $id) . "'><i class='ti ti-list-details me-1'></i>Indicadores</a> "
            . "<a class='btn btn-sm btn-outline-primary' href='" . $e(Menu::url('/front/lancamento.php?area=' . $id)) . "'><i class='ti ti-edit me-1'></i>Lançar valores</a> ";
    }
    echo "<a class='btn btn-sm btn-outline-secondary' href='" . $e($self . '?edit=' . $id) . "'><i class='ti ti-pencil'></i></a> "
        . "<form method='post' class='d-inline'>" . Menu::csrf() . "<input type='hidden' name='id' value='$id'><button class='btn btn-sm btn-outline-secondary' name='toggle' value='1'>" . ($a['is_active'] ? 'Ocultar' : 'Mostrar') . "</button></form> "
        . "<form method='post' class='d-inline'>" . Menu::csrf() . "<input type='hidden' name='id' value='$id'><button class='btn btn-sm btn-outline-danger' name='delete' value='1' onclick=\"return confirm('Excluir esta área, os indicadores e todos os valores?')\"><i class='ti ti-trash'></i></button></form>"
        . "</td></tr>";
}
echo "</tbody></table></div></div>";

$v = fn (string $k, $d = '') => $e($editing[$k] ?? $d);
$type = (string) ($editing['type'] ?? 'manual');
echo "<form method='post' class='card'>" . Menu::csrf() . "<div class='card-header'><h3 class='card-title'>" . ($editing ? 'Editar área' : 'Nova área') . "</h3></div><div class='card-body'>";
if ($editing) {
    echo "<input type='hidden' name='id' value='" . (int) $editing['id'] . "'>";
}
echo "<div class='row'>"
    . "<div class='col-md-5 mb-3'><label class='form-label'>Nome da área</label><input class='form-control' name='name' maxlength='120' required value='" . $v('name') . "' placeholder='Ex.: Segurança da Informação'></div>"
    . "<div class='col-md-4 mb-3'><label class='form-label'>Ícone</label><select class='form-select' name='icon'>" . $opt(Area::ICONES, (string) ($editing['icon'] ?? 'ti ti-chart-bar')) . "</select></div>"
    . "<div class='col-md-3 mb-3'><label class='form-label'>Cor</label><input type='color' class='form-control form-control-color w-100' name='color' value='" . $v('color', '#3b82f6') . "'></div>"
    . "<div class='col-md-8 mb-3'><label class='form-label'>Descrição (aparece no painel)</label><input class='form-control' name='description' maxlength='255' value='" . $v('description') . "'></div>"
    . "<div class='col-md-4 mb-3'><label class='form-label'>Tipo</label><select class='form-select' name='type' " . ($editing ? 'disabled' : '') . " onchange=\"document.getElementById('ind-chamados').hidden = this.value !== 'chamados'\">" . $opt(Area::TIPOS, $type) . "</select></div>"
    . "</div>";
echo "<div id='ind-chamados' class='row' " . ($type === 'chamados' ? '' : 'hidden') . "><div class='col-12'><div class='text-secondary small mb-2'>Os indicadores de atendimento (prazo, tempo de solução, fila e vencidos) saem dos chamados que atendem a todos os filtros abaixo. A entidade inclui as filhas.</div></div>"
    . "<div class='col-md-4 mb-3'><label class='form-label'>Entidade</label>";
Entity::dropdown(['name' => 'entities_id', 'value' => (int) ($editing['entities_id'] ?? 0), 'display' => true]);
echo "</div><div class='col-md-4 mb-3'><label class='form-label'>Grupo atribuído (opcional)</label>";
Group::dropdown(['name' => 'groups_id', 'value' => (int) ($editing['groups_id'] ?? 0), 'display' => true]);
echo "</div><div class='col-md-4 mb-3'><label class='form-label'>Categoria (opcional)</label>";
ITILCategory::dropdown(['name' => 'itilcategories_id', 'value' => (int) ($editing['itilcategories_id'] ?? 0), 'display' => true]);
echo "</div></div>";
echo "<label class='form-check mb-0'><input class='form-check-input' type='checkbox' name='is_active' value='1'" . (($editing['is_active'] ?? 1) ? ' checked' : '') . "><span class='form-check-label'>Mostrar no painel</span></label>";
echo "</div><div class='card-footer text-end'>" . ($editing ? "<a class='btn btn-link' href='" . $e($self) . "'>Cancelar</a> " : '')
    . "<button class='btn btn-primary' type='submit' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>" . ($editing ? 'Salvar' : 'Criar área') . "</button></div></form>";
echo "</div>";
Html::footer();
