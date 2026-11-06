<?php

namespace GlpiPlugin\Indicadores;

use CommonDBTM;

/**
 * Área do painel de KPIs. A raiz ("root") é a Superintendência; as demais ("area") ficam debaixo dela,
 * cada uma com os seus KPIs mensais, quadrimestrais ou anuais.
 */
class KpiArea extends CommonDBTM
{
    public static string $rightname = 'plugin_indicadores';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Áreas de KPIs' : 'Área de KPIs';
    }
}
