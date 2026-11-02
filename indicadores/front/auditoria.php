<?php

/**
 * Auditorias programadas: cria, edita e executa. Cada auditoria avalia o período que acabou de fechar
 * (quinzena, mês, bimestre, trimestre, semestre ou ano) em uma área ou em todas.
 */

use GlpiPlugin\Indicadores\Auditoria;
use GlpiPlugin\Indicadores\Execucao;
use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Periodo;
use GlpiPlugin\Indicadores\Score;

Session::checkRight(Menu::$rightname, READ);

global $DB;
$canEdit = Session::haveRight(Menu::$rightname, UPDATE);
$e = fn ($v) => Menu::e($v);
$self = Menu::url('/front/auditoria.php');
$audit = new Auditoria();

if ($canEdit && isset($_POST['save'])) {
    $input = [
        'name'        => $_POST['name'] ?? '',
        'area'        => $_POST['area'] ?? 'all',
        'frequency'   => $_POST['frequency'] ?? 'mensal',
        'users_id'    => (int) ($_POST['users_id'] ?? 0),
        'min_score'   => $_POST['min_score'] ?? 80,
        'open_ticket' => $_POST['open_ticket'] ?? 0,
        'is_active'   => $_POST['is_active'] ?? 0,
        'comment'     => $_POST['comment'] ?? '',
    ];
    if (!empty($_POST['id'])) {
        if ($audit->getFromDB((int) $_POST['id'])) {
            $audit->update(['id' => $audit->getID()] + $input);
            Session::addMessageAfterRedirect('Auditoria salva.');
        }
    } elseif ($audit->add($input)) {
        Session::addMessageAfterRedirect('Auditoria criada. A primeira execução será em ' . Html::convDate($audit->fields['next_run'] ?? Periodo::proxima($input['frequency'], date('Y-m-d'))) . '.');
    }
    Html::redirect($self);
}
if ($canEdit && isset($_POST['run']) && $audit->getFromDB((int) $_POST['id'])) {
    $execId = Auditoria::executar($audit->getID(), 'manual');
    Html::redirect($execId ? Menu::url('/front/execucao.php?id=' . $execId) : $self);
}
if ($canEdit && isset($_POST['toggle']) && $audit->getFromDB((int) $_POST['id'])) {
    $audit->update(['id' => $audit->getID(), 'is_active' => $audit->fields['is_active'] ? 0 : 1]);
    Html::redirect($self);
}
if ($canEdit && isset($_POST['delete']) && $audit->getFromDB((int) $_POST['id'])) {
    $DB->delete(Execucao::getTable(), ['plugin_indicadores_auditorias_id' => $audit->getID()]);
    $audit->delete(['id' => $audit->getID()], true);
    Session::addMessageAfterRedirect('Auditoria e histórico removidos.');
    Html::redirect($self);
}

$editing = null;
if ($canEdit && isset($_GET['edit']) && $audit->getFromDB((int) $_GET['edit'])) {
    $editing = $audit->fields;
}

Menu::header('Auditorias');
echo "<div class='ind'>";
echo Menu::title('Auditorias programadas', 'Escolha a área e a frequência: a auditoria roda sozinha, avalia o período que fechou e registra a nota, a evolução e os pontos de atenção.',
    Menu::button('Painel executivo', Menu::url('/front/painel.php'), 'ti-layout-dashboard', 'btn-outline-secondary'));

$areas = Auditoria::opcoesArea();
$last = Execucao::ultimas();
$rows = iterator_to_array($DB->request(['FROM' => Auditoria::getTable(), 'ORDER' => ['is_active DESC', 'next_run']]), false);

echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-hover mb-0'>";
echo "<thead><tr><th>Auditoria</th><th>Área</th><th>Frequência</th><th>Próxima execução</th><th>Última execução</th><th class='text-center'>Mín.</th><th>Situação</th><th></th></tr></thead><tbody>";
if (!$rows) {
    echo "<tr><td colspan='8' class='text-center text-secondary py-4'>Nenhuma auditoria cadastrada.</td></tr>";
}
foreach ($rows as $a) {
    $id = (int) $a['id'];
    $ult = $last[$id] ?? null;
    echo "<tr" . (!$a['is_active'] ? " class='text-secondary'" : '') . ">";
    echo "<td><b>" . $e($a['name']) . "</b>" . ($a['users_id'] ? "<div class='small text-secondary'>Responsável: " . $e(getUserName((int) $a['users_id'])) . "</div>" : '') . "</td>";
    echo "<td>" . $e($areas[$a['area']] ?? $a['area']) . "</td>";
    echo "<td><span class='badge bg-blue-lt'>" . $e(Periodo::rotulo($a['frequency'])) . "</span></td>";
    echo "<td>" . ($a['next_run'] ? $e(Html::convDate($a['next_run'])) : '—') . "</td>";
    echo "<td>" . ($ult
        ? "<a href='" . $e(Menu::url('/front/execucao.php?id=' . (int) $ult['id'])) . "'><span class='ind-pill ind-" . $e($ult['status']) . "'>" . ($ult['score'] === null ? '—' : $e(number_format((float) $ult['score'], 0, ',', '.'))) . "</span></a> <span class='small text-secondary'>" . $e(Periodo::texto($ult['period_from'], $ult['period_to'])) . "</span>"
        : "<span class='text-secondary'>ainda não executada</span>") . "</td>";
    echo "<td class='text-center'>" . (int) $a['min_score'] . ($a['open_ticket'] ? " <i class='ti ti-ticket' title='Abre chamado quando a nota fica abaixo do mínimo'></i>" : '') . "</td>";
    echo "<td>" . ($a['is_active'] ? "<span class='badge bg-green-lt'>Ativa</span>" : "<span class='badge bg-secondary-lt'>Pausada</span>") . "</td>";
    echo "<td class='text-end text-nowrap'>";
    if ($canEdit) {
        $post = fn (string $name, string $label, string $cls, string $extra = '') => "<form method='post' class='d-inline'>" . Menu::csrf() . "<input type='hidden' name='id' value='$id'><button class='btn btn-sm $cls' name='$name' value='1' $extra>$label</button></form> ";
        echo $post('run', "<i class='ti ti-player-play me-1'></i>Executar agora", 'btn-outline-primary', "title='Avalia agora o último período fechado'");
        echo "<a class='btn btn-sm btn-outline-secondary' href='" . $e($self . '?edit=' . $id) . "'><i class='ti ti-pencil'></i></a> ";
        echo $post('toggle', $a['is_active'] ? 'Pausar' : 'Ativar', 'btn-outline-secondary');
        echo $post('delete', "<i class='ti ti-trash'></i>", 'btn-outline-danger', "title='Excluir a auditoria e o histórico' onclick=\"return confirm('Excluir esta auditoria e todo o histórico de execuções?')\"");
    }
    echo "</td></tr>";
}
echo "</tbody></table></div></div>";

// histórico recente
$hist = Execucao::recentes(null, 10);
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Execuções recentes</h3></div><div class='table-responsive'><table class='table table-hover mb-0'>"
    . "<thead><tr><th>Auditoria</th><th>Período avaliado</th><th>Executada em</th><th class='text-end'>Nota</th><th class='text-end'>Críticos</th><th class='text-end'>Em atenção</th><th>Origem</th><th></th></tr></thead><tbody>";
if (!$hist) {
    echo "<tr><td colspan='8' class='text-center text-secondary py-3'>Nenhuma execução ainda. Use “Executar agora” para ver o resultado.</td></tr>";
}
foreach ($hist as $x) {
    echo "<tr><td>" . $e($x['name']) . "</td><td>" . $e(Periodo::texto($x['period_from'], $x['period_to'])) . "</td><td>" . $e(Html::convDateTime($x['date_run'])) . "</td>"
        . "<td class='text-end'><span class='ind-pill ind-" . $e($x['status']) . "'>" . ($x['score'] === null ? '—' : $e(number_format((float) $x['score'], 0, ',', '.'))) . "</span></td>"
        . "<td class='text-end'>" . (int) $x['kpis_bad'] . "</td><td class='text-end'>" . (int) $x['kpis_warn'] . "</td>"
        . "<td>" . ($x['origin'] === 'auto' ? 'Programada' : 'Manual') . "</td>"
        . "<td class='text-end'><a class='btn btn-sm btn-outline-primary' href='" . $e(Menu::url('/front/execucao.php?id=' . (int) $x['id'])) . "'>Abrir</a></td></tr>";
}
echo "</tbody></table></div></div>";

