<?php

namespace GlpiPlugin\Indicadores\Coletor;

use Throwable;

/**
 * Coletor de indicadores de uma área (um plugin ou módulo do GLPI).
 *
 * Cada coletor declara os indicadores da área (nome, unidade, direção, meta, limite, peso) e calcula o
 * valor de cada um para um período. Indicadores "vivos" descrevem a situação de agora (pendências,
 * atrasos abertos) e por isso só são calculados quando o período inclui hoje; os demais podem ser
 * calculados para qualquer período passado a partir das datas gravadas pelos plugins.
 *
 * Um coletor nunca derruba o painel: se a tabela do plugin não existe ou a consulta falha, o valor é nulo.
 */
abstract class Coletor
{
    /** Código da área, usado nos códigos dos indicadores (por exemplo "pmo"). */
    abstract public function codigo(): string;

    abstract public function nome(): string;

    /** Ícone Tabler (classe "ti ti-...") e cor da área. */
    abstract public function icone(): string;

    abstract public function cor(): string;

    /** Resumo do que a área mede, mostrado no painel. */
    abstract public function descricao(): string;

    /** Tabelas que precisam existir para a área ter dados (plugin instalado). */
    abstract protected function tabelas(): array;

    /**
     * Indicadores: código => [name, unit, dir (up|down|info), meta, limite, peso, vivo, dica].
     * Unidades: %, d (dias), h (horas), n (quantidade), R$ e nota.
     */
    abstract public function indicadores(): array;

    /**
     * @param string $de   primeiro dia (Y-m-d)
     * @param string $ate  último dia (Y-m-d)
     * @param bool   $vivo true quando o período inclui hoje (calcula também os indicadores vivos)
     *
     * @return array<string, ?float> código => valor (nulo quando não há dados)
     */
    abstract public function coletar(string $de, string $ate, bool $vivo): array;

    public function disponivel(): bool
    {
        global $DB;
        foreach ($this->tabelas() as $table) {
            if (!$DB->tableExists($table)) {
                return false;
            }
        }
        return true;
    }

    /** Monta a definição de um indicador. */
    protected function def(string $name, string $unit, string $dir, ?float $meta, ?float $limite, int $peso = 1, bool $vivo = false, string $dica = ''): array
    {
        return ['name' => $name, 'unit' => $unit, 'dir' => $dir, 'meta' => $meta, 'limite' => $limite, 'peso' => $dir === 'info' ? 0 : $peso, 'vivo' => $vivo, 'dica' => $dica];
    }

    // ------------------------------------------------------------------ ajudantes de consulta

    /** Início do dia ("Y-m-d 00:00:00"), com a data validada. */
    protected function ini(string $day): string
    {
        return self::day($day) . ' 00:00:00';
    }

    protected function fim(string $day): string
    {
        return self::day($day) . ' 23:59:59';
    }

    protected static function day(string $day): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new \InvalidArgumentException('Data inválida: ' . $day);
        }
        return $day;
    }

    /** Primeira linha do resultado; vazio quando a consulta falha. */
    protected function linha(string $sql): array
    {
        global $DB;
        try {
            $res = $DB->doQuery($sql);
            $row = $res ? $res->fetch_assoc() : null;
            return $row ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Primeira coluna da primeira linha como número (nulo quando não há dado). */
    protected function numero(string $sql): ?float
    {
        $row = $this->linha($sql);
        $v = $row ? reset($row) : null;
        return $v === null || $v === false || $v === '' ? null : (float) $v;
    }

    /** Percentual de $parte em $total (nulo quando o total é zero ou ausente). */
    protected function pct(?float $parte, ?float $total): ?float
    {
        return $total ? round(100 * (float) $parte / $total, 1) : null;
    }

    protected function media(?float $valor, int $casas = 1): ?float
    {
        return $valor === null ? null : round($valor, $casas);
    }

    /** Quando o indicador é vivo e o período não inclui hoje, não há como calculá-lo para trás. */
    protected function vivo(bool $vivo, callable $fn): ?float
    {
        return $vivo ? $fn() : null;
    }

    /** Fases (ciclo e eventos): percentual de fases concluídas no período que ficaram dentro do SLO. */
    protected function sloDasFases(string $tabela, string $de, string $ate): ?float
    {
        $r = $this->linha("SELECT COUNT(*) AS t, SUM(CASE WHEN seconds <= slo_days * 86400 THEN 1 ELSE 0 END) AS ok
            FROM `$tabela` WHERE status = 'done' AND slo_days > 0 AND date_end BETWEEN '" . $this->ini($de) . "' AND '" . $this->fim($ate) . "'");
        return $this->pct((float) ($r['ok'] ?? 0), (float) ($r['t'] ?? 0));
    }
}
