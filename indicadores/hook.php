<?php

use GlpiPlugin\Indicadores\Area;
use GlpiPlugin\Indicadores\Auditoria;
use GlpiPlugin\Indicadores\Execucao;
use GlpiPlugin\Indicadores\Menu;
use GlpiPlugin\Indicadores\Meta;
use GlpiPlugin\Indicadores\Motor;
use GlpiPlugin\Indicadores\Periodo;
use GlpiPlugin\Indicadores\Rotina;

/** Tabelas, catálogo de indicadores, auditoria padrão, configuração, direitos e ações automáticas. */
function plugin_indicadores_install(): bool
{
    global $DB;
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $int = "int $sign NOT NULL DEFAULT 0";
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC";

    $tables = [
        // catálogo: meta, limite, peso e ativação de cada indicador (nome, unidade e direção vêm dos coletores)
        Meta::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `code` varchar(60) NOT NULL, `area` varchar(30) NOT NULL, `name` varchar(255) DEFAULT NULL,
            `unit` varchar(8) NOT NULL DEFAULT 'n', `dir` varchar(6) NOT NULL DEFAULT 'info', `meta` decimal(16,4) DEFAULT NULL, `limite` decimal(16,4) DEFAULT NULL,
            `peso` tinyint NOT NULL DEFAULT 1, `is_active` tinyint NOT NULL DEFAULT 1, `vivo` tinyint NOT NULL DEFAULT 0, `dica` varchar(255) DEFAULT NULL,
            `position` int NOT NULL DEFAULT 0, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `code` (`code`), KEY `area` (`area`)",
        // foto mensal: um valor por indicador e mês (o histórico não muda quando os dados dos plugins mudam)
        Motor::VALORES => "`id` int $sign NOT NULL AUTO_INCREMENT, `code` varchar(60) NOT NULL, `period` char(7) NOT NULL, `value` decimal(18,4) DEFAULT NULL,
            `date_calc` datetime DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `code_period` (`code`, `period`), KEY `period` (`period`)",
        // auditorias programadas
        Auditoria::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(255) DEFAULT NULL, `area` varchar(30) NOT NULL DEFAULT 'all',
            `frequency` varchar(12) NOT NULL DEFAULT 'mensal', `next_run` date DEFAULT NULL, `last_run` datetime DEFAULT NULL, `users_id` $int,
            `min_score` tinyint NOT NULL DEFAULT 80, `open_ticket` tinyint NOT NULL DEFAULT 0, `is_active` tinyint NOT NULL DEFAULT 1, `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `next_run` (`next_run`), KEY `is_active` (`is_active`)",
        // áreas personalizadas incluídas pelo usuário (manual ou chamados do GLPI)
        Area::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `name` varchar(120) DEFAULT NULL, `description` varchar(255) DEFAULT NULL,
            `type` varchar(10) NOT NULL DEFAULT 'manual', `icon` varchar(40) NOT NULL DEFAULT 'ti ti-chart-bar', `color` varchar(7) NOT NULL DEFAULT '#64748b',
            `entities_id` $int, `groups_id` $int, `itilcategories_id` $int, `is_active` tinyint NOT NULL DEFAULT 1, `position` int NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL, `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `is_active` (`is_active`)",
        // indicadores das áreas manuais
        Area::KPIS => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_indicadores_areas_id` $int, `name` varchar(255) DEFAULT NULL, `unit` varchar(8) NOT NULL DEFAULT 'n',
            `dir` varchar(6) NOT NULL DEFAULT 'info', `meta` decimal(16,4) DEFAULT NULL, `limite` decimal(16,4) DEFAULT NULL, `peso` tinyint NOT NULL DEFAULT 1, `position` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `area` (`plugin_indicadores_areas_id`)",
        // execuções: período avaliado, nota e achados
        Execucao::getTable() => "`id` int $sign NOT NULL AUTO_INCREMENT, `plugin_indicadores_auditorias_id` $int, `name` varchar(255) DEFAULT NULL,
            `area` varchar(30) NOT NULL DEFAULT 'all', `frequency` varchar(12) DEFAULT NULL, `period_from` date DEFAULT NULL, `period_to` date DEFAULT NULL,
            `date_run` datetime DEFAULT NULL, `score` decimal(5,1) DEFAULT NULL, `status` varchar(6) DEFAULT NULL, `kpis_bad` int NOT NULL DEFAULT 0, `kpis_warn` int NOT NULL DEFAULT 0,
            `resumo` longtext, `tickets_id` $int, `origin` varchar(8) NOT NULL DEFAULT 'manual', `users_id` $int,
            PRIMARY KEY (`id`), KEY `auditoria` (`plugin_indicadores_auditorias_id`), KEY `date_run` (`date_run`)",
    ];
    foreach ($tables as $table => $def) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` ($def) $opts");
        }
    }

    Meta::sync();

    // configuração (só o que ainda não foi gravado)
    $current = Config::getConfigurationValues(Menu::CONFIG_CONTEXT);
    Config::setConfigurationValues(Menu::CONFIG_CONTEXT, array_diff_key(Menu::DEFAULTS, $current));

    // auditoria de exemplo, só na primeira instalação
    if (!countElementsInTable(Auditoria::getTable())) {
        (new Auditoria())->add([
            'name'      => 'Auditoria mensal da qualidade',
            'area'      => 'all',
            'frequency' => 'mensal',
            'min_score' => 80,
            'comment'   => 'Avalia todas as áreas no mês que fechou. Altere a frequência, a área ou crie outras auditorias.',
        ]);
    }

    // direitos: ver o painel e as auditorias para a interface padrão; editar para quem administra
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Menu::$rightname])) {
        ProfileRight::addProfileRights([Menu::$rightname]);
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
            $config = ProfileRight::getProfileRights($row['id'], ['config'])['config'] ?? 0;
            $DB->update(ProfileRight::getTable(), ['rights' => ($config & UPDATE) ? READ | CREATE | UPDATE | PURGE : READ], ['profiles_id' => $row['id'], 'name' => Menu::$rightname]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Menu::$rightname]);
        $_SESSION['glpiactiveprofile'][Menu::$rightname] = (int) ($rights[Menu::$rightname] ?? 0);
    }
    unset($_SESSION['glpimenu']);

    CronTask::register(Rotina::class, 'indicadoresfoto', 6 * HOUR_TIMESTAMP, [
        'comment' => 'Indicadores: grava a foto do mês corrente e do anterior',
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);
    CronTask::register(Rotina::class, 'indicadoresauditoria', HOUR_TIMESTAMP, [
        'comment' => 'Indicadores: executa as auditorias programadas que venceram',
        'mode'    => CronTask::MODE_INTERNAL,
        'state'   => CronTask::STATE_WAITING,
    ]);

    // primeira carga do histórico (12 meses); se algo falhar, o painel calcula sob demanda
    try {
        // na atualização o histórico já existe: só as áreas novas precisam de cálculo
        if (!countElementsInTable(Motor::VALORES)) {
            Motor::recalcular(12);
        }
    } catch (Throwable $e) {
        trigger_error('Indicadores: histórico inicial não calculado: ' . $e->getMessage(), E_USER_WARNING);
    }
    return true;
}

function plugin_indicadores_uninstall(): bool
{
    global $DB;
    foreach ([Execucao::getTable(), Auditoria::getTable(), Area::KPIS, Area::getTable(), Motor::VALORES, Meta::getTable()] as $table) {
        $DB->dropTable($table, true);
    }
    $DB->delete('glpi_logs', ['itemtype' => [Auditoria::class, Execucao::class, Meta::class, Area::class]]);
    ProfileRight::deleteProfileRights([Menu::$rightname]);
    Config::deleteConfigurationValues(Menu::CONFIG_CONTEXT, array_keys(Menu::DEFAULTS));
    CronTask::unregister('indicadores');
    unset($_SESSION['glpimenu']);
    return true;
}
