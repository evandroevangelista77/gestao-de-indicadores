<?php

/**
 * KPIs por área, debaixo da Superintendência: a planilha mensal de cada área (peso, meta, frequência e o
 * realizado de cada mês, com atingimento e resultado ponderado) e o consolidado da superintendência.
 * A planilha do painel (Meta 2026, Peso 2026, Frequência) é a origem do catálogo: "Importar planilha".
 */

use GlpiPlugin\Indicadores\Area;
use GlpiPlugin\Indicadores\Kpi;
use GlpiPlugin\Indicadores\KpiArea;
use GlpiPlugin\Indicadores\KpiImport;
use GlpiPlugin\Indicadores\KpiMotor;
use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Periodo;
use GlpiPlugin\Indicadores\Score;

Session::checkRight(Menu::$rightname, READ);

global $DB;
$canEdit = Session::haveRight(Menu::$rightname, UPDATE);
$e = fn ($v) => Menu::e($v);
$ano = (int) ($_GET['ano'] ?? $_POST['ano'] ?? date('Y'));
$ano = max((int) date('Y') - 3, min((int) date('Y') + 1, $ano ?: (int) date('Y')));
$areaId = (int) ($_GET['area'] ?? $_POST['area'] ?? 0);
$self = fn (array $q = []) => Menu::url('/front/kpi.php?' . http_build_query($q + ['ano' => $ano, 'area' => $areaId]));

// ---------------------------------------------------------------- ações
if ($canEdit && isset($_POST['import'])) {
    $f = $_FILES['xlsx'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'xlsx' || $f['size'] > 5 * 1024 * 1024) {
        Session::addMessageAfterRedirect('Envie um arquivo .xlsx de até 5 MB.', false, ERROR);
    } else {
        try {
            $r = KpiImport::arquivo($f['tmp_name']);
            Session::addMessageAfterRedirect("Planilha importada: {$r['areas']} área(s), {$r['criados']} KPI(s) novo(s) e {$r['atualizados']} atualizado(s).");
        } catch (Throwable $ex) {
            Session::addMessageAfterRedirect('Não foi possível ler a planilha: ' . $ex->getMessage(), false, ERROR);
        }
    }
    Html::redirect($self());
}
if ($canEdit && isset($_POST['salvar_valores'])) {
    $n = 0;
    foreach ((array) ($_POST['v'] ?? []) as $kid => $meses) {
        $kpi = new Kpi();
        if (!$kpi->getFromDB((int) $kid) || (int) $kpi->fields['plugin_indicadores_kpiareas_id'] !== $areaId) {
            continue;
        }
        $atuais = KpiMotor::valores([(int) $kid], array_keys((array) $meses));
        foreach ((array) $meses as $ym => $raw) {
            if (!Periodo::mesValido($ym) || !KpiMotor::aplica($kpi->fields, (int) substr($ym, 5, 2))) {
                continue;
            }
            $novo = KpiMotor::numero($raw);
            $antigo = $atuais[(int) $kid][$ym] ?? null;
            // a tela reenvia todas as células (inclusive as automáticas): só grava o que foi alterado
            if (($novo === null && $antigo === null) || ($novo !== null && $antigo !== null && abs($novo - $antigo) < 0.00005)) {
                continue;
            }
            if ($novo === null) {
                // célula apagada: volta ao automático (se o KPI tiver fonte) ou fica sem valor
                $DB->delete(KpiMotor::VALORES, ['plugin_indicadores_kpis_id' => (int) $kid, 'period' => $ym]);
            } else {
                KpiMotor::salvar((int) $kid, $ym, $novo, 'manual');
            }
            $n++;
        }
    }
    \GlpiPlugin\Indicadores\KpiColeta::coletar(date('Y-m'), $areaId); // células apagadas voltam ao automático
    \GlpiPlugin\Indicadores\Motor::garantir(date('Y-m'), true);
    Session::addMessageAfterRedirect($n ? "$n valor(es) salvo(s). O painel executivo já reflete os lançamentos." : 'Nenhuma alteração para salvar.');
    Html::redirect($self());
}
if ($canEdit && isset($_POST['kpi_salvar'])) {
    $input = ['name' => $_POST['name'] ?? '', 'meta' => $_POST['meta'] ?? '', 'peso' => $_POST['peso'] ?? 0, 'freq' => $_POST['freq'] ?? 'mensal',
        'dir' => $_POST['dir'] ?? 'up', 'unit' => $_POST['unit'] ?? '%', 'is_active' => $_POST['is_active'] ?? 0, 'note' => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255)];
    $kpi = new Kpi();
    if (!empty($_POST['kpi_id'])) {
        if ($kpi->getFromDB((int) $_POST['kpi_id']) && (int) $kpi->fields['plugin_indicadores_kpiareas_id'] === $areaId) {
            $kpi->update(['id' => $kpi->getID()] + $input);
            Session::addMessageAfterRedirect('KPI salvo.');
        }
    } elseif ($areaId && $kpi->add($input + ['plugin_indicadores_kpiareas_id' => $areaId])) {
        Session::addMessageAfterRedirect('KPI criado.');
    }
    Html::redirect($self());
}
if ($canEdit && isset($_POST['kpi_excluir'])) {
    $kpi = new Kpi();
    if ($kpi->getFromDB((int) ($_POST['kpi_id'] ?? 0)) && (int) $kpi->fields['plugin_indicadores_kpiareas_id'] === $areaId) {
        $DB->delete(KpiMotor::VALORES, ['plugin_indicadores_kpis_id' => $kpi->getID()]);
        $kpi->delete(['id' => $kpi->getID()], true);
        Session::addMessageAfterRedirect('KPI removido.');
    }
    Html::redirect($self());
}

