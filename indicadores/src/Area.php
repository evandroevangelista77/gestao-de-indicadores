<?php

namespace GlpiPlugin\Indicadores;

use CommonDBTM;
use GlpiPlugin\Indicadores\Coletor\Personalizada;
use Session;

/**
 * Área personalizada do painel: o usuário inclui uma área além das nove que vêm com o plugin.
 * Tipo "manual" (indicadores e valores lançados na tela) ou "chamados" (indicadores de atendimento
 * calculados a partir dos chamados de uma entidade, grupo ou categoria).
 */
class Area extends CommonDBTM
{
    public static string $rightname = 'plugin_indicadores';

    public const KPIS = 'glpi_plugin_indicadores_areakpis';

    public const TIPOS = ['manual' => 'Manual (lanço os valores de cada mês)', 'chamados' => 'Chamados do GLPI (calculado sozinho)'];

    public const ICONES = [
        'ti ti-chart-bar' => 'Gráfico', 'ti ti-building' => 'Prédio', 'ti ti-briefcase' => 'Pasta', 'ti ti-users' => 'Pessoas', 'ti ti-shield-check' => 'Segurança',
        'ti ti-truck' => 'Logística', 'ti ti-headset' => 'Atendimento', 'ti ti-coin' => 'Financeiro', 'ti ti-receipt' => 'Recibo', 'ti ti-settings' => 'Engrenagem',
        'ti ti-cloud' => 'Nuvem', 'ti ti-server' => 'Servidor', 'ti ti-school' => 'Treinamento', 'ti ti-heart' => 'Coração', 'ti ti-rocket' => 'Foguete', 'ti ti-flag' => 'Bandeira',
    ];

    public const UNIDADES = ['%' => '% (percentual)', 'n' => 'n (quantidade)', 'd' => 'd (dias)', 'h' => 'h (horas)', 'R$' => 'R$ (valor)', 'nota' => 'nota (média)'];

    public const DIRECOES = ['up' => 'Quanto maior, melhor', 'down' => 'Quanto menor, melhor', 'info' => 'Apenas informativo'];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Áreas' : 'Área';
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->limpar($input);
        if ($input === false) {
            return false;
        }
        $input['position'] = (int) countElementsInTable(self::getTable()) * 10 + 100;
        $input['date_creation'] = date('Y-m-d H:i:s');
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->limpar($input);
        if ($input === false) {
            return false;
        }
        $input['date_mod'] = date('Y-m-d H:i:s');
        return parent::prepareInputForUpdate($input);
    }

    private function limpar(array $input)
    {
        if (isset($input['name'])) {
            $input['name'] = mb_substr(trim((string) $input['name']), 0, 120);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(__('Informe o nome da área.'), false, ERROR);
                return false;
            }
        }
        if (isset($input['type']) && !isset(self::TIPOS[$input['type']])) {
            Session::addMessageAfterRedirect(__('Tipo de área inválido.'), false, ERROR);
            return false;
        }
        if (isset($input['color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $input['color'])) {
            $input['color'] = '#64748b';
        }
        if (isset($input['icon']) && !isset(self::ICONES[$input['icon']])) {
            $input['icon'] = 'ti ti-chart-bar';
        }
        if (isset($input['description'])) {
            $input['description'] = mb_substr(trim((string) $input['description']), 0, 255);
        }
        foreach (['entities_id', 'groups_id', 'itilcategories_id'] as $f) {
            if (array_key_exists($f, $input)) {
                $input[$f] = max(0, (int) $input[$f]);
            }
        }
        if (array_key_exists('is_active', $input)) {
            $input['is_active'] = empty($input['is_active']) ? 0 : 1;
        }
        return $input;
    }

    /** Áreas personalizadas (todas ou só as ativas), na ordem do painel. */
    public static function todas(bool $apenasAtivas = false): array
    {
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => $apenasAtivas ? ['is_active' => 1] : [], 'ORDER' => ['position', 'id']]), false);
    }

    /** Indicadores manuais de uma área. */
    public static function kpis(int $areaId): array
    {
        global $DB;
        if (!$DB->tableExists(self::KPIS)) {
            return [];
        }
        return iterator_to_array($DB->request(['FROM' => self::KPIS, 'WHERE' => ['plugin_indicadores_areas_id' => $areaId], 'ORDER' => ['position', 'id']]), false);
    }

    /** Coletores das áreas ativas (usado pelo motor, junto com as áreas que vêm com o plugin). */
    public static function coletores(): array
    {
        $out = [];
        foreach (self::todas(true) as $row) {
            $c = new Personalizada($row, $row['type'] === 'manual' ? self::kpis((int) $row['id']) : []);
            $out[$c->codigo()] = $c;
        }
        return $out;
    }

    /** Remove a área, os indicadores, os valores gravados e as metas. */
    public static function excluir(int $id): void
    {
        global $DB;
        $code = 'x' . $id;
        $DB->delete(self::KPIS, ['plugin_indicadores_areas_id' => $id]);
        $DB->delete(Motor::VALORES, ['code' => ['LIKE', $code . '.%']]);
        $DB->delete(Meta::getTable(), ['area' => $code]);
        $DB->delete(self::getTable(), ['id' => $id]);
        Motor::esquecer();
    }

    /** Depois de criar ou alterar uma área: atualiza o catálogo de metas e, nas de chamados, calcula o histórico. */
    public static function atualizar(int $id): void
    {
        Motor::esquecer();
        Meta::sync();
        $c = Motor::coletores()['x' . $id] ?? null;
        if ($c && $c->tipo() === 'chamados') {
            Motor::recalcularArea('x' . $id, 12);
        }
    }
}
