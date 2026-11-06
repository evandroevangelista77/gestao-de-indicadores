<?php

/** Configuração: faixas de nota das áreas, chamados das auditorias e atualização do mês corrente. */

use GlpiPlugin\Indicadores\Fontes;
use GlpiPlugin\Indicadores\Kpi;
use GlpiPlugin\Indicadores\KpiArea;
use GlpiPlugin\Indicadores\KpiColeta;
use GlpiPlugin\Indicadores\KpiMotor;
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


// ---------------------------------------------------------------- coleta automática dos KPIs
global $DB;
if (isset($_POST['kpi_area_save'])) {
    $areaId = (int) ($_POST['area_id'] ?? 0);
    $area = new KpiArea();
    if ($area->getFromDB($areaId) && $area->fields['kind'] === 'area') {
        $DB->update(KpiArea::getTable(), [
            'auto'              => empty($_POST['auto']) ? 0 : 1,
            'entities_id'       => empty($_POST['scope_on']) ? null : max(0, (int) ($_POST['entities_id'] ?? 0)),
            'groups_id'         => max(0, (int) ($_POST['groups_id'] ?? 0)),
            'itilcategories_id' => max(0, (int) ($_POST['itilcategories_id'] ?? 0)),
        ], ['id' => $areaId]);
        foreach (KpiMotor::kpis($areaId, false) as $k) {
            $fonte = (string) ($_POST['src'][$k['id']] ?? '');
            $fonte = isset(Fontes::CATALOGO[$fonte]) ? $fonte : null;
            $param = $fonte ? mb_substr(trim((string) ($_POST['prm'][$k['id']] ?? '')), 0, 120) : '';
            $DB->update(Kpi::getTable(), ['source' => $fonte, 'source_param' => $param !== '' ? $param : null], ['id' => $k['id']]);
        }
        Session::addMessageAfterRedirect('Coleta da área salva.');
        if (!empty($_POST['coletar_agora'])) {
            $r = KpiColeta::coletarMeses(12, $areaId);
            Session::addMessageAfterRedirect("Coleta feita: {$r['preenchidos']} valor(es) preenchido(s), {$r['manuais']} mantido(s) por já terem lançamento manual, {$r['sem_dado']} sem dados no período.");
        }
    }
    Html::redirect($self . '#kpi-' . $areaId);
}
if (isset($_POST['kpi_sugerir'])) {
    $n = KpiColeta::sugerir();
    Session::addMessageAfterRedirect("$n KPI(s) vinculado(s) a uma fonte pelo nome. Confira abaixo e ajuste o que precisar.");
    Html::redirect($self);
}
if (isset($_POST['kpi_coletar_tudo'])) {
    $r = KpiColeta::coletarMeses(12);
    Session::addMessageAfterRedirect("Coleta de todas as áreas: {$r['preenchidos']} valor(es) preenchido(s), {$r['manuais']} mantido(s) (manuais), {$r['sem_dado']} sem dados.");
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

// ---------------------------------------------------------------- coleta automática dos KPIs por área
$areasKpi = KpiMotor::areas(false);
$fases = Fontes::fasesCiclo();
echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>Coleta automática dos KPIs por área</h3></div><div class='card-body'>"
    . "<p class='text-secondary'>Os KPIs de cada área podem ser preenchidos sozinhos a partir dos chamados, SLAs, satisfação, projetos e Ciclo Financeiro do GLPI. Para cada área defina o <b>escopo dos chamados</b> (entidade, grupo e categoria) e, para cada KPI, a <b>fonte</b>. A coleta roda a cada 6 horas (mês corrente e anterior) e também por botão. "
    . "<b>Valor lançado à mão tem prioridade</b>: a coleta nunca o sobrescreve; apague o valor na planilha para voltar ao automático.</p>"
    . "<div class='d-flex flex-wrap gap-2'><form method='post' class='m-0'>" . Menu::csrf() . "<button class='btn btn-outline-primary' name='kpi_sugerir' value='1'><i class='ti ti-wand me-1'></i>Sugerir fontes pelo nome dos KPIs</button></form>"
    . "<form method='post' class='m-0'>" . Menu::csrf() . "<button class='btn btn-outline-secondary' name='kpi_coletar_tudo' value='1'><i class='ti ti-refresh me-1'></i>Coletar todas as áreas agora (12 meses)</button></form></div></div></div>";
if (!$areasKpi) {
    echo "<div class='card mt-3'><div class='card-body text-center text-secondary py-4'>Nenhuma área de KPIs ainda. Importe a planilha do painel em <b>Indicadores → KPIs</b>.</div></div>";
}
$fonteOpts = "<option value=''>Manual (sem coleta)</option>";
foreach (Fontes::CATALOGO as $code => [$rotulo, $desc]) {
    $fonteOpts .= "<option value='" . $e($code) . "'" . (!Fontes::disponivel($code) ? ' disabled' : '') . ">" . $e($rotulo) . (!Fontes::disponivel($code) ? ' (plugin não instalado)' : '') . "</option>";
}
echo "<datalist id='ind-fases'>";
foreach ($fases as $code => $nome) {
    echo "<option value='" . $e($code) . "'>" . $e($nome) . "</option>";
}
echo "</datalist>";
$catalogoJs = [];
foreach (Fontes::CATALOGO as $code => [$rotulo, $desc, $plabel, $pdica]) {
    $catalogoJs[$code] = ['desc' => $desc, 'plabel' => $plabel, 'dica' => $pdica];
}
foreach ($areasKpi as $a) {
    $aid = (int) $a['id'];
    $kpis = KpiMotor::kpis($aid, false);
    $comFonte = count(array_filter($kpis, fn ($k) => !empty($k['source'])));
    echo "<form method='post' class='card mt-3' id='kpi-$aid'>" . Menu::csrf() . "<input type='hidden' name='area_id' value='$aid'>"
        . "<div class='card-header'><h3 class='card-title'>" . $e($a['name']) . "</h3><div class='ms-auto small text-secondary'>$comFonte de " . count($kpis) . " KPIs com coleta"
        . ($a['date_collect'] ? ' · última coleta ' . $e(Html::convDateTime($a['date_collect'])) : '') . "</div></div><div class='card-body'>"
        . "<div class='row'><div class='col-md-3 mb-3'><label class='form-check mb-2'><input class='form-check-input' type='checkbox' name='auto' value='1'" . ((int) $a['auto'] ? ' checked' : '') . "><span class='form-check-label'>Coletar esta área automaticamente</span></label>"
        . "<label class='form-check mb-0'><input class='form-check-input' type='checkbox' name='scope_on' value='1'" . ($a['entities_id'] !== null ? ' checked' : '') . "><span class='form-check-label'>Usar chamados do GLPI com este escopo</span></label></div>"
        . "<div class='col-md-3 mb-3'><label class='form-label'>Entidade (com as filhas)</label>";
    Entity::dropdown(['name' => 'entities_id', 'value' => (int) ($a['entities_id'] ?? 0), 'display' => true, 'rand' => $aid]);
    echo "</div><div class='col-md-3 mb-3'><label class='form-label'>Grupo atribuído (opcional)</label>";
    Group::dropdown(['name' => 'groups_id', 'value' => (int) $a['groups_id'], 'display' => true, 'rand' => $aid + 1000]);
    echo "</div><div class='col-md-3 mb-3'><label class='form-label'>Categoria (opcional)</label>";
    ITILCategory::dropdown(['name' => 'itilcategories_id', 'value' => (int) $a['itilcategories_id'], 'display' => true, 'rand' => $aid + 2000]);
    echo "</div></div>";
    echo "<div class='table-responsive'><table class='table table-sm align-middle mb-0'><thead><tr><th>KPI</th><th style='width:30%'>Fonte</th><th style='width:24%'>Parâmetro</th></tr></thead><tbody>";
    foreach ($kpis as $k) {
        $kid = (int) $k['id'];
        $sel = str_replace("<option value='" . $e((string) $k['source']) . "'", "<option value='" . $e((string) $k['source']) . "' selected", $fonteOpts);
        echo "<tr" . (!$k['is_active'] ? " class='text-secondary'" : '') . "><td><b class='small'>" . $e($k['name']) . "</b><div class='small text-secondary'>peso " . (int) $k['peso'] . ' · ' . $e(Kpi::FREQUENCIAS[$k['freq']] ?? '') . "</div></td>"
            . "<td><select class='form-select form-select-sm ind-src' name='src[$kid]' data-kpi='$kid'>$sel</select><div class='small text-secondary ind-src-desc' id='desc$kid'></div></td>"
            . "<td><input class='form-control form-control-sm ind-prm' name='prm[$kid]' data-kpi='$kid' value='" . $e((string) $k['source_param']) . "'><div class='small text-secondary' id='dica$kid'></div></td></tr>";
    }
    echo "</tbody></table></div></div><div class='card-footer d-flex justify-content-end gap-2'>"
        . "<button class='btn btn-outline-secondary' name='kpi_area_save' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar</button>"
        . "<button class='btn btn-primary' name='kpi_area_save' value='1' onclick=\"this.form.insertAdjacentHTML('beforeend','<input type=hidden name=coletar_agora value=1>')\"><i class='ti ti-player-play me-1'></i>Salvar e coletar agora</button></div></form>";
}
echo "<script>(() => { const cat = " . json_encode($catalogoJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ";
const upd = (sel) => { const k = sel.dataset.kpi, c = cat[sel.value], p = document.querySelector('.ind-prm[data-kpi=\"' + k + '\"]');
  document.getElementById('desc' + k).textContent = c ? c.desc : 'O valor é lançado à mão na planilha de KPIs.';
  p.disabled = !c || !c.plabel; p.placeholder = c && c.plabel ? c.plabel : ''; document.getElementById('dica' + k).textContent = c && c.plabel ? c.dica : '';
  if (sel.value === 'ciclo_fase') p.setAttribute('list', 'ind-fases'); else p.removeAttribute('list'); };
document.querySelectorAll('.ind-src').forEach((s) => { upd(s); s.addEventListener('change', () => upd(s)); }); })();</script>";
echo "</div>";
Html::footer();