if ($canEdit) {
    $v = fn (string $key, $default = '') => $e($editing[$key] ?? $default);
    $opt = function (array $list, string $current) use ($e): string {
        $html = '';
        foreach ($list as $key => $label) {
            $html .= "<option value='" . $e($key) . "'" . ($current === (string) $key ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        return $html;
    };
    echo "<form method='post' class='card'>" . Menu::csrf() . "<div class='card-header'><h3 class='card-title'>" . ($editing ? 'Editar auditoria' : 'Nova auditoria') . "</h3></div><div class='card-body'>";
    if ($editing) {
        echo "<input type='hidden' name='id' value='" . (int) $editing['id'] . "'>";
    }
    echo "<div class='row'>";
    echo "<div class='col-md-6 mb-3'><label class='form-label'>Nome</label><input class='form-control' name='name' maxlength='255' required value='" . $v('name') . "' placeholder='Ex.: Auditoria trimestral de PMO'></div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Área</label><select class='form-select' name='area'>" . $opt($areas, (string) ($editing['area'] ?? 'all')) . "</select></div>";
    echo "<div class='col-md-3 mb-3'><label class='form-label'>Frequência</label><select class='form-select' name='frequency'>" . $opt(Periodo::FREQUENCIAS, (string) ($editing['frequency'] ?? 'mensal')) . "</select></div>";
    echo "<div class='col-md-4 mb-3'><label class='form-label'>Responsável pelos achados</label>";
    User::dropdown(['name' => 'users_id', 'value' => (int) ($editing['users_id'] ?? 0), 'right' => 'all', 'display' => true]);
    echo "</div>";
    echo "<div class='col-md-2 mb-3'><label class='form-label'>Nota mínima</label><input type='number' class='form-control' name='min_score' min='0' max='100' value='" . $v('min_score', '80') . "'></div>";
    echo "<div class='col-md-6 mb-3 d-flex flex-column justify-content-end gap-2'>"
        . "<label class='form-check mb-0'><input class='form-check-input' type='checkbox' name='open_ticket' value='1'" . (($editing['open_ticket'] ?? 0) ? ' checked' : '') . "><span class='form-check-label'>Abrir um chamado para o responsável quando a nota ficar abaixo do mínimo</span></label>"
        . "<label class='form-check mb-0'><input class='form-check-input' type='checkbox' name='is_active' value='1'" . (($editing['is_active'] ?? 1) ? ' checked' : '') . "><span class='form-check-label'>Ativa (roda sozinha na data programada)</span></label></div>";
    echo "<div class='col-12 mb-3'><label class='form-label'>Observações</label><textarea class='form-control' name='comment' rows='2'>" . $v('comment') . "</textarea></div>";
    echo "</div><p class='text-secondary small mb-0'><b>Quando roda:</b> quinzenal nos dias 1 e 16 · mensal no dia 1 · bimestral em jan, mar, mai, jul, set e nov · trimestral em jan, abr, jul e out · semestral em jan e jul · anual em 1º de janeiro. "
        . "A auditoria avalia o período que acabou de fechar; os indicadores de situação atual (pendências e atrasos abertos) são avaliados no momento da execução.</p>";
    echo "</div><div class='card-footer text-end'>"
        . ($editing ? "<a class='btn btn-link' href='" . $e($self) . "'>Cancelar</a> " : '')
        . "<button class='btn btn-primary' type='submit' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>" . ($editing ? 'Salvar' : 'Criar auditoria') . "</button></div></form>";
}
echo "</div>";
Html::footer();
