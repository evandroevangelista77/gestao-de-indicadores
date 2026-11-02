<?php

namespace GlpiPlugin\Indicadores\Coletor;

use Throwable;

/**
 * Área criada pelo usuário no painel. Dois tipos:
 *
 * - "manual": os indicadores são definidos na tela e os valores de cada mês são lançados à mão;
 * - "chamados": os indicadores saem dos chamados do GLPI de uma entidade (com as filhas), de um grupo
 *   atribuído e/ou de uma categoria, com o mesmo conjunto de indicadores de atendimento.
 */
class Personalizada extends Coletor
{
    /** @param array $row linha da área; @param array $kpis indicadores manuais (linhas) */
    public function __construct(private array $row, private array $kpis = [])
    {
    }

    public function codigo(): string
    {
        return 'x' . (int) $this->row['id'];
    }

    public function nome(): string
    {
        return (string) $this->row['name'];
    }

    public function icone(): string
    {
        return (string) ($this->row['icon'] ?: 'ti ti-chart-bar');
    }

    public function cor(): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->row['color']) ? $this->row['color'] : '#64748b';
    }

    public function descricao(): string
    {
        return (string) ($this->row['description'] ?: ($this->row['type'] === 'chamados' ? 'Atendimento (chamados do GLPI)' : 'Indicadores lançados manualmente'));
    }

    public function tipo(): string
    {
        return (string) $this->row['type'];
    }

    protected function tabelas(): array
    {
        return $this->row['type'] === 'chamados' ? ['glpi_tickets'] : [];
    }

    public function indicadores(): array
    {
        $p = $this->codigo() . '.';
        if ($this->row['type'] === 'manual') {
            $out = [];
            foreach ($this->kpis as $k) {
                $dir = in_array($k['dir'], ['up', 'down', 'info'], true) ? $k['dir'] : 'info';
                $out[$p . 'k' . (int) $k['id']] = $this->def((string) $k['name'], (string) $k['unit'], $dir,
                    $k['meta'] === null ? null : (float) $k['meta'], $k['limite'] === null ? null : (float) $k['limite'], (int) $k['peso'], false, 'Lançado manualmente');
            }
            return $out;
        }
        return [
            $p . 'sla'      => $this->def('Chamados solucionados no prazo', '%', 'up', 90, 80, 3, false, 'Chamados solucionados no período dentro do prazo de solução'),
            $p . 'tempo'    => $this->def('Tempo médio de solução', 'h', 'down', 24, 72, 2, false, 'Da abertura à solução, nos chamados solucionados no período'),
            $p . 'vencidos' => $this->def('Chamados em aberto vencidos', 'n', 'down', 0, 5, 3, true, 'Chamados em aberto com o prazo de solução vencido'),
            $p . 'backlog'  => $this->def('Chamados em aberto', 'n', 'down', 15, 40, 1, true, 'Fila de chamados que ainda não foram solucionados'),
            $p . 'abertos'  => $this->def('Chamados abertos', 'n', 'info', null, null, 0, false, 'Chamados abertos no período'),
        ];
    }

    /** Filtro SQL dos chamados da área (entidade e filhas, grupo atribuído e categoria). */
    private function filtro(): string
    {
        $ents = [(int) $this->row['entities_id']];
        try {
            $ents = array_map('intval', array_values(getSonsOf('glpi_entities', (int) $this->row['entities_id'])));
        } catch (Throwable) {
        }
        $sql = 'is_deleted = 0 AND entities_id IN (' . implode(',', $ents ?: [0]) . ')';
        if ((int) $this->row['groups_id'] > 0) {
            $sql .= ' AND id IN (SELECT tickets_id FROM glpi_groups_tickets WHERE type = 2 AND groups_id = ' . (int) $this->row['groups_id'] . ')';
        }
        if ((int) $this->row['itilcategories_id'] > 0) {
            $cats = [(int) $this->row['itilcategories_id']];
            try {
                $cats = array_map('intval', array_values(getSonsOf('glpi_itilcategories', (int) $this->row['itilcategories_id'])));
            } catch (Throwable) {
            }
            $sql .= ' AND itilcategories_id IN (' . implode(',', $cats ?: [0]) . ')';
        }
        return $sql;
    }

    public function coletar(string $de, string $ate, bool $vivo): array
    {
        if ($this->row['type'] !== 'chamados') {
            return []; // os valores manuais ficam como foram lançados
        }
        $p = $this->codigo() . '.';
        $i = $this->ini($de);
        $f = $this->fim($ate);
        $t = 'FROM glpi_tickets WHERE ' . $this->filtro();
        $out = [];
        $r = $this->linha("SELECT COUNT(*) AS n, SUM(CASE WHEN solvedate <= time_to_resolve THEN 1 ELSE 0 END) AS ok $t AND solvedate BETWEEN '$i' AND '$f' AND time_to_resolve IS NOT NULL");
        $out[$p . 'sla'] = $this->pct((float) ($r['ok'] ?? 0), (float) ($r['n'] ?? 0));
        $out[$p . 'tempo'] = $this->media($this->numero("SELECT AVG(TIMESTAMPDIFF(MINUTE, date, solvedate)) / 60 $t AND solvedate BETWEEN '$i' AND '$f'"));
        $out[$p . 'abertos'] = $this->numero("SELECT COUNT(*) $t AND date BETWEEN '$i' AND '$f'");
        if ($vivo) {
            $out[$p . 'backlog'] = $this->numero("SELECT COUNT(*) $t AND status IN (1, 2, 3, 4)");
            $out[$p . 'vencidos'] = $this->numero("SELECT COUNT(*) $t AND status IN (1, 2, 3, 4) AND time_to_resolve IS NOT NULL AND time_to_resolve < NOW()");
        }
        return $out;
    }
}