// ---------------------------------------------------------------- dados
$raiz = KpiMotor::raiz();
$areas = KpiMotor::areas();
$metaRaiz = (float) ($raiz['meta'] ?? 90);
$d = KpiMotor::ano($ano);
$hoje = date('Y-m');
$nomeRaiz = $raiz['name'] ?? 'Superintendência Operacional';

$anos = '';
for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 3; $y--) {
    $anos .= "<option value='$y'" . ($y === $ano ? ' selected' : '') . ">$y</option>";
}
$acoes = "<form method='get' class='d-flex gap-2 align-items-end m-0' action='" . $e(Menu::url('/front/kpi.php')) . "'><input type='hidden' name='area' value='" . $areaId . "'>"
    . "<div><label class='form-label small mb-0'>Ano</label><select class='form-select form-select-sm' name='ano' onchange='this.form.submit()'>$anos</select></div></form>"
    . Menu::button('Painel executivo', Menu::url('/front/painel.php'), 'ti-layout-dashboard', 'btn-outline-secondary');

Menu::header('KPIs');
echo "<div class='ind'>";
echo Menu::title('KPIs · ' . $nomeRaiz, 'KPIs de cada área debaixo da superintendência: meta, peso e frequência vêm da planilha do painel; o realizado é lançado mês a mês.', $acoes);

// ---------------------------------------------------------------- abas
$aba = fn (int $id, string $nome, string $icone) => "<a class='ind-tab" . ($areaId === $id ? ' on' : '') . "' href='" . $e($self(['area' => $id])) . "'><i class='ti $icone'></i>" . $e($nome) . "</a>";
echo "<div class='ind-tabs'>" . $aba(0, 'Consolidado', 'ti-layout-dashboard');
foreach ($areas as $a) {
    echo $aba((int) $a['id'], $a['name'], 'ti-target-arrow');
}
echo "</div>";

if (!$areas) {
    echo "<div class='card'><div class='card-body text-center py-5'><div class='text-secondary mb-3'>Nenhuma área de KPIs ainda. Importe a planilha do painel da superintendência para criar as áreas e os KPIs.</div></div></div>";
}

$cell = function (?float $r, string $cls = '') use ($e): string {
    return "<td class='ind-kcell $cls'>" . ($r === null ? '—' : $e(number_format($r, 1, ',', '.'))) . "</td>";
};

