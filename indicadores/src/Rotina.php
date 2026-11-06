<?php

namespace GlpiPlugin\Indicadores;

use CommonGLPI;
use CronTask;
use Throwable;

/** Ações automáticas do plugin: fotos dos indicadores e execução das auditorias que venceram. */
class Rotina extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Indicadores';
    }

    public static function cronInfo($name): array
    {
        return match ($name) {
            'indicadoresfoto'      => ['description' => 'Indicadores: grava a foto do mês corrente e do anterior'],
            'indicadoreskpi'       => ['description' => 'Indicadores: coleta automática dos KPIs por área'],
            'indicadoresauditoria' => ['description' => 'Indicadores: executa as auditorias programadas que venceram'],
            default                => [],
        };
    }

    public static function cronIndicadoresfoto(CronTask $task): int
    {
        $task->addVolume(Motor::recalcular(2));
        return 1;
    }

    public static function cronIndicadoreskpi(CronTask $task): int
    {
        $n = 0;
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $ym) {
            $n += KpiColeta::coletar($ym)['preenchidos'];
        }
        Motor::recalcularArea('so', 2);
        $task->addVolume($n);
        return 1;
    }

    public static function cronIndicadoresauditoria(CronTask $task): int
    {
        $done = 0;
        foreach (Auditoria::devidas() as $a) {
            try {
                if (Auditoria::executar((int) $a['id'], 'auto')) {
                    $done++;
                    $task->addVolume(1);
                    $task->log('Auditoria executada: ' . $a['name']);
                }
            } catch (Throwable $e) {
                $task->log('Falha na auditoria ' . $a['name'] . ': ' . $e->getMessage());
            }
        }
        return $done > 0 ? 1 : 0;
    }
}
