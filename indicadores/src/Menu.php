<?php

namespace GlpiPlugin\Indicadores;

use Config;
use Html;
use Session;

/**
 * Seção "Indicadores" do menu, endereços, configuração e pequenos ajudantes de tela.
 */
class Menu
{
    public const SECTOR = 'indicadores';

    /** Direito do plugin: ver o painel e as auditorias; atualizar edita auditorias, metas e limites. */
    public static string $rightname = 'plugin_indicadores';

    public const CONFIG_CONTEXT = 'plugin:indicadores';

    public const DEFAULTS = [
        'green_min'     => '80',  // nota da área a partir da qual ela é "saudável"
        'yellow_min'    => '60',  // nota mínima da faixa de atenção; abaixo disso é "crítica"
        'ticket_entity' => '0',   // entidade dos chamados abertos pelas auditorias
        'ticket_type'   => '2',   // 1 incidente, 2 requisição
        'refresh_min'   => '15',  // de quantos em quantos minutos o mês corrente é recalculado
    ];

    public static function content(): array
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return [];
        }
        $items = [
            'painel'    => ['title' => 'Painel executivo', 'page' => self::page('/front/painel.php'), 'icon' => 'ti ti-layout-dashboard'],
            'auditoria' => ['title' => 'Auditorias', 'page' => self::page('/front/auditoria.php'), 'icon' => 'ti ti-clipboard-check'],
        ];
        if (Session::haveRight(self::$rightname, UPDATE)) {
            $items['area'] = ['title' => 'Áreas do painel', 'page' => self::page('/front/area.php'), 'icon' => 'ti ti-layout-grid-add'];
            $items['meta'] = ['title' => 'Metas e limites', 'page' => self::page('/front/meta.php'), 'icon' => 'ti ti-target'];
        }
        if (Session::haveRight('config', UPDATE)) {
            $items['config'] = ['title' => 'Configuração', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
        }
        return $items;
    }

    public static function page(string $path): string
    {
        return '/plugins/indicadores' . $path;
    }

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/indicadores' . $path;
    }

    /** Endereço das telas por extenso (para link em chamado ou e-mail). */
    public static function absolute(string $path): string
    {
        global $CFG_GLPI;
        return rtrim((string) $CFG_GLPI['url_base'], '/') . '/plugins/indicadores' . $path;
    }

    /** Configuração do plugin, com os padrões para o que ainda não foi gravado. */
    public static function config(): array
    {
        return Config::getConfigurationValues(self::CONFIG_CONTEXT) + self::DEFAULTS;
    }

    public static function css(): string
    {
        $file = dirname(__DIR__) . '/public/css/indicadores.css';
        $version = is_file($file) ? filemtime($file) : 0;
        return "<link rel='stylesheet' href='" . self::e(self::url('/css/indicadores.css') . '?m=' . $version) . "'>";
    }

    public static function script(string $file): string
    {
        $path = dirname(__DIR__) . '/public/js/' . $file;
        return Html::script(self::url('/js/' . $file), ['version' => is_file($path) ? filemtime($path) : 0], false);
    }

    public static function header(string $title): void
    {
        Html::header($title, '', self::SECTOR, Auditoria::class);
        echo self::css();
    }

    /** Cabeçalho da tela, com a seção do plugin e os botões de ação. */
    public static function title(string $title, string $subtitle, string $actions = ''): string
    {
        return "<div class='ind-title'><div><div class='ind-crumb'>Indicadores</div><h2>" . self::e($title) . "</h2>"
            . ($subtitle ? "<div class='text-secondary small'>" . self::e($subtitle) . "</div>" : '')
            . "</div>" . ($actions ? "<div class='ind-actions'>$actions</div>" : '') . "</div>";
    }

    public static function button(string $label, string $url, string $icon = 'ti-plus', string $cls = 'btn-primary'): string
    {
        return "<a class='btn $cls' href='" . self::e($url) . "'><i class='ti $icon me-1'></i>" . self::e($label) . "</a>";
    }

    /** Campo escondido com o token de segurança dos formulários. */
    public static function csrf(): string
    {
        return "<input type='hidden' name='_glpi_csrf_token' value='" . self::e(Session::getNewCSRFToken()) . "'>";
    }

    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
