<?php

namespace GlpiPlugin\Indicadores;

use CommonDBTM;

/** Execução de uma auditoria: o período avaliado, a nota, o semáforo e os achados (guardados em JSON). */
class Execucao extends CommonDBTM
{
    public static string $rightname = 'plugin_indicadores';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Execuções' : 'Execução';
    }

    public function prepareInputForAdd($input)
    {
        $input['date_run'] = date('Y-m-d H:i:s');
        return parent::prepareInputForAdd($input);
    }

    /** Execuções mais recentes (de uma auditoria ou de todas). */
    public static function recentes(?int $auditoria = null, int $limite = 20): array
    {
        global $DB;
        $where = $auditoria ? ['plugin_indicadores_auditorias_id' => $auditoria] : [];
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => $limite]), false);
    }

    /** Última execução de cada auditoria: auditoria => linha. */
    public static function ultimas(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'id ASC']) as $r) {
            $out[(int) $r['plugin_indicadores_auditorias_id']] = $r;
        }
        return $out;
    }
}
