<?php

/**
 * Plugin "Indicadores" para GLPI 11 e 12.
 *
 * Reúne os indicadores de qualidade dos departamentos (PMO, Sistemas Internos, Reembolso, Comercial, Ciclo
 * Financeiro, Eventos, RH, Equipamentos, CSAT e e-NPS) num painel executivo mês a mês e executa
 * auditorias programadas (quinzenal, mensal, bimestral, trimestral, semestral e anual).
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Indicadores\Area;
use GlpiPlugin\Indicadores\Auditoria;
use GlpiPlugin\Indicadores\Execucao;
use GlpiPlugin\Indicadores\Kpi;
use GlpiPlugin\Indicadores\KpiArea;
use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Meta;

define('PLUGIN_INDICADORES_VERSION', '1.4.0');
define('PLUGIN_INDICADORES_MIN_GLPI', '11.0.0');
define('PLUGIN_INDICADORES_MAX_GLPI', '12.0.99');

function plugin_init_indicadores(): void
{
    global $PLUGIN_HOOKS;

    // aba do plugin em Administração → Perfis
    Plugin::registerClass(GlpiPlugin\Indicadores\ProfileTab::class, ['addtabon' => [Profile::class]]);

    Plugin::registerClass(Auditoria::class);
    Plugin::registerClass(Execucao::class);
    Plugin::registerClass(Meta::class);
    Plugin::registerClass(Area::class);
    Plugin::registerClass(Kpi::class);
    Plugin::registerClass(KpiArea::class);

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Menu::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::$rightname]);
        $_SESSION['glpiactiveprofile'][Menu::$rightname] = (int) ($rights[Menu::$rightname] ?? 0);
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['indicadores']     = [Menu::SECTOR => [Auditoria::class, Meta::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['indicadores'] = 'plugin_indicadores_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['indicadores']        = ['css/indicadores.css'];
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['indicadores']    = 'front/config.php';
}

/** Seção "Indicadores" no menu lateral, abaixo do Suporte (ou no topo). */
function plugin_indicadores_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'Indicadores',
        'icon'    => 'ti ti-gauge',
        'types'   => [Auditoria::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    foreach (['assets', 'management', 'tools'] as $after) {
        if (isset($menu[$after])) {
            $out = [];
            foreach ($menu as $key => $value) {
                $out[$key] = $value;
                if ($key === $after) {
                    $out += $section;
                }
            }
            return $out;
        }
    }
    return $section + $menu;
}

function plugin_version_indicadores(): array
{
    return [
        'name'         => 'Indicadores',
        'version'      => PLUGIN_INDICADORES_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/evandroevangelista77/gestao-de-indicadores',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_INDICADORES_MIN_GLPI, 'max' => PLUGIN_INDICADORES_MAX_GLPI],
        ],
    ];
}

function plugin_indicadores_check_prerequisites(): bool
{
    return true;
}

function plugin_indicadores_check_config($verbose = false): bool
{
    return true;
}
