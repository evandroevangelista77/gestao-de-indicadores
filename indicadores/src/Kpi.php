<?php

namespace GlpiPlugin\Indicadores;

use CommonDBTM;
use Session;

/** KPI de uma área: nome, meta, peso na nota da área, frequência de apuração e direção (maior ou menor é melhor). */
class Kpi extends CommonDBTM
{
    public static string $rightname = 'plugin_indicadores';

    public const FREQUENCIAS = ['mensal' => 'Mensal', 'quadrimestral' => 'Quadrimestral', 'anual' => 'Anual'];

    /** Meses em que cada frequência é apurada (quadrimestral: abril, agosto e dezembro; anual: dezembro). */
    public const MESES = ['mensal' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 'quadrimestral' => [4, 8, 12], 'anual' => [12]];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'KPIs' : 'KPI';
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->limpar($input);
        if ($input === false) {
            return false;
        }
        $input['position'] = $input['position'] ?? ((int) countElementsInTable(self::getTable(), ['plugin_indicadores_kpiareas_id' => (int) ($input['plugin_indicadores_kpiareas_id'] ?? 0)]) + 1) * 10;
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->limpar($input);
        return $input === false ? false : parent::prepareInputForUpdate($input);
    }

    private function limpar(array $input)
    {
        if (isset($input['name'])) {
            $input['name'] = mb_substr(trim((string) $input['name']), 0, 255);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(__('Informe o nome do KPI.'), false, ERROR);
                return false;
            }
        }
        if (isset($input['freq']) && !isset(self::FREQUENCIAS[$input['freq']])) {
            $input['freq'] = 'mensal';
        }
        if (isset($input['dir']) && !in_array($input['dir'], ['up', 'down'], true)) {
            $input['dir'] = 'up';
        }
        if (isset($input['unit']) && !isset(Area::UNIDADES[$input['unit']])) {
            $input['unit'] = '%';
        }
        foreach (['meta'] as $f) {
            if (array_key_exists($f, $input)) {
                $v = KpiMotor::numero($input[$f]);
                $input[$f] = $v;
            }
        }
        if (isset($input['peso'])) {
            $input['peso'] = max(0, min(100, (int) $input['peso']));
        }
        if (array_key_exists('is_active', $input)) {
            $input['is_active'] = empty($input['is_active']) ? 0 : 1;
        }
        return $input;
    }
}