// ---------------------------------------------------------------- consolidado
if ($areaId === 0 && $areas) {
    $cons = $d['consolidado'];
    $acum = KpiMotor::acumulado($cons);
    $atual = $d['meses'][min(11, $ano === (int) date('Y') ? (int) date('n') - 1 : 11)];
    $ultimo = null;
    foreach ($d['meses'] as $ym) {
        if ($cons[$ym] !== null) {
            $ultimo = $ym;
        }
    }
    $st = KpiMotor::statusResultado($ultimo ? $cons[$ultimo] : null, $metaRaiz);
    echo "<div class='ind-summary' style='grid-template-columns: minmax(300px,1.5fr) repeat(2, minmax(200px,1fr))'>";
    echo "<div class='ind-hero ind-" . $e($st) . "'><div><div class='ind-hero-label'>Consolidado" . ($ultimo ? ' · ' . $e(Periodo::mes($ultimo)) : '') . "</div>"
        . "<div class='ind-hero-value'>" . ($ultimo ? $e(number_format($cons[$ultimo], 1, ',', '.')) : '—') . "<small>% · meta " . $e(number_format($metaRaiz, 0, ',', '.')) . "%</small></div>"
        . "<span class='ind-pill ind-" . $e($st) . "'>" . $e(Score::label($st)) . "</span></div>"
        . "<div class='ind-hero-spark' data-serie='" . $e(json_encode(array_values($cons))) . "' data-meses='" . $e(json_encode(array_map([Periodo::class, 'mes'], $d['meses']))) . "' data-min='0' data-max='100' data-cor='" . ['ok' => '#22c55e', 'warn' => '#f59e0b', 'bad' => '#ef4444', 'none' => '#94a3b8'][$st] . "'></div></div>";
    $nKpis = array_sum(array_map('count', $d['kpis']));
    echo "<div class='ind-stat ind-" . $e(KpiMotor::statusResultado($acum, $metaRaiz)) . "'><i class='ti ti-calendar-stats ind-stat-ico'></i><div class='ind-stat-main'><span class='ind-stat-label'>Acumulado do ano</span><div class='ind-stat-num'>" . ($acum === null ? '—' : $e(number_format($acum, 1, ',', '.'))) . "<small>%</small></div><div class='ind-stat-desc'>Média dos resultados mensais já apurados em " . $ano . ".</div></div></div>";
    echo "<div class='ind-stat ind-none'><i class='ti ti-list-check ind-stat-ico'></i><div class='ind-stat-main'><span class='ind-stat-label'>KPIs acompanhados</span><div class='ind-stat-num'>$nKpis<small>em " . count($areas) . " áreas</small></div><div class='ind-stat-desc'>Pesos e metas de 2026 vindos da planilha do painel.</div></div></div>";
    echo "</div>";

    echo "<div class='card ind-gridcard'><div class='table-responsive'><table class='ind-ktable'><thead><tr><th class='ind-kname'>Área</th>";
    for ($m = 1; $m <= 12; $m++) {
        echo "<th>" . $e(Periodo::mesCurto($m)) . "</th>";
    }
    echo "<th>Acum.</th></tr></thead><tbody>";
    foreach ($areas as $a) {
        $res = $d['resultados'][(int) $a['id']];
        echo "<tr><td class='ind-kname'><a href='" . $e($self(['area' => (int) $a['id']])) . "'><b>" . $e($a['name']) . "</b></a><small>" . count($d['kpis'][(int) $a['id']]) . " KPIs</small></td>";
        foreach ($d['meses'] as $ym) {
            echo $cell($res[$ym], 'ind-' . KpiMotor::statusResultado($res[$ym], $metaRaiz));
        }
        $ac = KpiMotor::acumulado($res);
        echo $cell($ac, 'ind-total ind-' . KpiMotor::statusResultado($ac, $metaRaiz)) . "</tr>";
    }
    echo "<tr class='ind-ktotal'><td class='ind-kname'><b>" . $e($nomeRaiz) . "</b><small>Consolidação das áreas · meta " . $e(number_format($metaRaiz, 0, ',', '.')) . "%</small></td>";
    foreach ($d['meses'] as $ym) {
        echo $cell($cons[$ym], 'ind-' . KpiMotor::statusResultado($cons[$ym], $metaRaiz));
    }
    echo $cell($acum, 'ind-total ind-' . KpiMotor::statusResultado($acum, $metaRaiz)) . "</tr></tbody></table></div>";
    echo "<div class='ind-legend'><span><i class='ind-dot ind-ok'></i> Na meta (≥ " . (int) $metaRaiz . "%)</span><span><i class='ind-dot ind-warn'></i> Até 10 pontos abaixo</span><span><i class='ind-dot ind-bad'></i> Abaixo</span>"
        . "<span class='ms-auto text-secondary'>Resultado da área = média dos atingimentos dos KPIs ponderada pelo peso. Consolidado = média simples das áreas.</span></div></div>";
}

