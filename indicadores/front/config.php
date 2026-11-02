<?php

/** Configuração: faixas de nota das áreas, chamados das auditorias e atualização do mês corrente. */

use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Motor;

Session::checkRight('config', UPDATE);

$e = fn ($v) => Menu::e($v);
$self = Menu::url('/front/config.php');

if (isset($_POST['save'])) {
    $green = max(1, min(100, (int) ($_POST['green_min'] ?? 80)));
    $yellow = max(1, min($green - 1, (int) ($_POST['yellow_min'] ?? 60)));
    Config::setConfigurationValues(Menu::CONFIG_CONTEXT, [
        'green_min'     => (string) $green,
        'yellow_min'    => (string) $yellow,
        'ticket_entity' => (string) max(0, (int) ($_POST['ticket_entity'] ?? 0)),
        'ticket_type'   => (string) (in_array((int) ($_POST['ticket_type'] ?? 2), [1, 2], true) ? (int) $_POST['ticket_type'] : 2),
        'refresh_min'   => (string) max(1, min(1440, (int) ($_POST['refresh_min'] ?? 15))),
    ]);
    Session::addMessageAfterRedirect('Configuração salva.');
    Html::redirect($self);
}
if (isset($_POST['recalc'])) {
    $n = Motor::recalcular(max(1, min(36, (int) ($_POST['months'] ?? 12))));
    Session::addMessageAfterRedirect("Foram recalculados $n mês(es).");
    Html::redirect($self);
}

$cfg = Menu::config();
Menu::header('Configuração');
echo "<div class='ind'>";
echo Menu::title('Configuração', 'Faixas de nota, chamados das auditorias e atualização dos indicadores.', Menu::button('Painel executivo', Menu::url('/front/painel.php'), 'ti-layout-dashboard', 'btn-outline-secondary'));

echo "<form method='post' class='card mb-3'>" . Menu::csrf() . "<div class='card-header'><h3 class='card-title'>Faixas da nota de uma área</h3></div><div class='card-body'><div class='row'>";
echo "<div class='col-md-3 mb-3'><label class='form-label'>Saudável a partir de</label><input type='number' class='form-control' name='green_min' min='1' max='100' value='" . $e($cfg['green_min']) . "'></div>";
echo "<div class='col-md-3 mb-3'><label class='form-label'>Atenção a partir de</label><input type='number' class='form-control' name='yellow_min' min='1' max='99' value='" . $e($cfg['yellow_min']) . "'><div class='form-hint'>Abaixo disso a área é crítica.</div></div>";
echo "<div class='col-md-3 mb-3'><label class='form-label'>Recalcular o mês corrente a cada (min)</label><input type='number' class='form-control' name='refresh_min' min='1' max='1440' value='" . $e($cfg['refresh_min']) . "'></div>";
echo "</div><div class='row'><div class='col-md-4 mb-3'><label class='form-label'>Entidade dos chamados das auditorias</label>";
Entity::dropdown(['name' => 'ticket_entity', 'value' => (int) $cfg['ticket_entity'], 'display' => true]);
echo "</div><div class='col-md-3 mb-3'><label class='form-label'>Tipo do chamado</label><select class='form-select' name='ticket_type'>"
    . "<option value='2'" . ((int) $cfg['ticket_type'] === 2 ? ' selected' : '') . ">Requisição</option><option value='1'" . ((int) $cfg['ticket_type'] === 1 ? ' selected' : '') . ">Incidente</option></select></div></div>";
echo "</div><div class='card-footer text-end'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button></div></form>";

echo "<form method='post' class='card'>" . Menu::csrf() . "<div class='card-header'><h3 class='card-title'>Histórico dos indicadores</h3></div><div class='card-body'>"
    . "<p class='text-secondary'>O plugin guarda uma foto dos indicadores de cada mês. Recalcular refaz as fotos a partir dos dados atuais dos plugins (os indicadores de situação atual, como pendências e atrasos abertos, só valem para o mês corrente).</p>"
    . "<div class='row'><div class='col-md-3'><label class='form-label'>Meses para trás</label><input type='number' class='form-control' name='months' min='1' max='36' value='12'></div></div>"
    . "</div><div class='card-footer text-end'><button class='btn btn-outline-secondary' name='recalc' value='1'><i class='ti ti-refresh me-1'></i>Recalcular</button></div></form>";
echo "</div>";
Html::footer();
