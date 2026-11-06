<?php

namespace GlpiPlugin\Indicadores;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa a planilha do painel da Superintendência (uma aba por área: Nome, Nome Indicador, Meta, Peso,
 * Frequência, Observação). A primeira aba é a própria Superintendência (a meta do consolidado). A
 * importação pode ser repetida: o que já existe é atualizado (por área e nome do KPI) e o que é novo é criado.
 */
class KpiImport
{
    /** Palavras do nome que indicam "menor é melhor". */
    private const MENOR_MELHOR = ['variação', 'variacao', 'desvio', 'devolvida', 'escalonamentos', 'reclamação', 'reclamacao', 'recorrência', 'recorrencia', 'reincidência', 'reincidencias', 'reincidências'];

    /** @return array{areas: int, criados: int, atualizados: int} */
    public static function arquivo(string $caminho): array
    {
        global $DB;
        $wb = IOFactory::load($caminho);
        $stat = ['areas' => 0, 'criados' => 0, 'atualizados' => 0];
        $ordem = 0;
        foreach ($wb->getAllSheets() as $i => $ws) {
            $linhas = $ws->toArray(null, true, true, false);
            array_shift($linhas); // cabeçalho
            $linhas = array_values(array_filter($linhas, fn ($l) => isset($l[1]) && trim((string) $l[1]) !== ''));
            if (!$linhas) {
                continue;
            }
            $nome = trim((string) ($linhas[0][0] ?? $ws->getTitle())) ?: $ws->getTitle();
            if ($i === 0) {
                // a primeira aba é a superintendência: guarda a meta do consolidado
                $meta = KpiMotor::numero($linhas[0][2] ?? null);
                $id = self::area($nome, 'root', 0, $meta ?? 90);
                $stat['areas']++;
                continue;
            }
            $ordem += 10;
            $areaId = self::area($nome, 'area', $ordem, null);
            $stat['areas']++;
            $pos = 0;
            foreach ($linhas as $l) {
                $pos += 10;
                $kpi = trim((string) $l[1]);
                $row = [
                    'meta' => KpiMotor::numero($l[2] ?? null),
                    'peso' => (int) round((float) ($l[3] ?? 0)),
                    'freq' => self::frequencia((string) ($l[4] ?? '')),
                    'note' => trim((string) ($l[5] ?? '')) ?: null,
                    'position' => $pos,
                ];
                $existente = $DB->request(['FROM' => Kpi::getTable(), 'WHERE' => ['plugin_indicadores_kpiareas_id' => $areaId, 'name' => $kpi], 'LIMIT' => 1])->current();
                if ($existente) {
                    $DB->update(Kpi::getTable(), $row, ['id' => $existente['id']]);
                    $stat['atualizados']++;
                } else {
                    $DB->insert(Kpi::getTable(), $row + [
                        'plugin_indicadores_kpiareas_id' => $areaId, 'name' => $kpi, 'is_active' => 1,
                        'dir' => self::direcao($kpi), 'unit' => stripos($kpi, '(valor)') !== false ? 'R$' : '%',
                    ]);
                    $stat['criados']++;
                }
            }
        }
        Motor::esquecer();
        return $stat;
    }

    private static function area(string $nome, string $tipo, int $ordem, ?float $meta): int
    {
        global $DB;
        $existente = $DB->request(['FROM' => KpiArea::getTable(), 'WHERE' => ['name' => $nome, 'kind' => $tipo], 'LIMIT' => 1])->current();
        if ($existente) {
            $DB->update(KpiArea::getTable(), ['meta' => $meta ?? $existente['meta']], ['id' => $existente['id']]);
            return (int) $existente['id'];
        }
        $DB->insert(KpiArea::getTable(), ['name' => $nome, 'kind' => $tipo, 'position' => $ordem, 'meta' => $meta, 'is_active' => 1]);
        return (int) $DB->insertId();
    }

    private static function frequencia(string $f): string
    {
        $f = mb_strtolower(trim($f));
        return str_contains($f, 'quadr') ? 'quadrimestral' : (str_contains($f, 'anu') ? 'anual' : 'mensal');
    }

    private static function direcao(string $nome): string
    {
        $n = mb_strtolower($nome);
        foreach (self::MENOR_MELHOR as $p) {
            if (str_contains($n, $p)) {
                return 'down';
            }
        }
        return 'up';
    }
}