// ---------------------------------------------------------------- planilha de uma área
$area = null;
foreach ($areas as $a) {
    if ((int) $a['id'] === $areaId) {
        $area = $a;
    }
}
if ($area) {
    $kpis = KpiMotor::kpis($areaId, false);
    $valores = $d['valores'];
    $origens = KpiMotor::origens(array_map('intval', array_column($kpis, 'id')), $d['meses']);
    $res = $d['resultados'][$areaId] ?? [];
    $pesoTotal = array_sum(array_map(fn ($k) => $k['is_active'] ? (int) $k['peso'] : 0, $kpis));
    $acum = KpiMotor::acumulado($res);
    echo "<form method='post' class='card ind-gridcard'>" . Menu::csrf() . "<input type='hidden' name='ano' value='$ano'><input type='hidden' name='area' value='$areaId'>";
    echo "<div class='card-header'><h3 class='card-title'>" . $e($area['name']) . "</h3><div class='ms-auto small text-secondary'>" . (Session::haveRight('config', UPDATE) ? "<a class='me-3' href='" . $e(Menu::url('/front/config.php#kpi-' . $areaId)) . "'><i class='ti ti-bolt'></i>Configurar coleta</a>" : '') . "Peso total ativo: <b>$pesoTotal</b> · acumulado: <b>" . ($acum === null ? '—' : $e(number_format($acum, 1, ',', '.')) . '%') . "</b></div></div>";
    echo "<div class='table-responsive'><table class='ind-ktable'><thead><tr><th class='ind-kname'>KPI</th><th>Peso</th><th>Meta</th><th>Freq.</th>";
    for ($m = 1; $m <= 12; $m++) {
        echo "<th>" . $e(Periodo::mesCurto($m)) . "</th>";
    }
    echo "<th></th></tr></thead><tbody>";
    foreach ($kpis as $k) {
        $kid = (int) $k['id'];
        $dir = $k['dir'] === 'down' ? '↓ menor é melhor' : '↑ maior é melhor';
        $fonteTxt = !empty($k['source']) ? ' · <i class="ti ti-bolt"></i> ' . $e(\GlpiPlugin\Indicadores\Fontes::rotulo($k['source'])) : '';
        echo "<tr" . (!$k['is_active'] ? " class='text-secondary'" : '') . "><td class='ind-kname'><b>" . $e($k['name']) . "</b><small>" . $e($dir) . ($k['peso'] ? '' : ' · acompanhamento (peso 0)') . $fonteTxt . "</small></td>"
            . "<td class='ind-kmeta'>" . (int) $k['peso'] . "</td><td class='ind-kmeta'>" . ($k['meta'] === null ? '—' : $e(rtrim(rtrim(number_format((float) $k['meta'], 2, ',', ''), '0'), ',')) . ($k['unit'] === '%' ? '%' : '')) . "</td>"
            . "<td class='ind-kmeta'><small>" . $e(Kpi::FREQUENCIAS[$k['freq']] ?? $k['freq']) . "</small></td>";
        foreach ($d['meses'] as $ym) {
            $mes = (int) substr($ym, 5, 2);
            if (!KpiMotor::aplica($k, $mes)) {
                echo "<td class='ind-kcell ind-off' title='Este KPI é apurado " . $e(strtolower(Kpi::FREQUENCIAS[$k['freq']])) . "'>·</td>";
                continue;
            }
            $v = $valores[$kid][$ym] ?? null;
            $auto = $v !== null && ($origens[$kid][$ym] ?? 'manual') === 'auto';
            $at = KpiMotor::atingimento($k, $v);
            $txt = $v === null ? '' : rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
            if ($canEdit && $ym <= $hoje) {
                echo "<td class='ind-kcell ind-" . $e(KpiMotor::status($at)) . ($auto ? ' ind-auto' : '') . "' title='" . ($at === null ? 'Sem lançamento' : ($auto ? 'Coletado automaticamente · ' : '') . 'Atingimento ' . $e(number_format($at, 1, ',', '.')) . '%') . "'><input name='v[$kid][$ym]' value='" . $e($txt) . "' inputmode='decimal'></td>";
            } else {
                echo "<td class='ind-kcell ind-" . $e(KpiMotor::status($at)) . ($auto ? ' ind-auto' : '') . "' title='" . ($at === null ? 'Sem lançamento' : ($auto ? 'Coletado automaticamente · ' : '') . 'Atingimento ' . $e(number_format($at, 1, ',', '.')) . '%') . "'>" . ($txt === '' ? '—' : $e($txt)) . "</td>";
            }
        }
        echo "<td class='text-end'>" . ($canEdit ? "<a class='btn btn-sm btn-ghost-secondary' href='" . $e($self(['kpi' => $kid])) . "#editar' title='Editar'><i class='ti ti-pencil'></i></a>" : '') . "</td></tr>";
    }
    echo "<tr class='ind-ktotal'><td class='ind-kname'><b>Resultado ponderado</b><small>Atingimento médio, ponderado pelo peso · meta " . $e(number_format($metaRaiz, 0, ',', '.')) . "%</small></td><td></td><td></td><td></td>";
    foreach ($d['meses'] as $ym) {
        echo $cell($res[$ym] ?? null, 'ind-' . KpiMotor::statusResultado($res[$ym] ?? null, $metaRaiz));
    }
    echo "<td></td></tr></tbody></table></div>";
    echo "<div class='card-footer d-flex flex-wrap align-items-center gap-3'><div class='ind-legend p-0 flex-grow-1'><span><i class='ind-dot ind-ok'></i> Atingimento ≥ 100%</span><span><i class='ind-dot ind-warn'></i> 90 a 99%</span><span><i class='ind-dot ind-bad'></i> Abaixo de 90%</span>"
        . "<span><i class='ti ti-bolt'></i> Valor coletado automaticamente</span><span class='text-secondary'>Quadrimestral: abril, agosto e dezembro · Anual: dezembro</span></div>"
        . ($canEdit ? "<button class='btn btn-primary' name='salvar_valores' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar valores</button>" : '') . "</div></form>";

    if ($canEdit) {
        $edit = null;
        foreach ($kpis as $k) {
            if (isset($_GET['kpi']) && (int) $_GET['kpi'] === (int) $k['id']) {
                $edit = $k;
            }
        }
        $opt = function (array $list, string $cur) use ($e): string {
            $h = '';
            foreach ($list as $key => $label) {
                $h .= "<option value='" . $e($key) . "'" . ((string) $key === $cur ? ' selected' : '') . '>' . $e($label) . '</option>';
            }
            return $h;
        };
        $v = fn (string $key, $def = '') => $e($edit[$key] ?? $def);
        echo "<form method='post' class='card mt-3' id='editar'>" . Menu::csrf() . "<input type='hidden' name='ano' value='$ano'><input type='hidden' name='area' value='$areaId'>" . ($edit ? "<input type='hidden' name='kpi_id' value='" . (int) $edit['id'] . "'>" : '')
            . "<div class='card-header'><h3 class='card-title'>" . ($edit ? 'Editar KPI' : 'Novo KPI nesta área') . "</h3></div><div class='card-body'><div class='row'>"
            . "<div class='col-md-6 mb-3'><label class='form-label'>Nome do KPI</label><input class='form-control' name='name' maxlength='255' required value='" . $v('name') . "'></div>"
            . "<div class='col-md-2 mb-3'><label class='form-label'>Meta</label><input class='form-control' name='meta' value='" . $e($edit && $edit['meta'] !== null ? rtrim(rtrim(number_format((float) $edit['meta'], 2, ',', ''), '0'), ',') : '') . "'></div>"
            . "<div class='col-md-2 mb-3'><label class='form-label'>Peso</label><input type='number' class='form-control' name='peso' min='0' max='100' value='" . $v('peso', '0') . "'></div>"
            . "<div class='col-md-2 mb-3'><label class='form-label'>Frequência</label><select class='form-select' name='freq'>" . $opt(Kpi::FREQUENCIAS, (string) ($edit['freq'] ?? 'mensal')) . "</select></div>"
            . "<div class='col-md-3 mb-3'><label class='form-label'>Direção</label><select class='form-select' name='dir'>" . $opt(['up' => 'Maior é melhor', 'down' => 'Menor é melhor'], (string) ($edit['dir'] ?? 'up')) . "</select></div>"
            . "<div class='col-md-3 mb-3'><label class='form-label'>Unidade</label><select class='form-select' name='unit'>" . $opt(Area::UNIDADES, (string) ($edit['unit'] ?? '%')) . "</select></div>"
            . "<div class='col-md-4 mb-3'><label class='form-label'>Observação</label><input class='form-control' name='note' maxlength='255' value='" . $v('note') . "'></div>"
            . "<div class='col-md-2 mb-3 d-flex align-items-end'><label class='form-check mb-2'><input class='form-check-input' type='checkbox' name='is_active' value='1'" . (($edit['is_active'] ?? 1) ? ' checked' : '') . "><span class='form-check-label'>Ativo</span></label></div>"
            . "</div></div><div class='card-footer d-flex justify-content-between'><div>"
            . ($edit ? "<button class='btn btn-outline-danger' name='kpi_excluir' value='1' onclick=\"return confirm('Remover este KPI e os valores lançados?')\"><i class='ti ti-trash me-1'></i>Remover</button>" : '')
            . "</div><div>" . ($edit ? "<a class='btn btn-link' href='" . $e($self()) . "'>Cancelar</a> " : '')
            . "<button class='btn btn-primary' name='kpi_salvar' value='1'><i class='ti ti-device-floppy me-1'></i>Salvar KPI</button></div></div></form>";
    }
}

// ---------------------------------------------------------------- importação
if ($canEdit) {
    echo "<form method='post' enctype='multipart/form-data' class='card mt-3'>" . Menu::csrf() . "<input type='hidden' name='ano' value='$ano'><input type='hidden' name='area' value='$areaId'>"
        . "<div class='card-header'><h3 class='card-title'>Importar a planilha do painel</h3></div><div class='card-body'>"
        . "<p class='text-secondary small'>Uma aba por área, com as colunas <b>Nome</b>, <b>Nome Indicador</b>, <b>Meta</b>, <b>Peso</b>, <b>Frequência</b> e <b>Observação</b>. A primeira aba é a própria superintendência (a meta do consolidado). "
        . "Pode importar de novo a qualquer momento: KPIs existentes (mesma área e nome) são atualizados e os novos são criados; os valores já lançados são mantidos.</p>"
        . "<input type='file' class='form-control' name='xlsx' accept='.xlsx' required></div>"
        . "<div class='card-footer text-end'><button class='btn btn-outline-primary' name='import' value='1'><i class='ti ti-file-import me-1'></i>Importar planilha</button></div></form>";
}
echo "</div>";
echo Menu::script('painel.js');
Html::footer();
