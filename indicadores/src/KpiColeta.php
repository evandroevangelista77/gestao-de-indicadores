<?php

namespace GlpiPlugin\Indicadores;

/**
 * Coleta automática dos KPIs: percorre as áreas com coleta ligada e os KPIs que têm fonte, calcula o valor do
 * mês (ou do quadrimestre e do ano, para os KPIs dessa frequência) e grava com origem "auto". Valor lançado à
 * mão tem prioridade: a coleta nunca sobrescreve um valor manual; para voltar ao automático, apague o valor.
 */
class KpiColeta
{
    /** Palavras do nome do KPI => fonte e parâmetro sugeridos. */
    private const SUGESTOES = [
        ['acordos de nível de serviços - incidentes', 'incidentes_sla', ''],
        ['taxa de cumprimento de acordos de nível de serviço', 'chamados_sla', ''],
        ['requisições atendidas no prazo', 'requisicoes_sla', ''],
        ['satisfação do cliente', 'chamados_satisfacao', ''],
        ['reclamação de cliente', 'chamados_reclamacao', ''],
        ['satisfação - projetos', 'pmo_satisfacao', ''],
        ['lançamento dos indicadores no prazo', 'lancamento_prazo', ''],
        ['documentação devolvida', 'ciclo_devolucao', ''],
        ['divulgação do aviso de fechamento', 'ciclo_fase', 'divulgacao'],
        ['envio do pedido ao fornecedor', 'ciclo_fase', 'compra'],
        ['criação do centro de custo', 'ciclo_fase', 'centro_custo'],
        ['aprovação da rm', 'ciclo_fase', 'requisicao'],
        ['espelho do pedido', 'ciclo_fase', 'transfer'],
        ['saída do material em estoque', 'ciclo_fase', 'expedicao'],
        ['documentação comercial corrigida', 'ciclo_fase', 'validacao'],
        ['disponibilidade', 'zabbix_sli', ''],
    ];

    /** Janela do KPI no mês: mensal = o mês; quadrimestral = 4 meses até o mês; anual = o ano. */
    public static function janela(array $kpi, string $ym): array
    {
        $ano = (int) substr($ym, 0, 4);
        $mes = (int) substr($ym, 5, 2);
        $de = match ($kpi['freq']) {
            'quadrimestral' => sprintf('%04d-%02d-01', $ano, max(1, $mes - 3)),
            'anual'         => sprintf('%04d-01-01', $ano),
            default         => "$ym-01",
        };
        return [$de, date('Y-m-t', strtotime("$ym-01"))];
    }

    /**
     * Coleta um mês (todas as áreas, ou só uma).
     *
     * @return array{preenchidos: int, manuais: int, sem_dado: int}
     */
    public static function coletar(string $ym, ?int $areaId = null): array
    {
        global $DB;
        $stat = ['preenchidos' => 0, 'manuais' => 0, 'sem_dado' => 0];
        if ($ym > date('Y-m') || !$DB->tableExists(KpiArea::getTable())) {
            return $stat;
        }
        $mes = (int) substr($ym, 5, 2);
        foreach (KpiMotor::areas() as $area) {
            if (($areaId !== null && (int) $area['id'] !== $areaId) || ($areaId === null && !(int) $area['auto'])) {
                continue;
            }
            $ids = [];
            $kpis = array_filter(KpiMotor::kpis((int) $area['id']), fn ($k) => !empty($k['source']) && KpiMotor::aplica($k, $mes));
            foreach ($kpis as $k) {
                $ids[] = (int) $k['id'];
            }
            $atuais = KpiMotor::valores($ids, [$ym]);
            $origens = KpiMotor::origens($ids, [$ym]);
            foreach ($kpis as $k) {
                $kid = (int) $k['id'];
                if (($origens[$kid][$ym] ?? null) === 'manual' && ($atuais[$kid][$ym] ?? null) !== null) {
                    $stat['manuais']++;
                    continue;
                }
                [$de, $ate] = self::janela($k, $ym);
                $v = Fontes::valor($k['source'], $area, $k, $de, $ate);
                if ($v === null) {
                    $stat['sem_dado']++;
                    if (isset($origens[$kid][$ym])) {
                        $DB->delete(KpiMotor::VALORES, ['plugin_indicadores_kpis_id' => $kid, 'period' => $ym, 'origin' => 'auto']);
                    }
                    continue;
                }
                KpiMotor::salvar($kid, $ym, $v, 'auto');
                $stat['preenchidos']++;
            }
            $DB->update(KpiArea::getTable(), ['date_collect' => date('Y-m-d H:i:s')], ['id' => $area['id']]);
        }
        return $stat;
    }

    /** Coleta os últimos meses (do mais antigo ao mais novo, para o "lançamento no prazo" enxergar os manuais). */
    public static function coletarMeses(int $meses = 12, ?int $areaId = null): array
    {
        $total = ['preenchidos' => 0, 'manuais' => 0, 'sem_dado' => 0];
        foreach (Periodo::ultimosMeses(date('Y-m'), $meses) as $ym) {
            foreach (self::coletar($ym, $areaId) as $k => $n) {
                $total[$k] += $n;
            }
        }
        Motor::recalcularArea('so', $meses); // a Superintendência no painel executivo acompanha os KPIs
        Motor::garantir(date('Y-m'), true);
        return $total;
    }

    /** Vincula fontes sugeridas aos KPIs que ainda não têm fonte (ou a todos, com $sobrescrever). Devolve quantos vinculou. */
    public static function sugerir(bool $sobrescrever = false): int
    {
        global $DB;
        $n = 0;
        foreach (KpiMotor::areas(false) as $area) {
            foreach (KpiMotor::kpis((int) $area['id'], false) as $k) {
                if (!empty($k['source']) && !$sobrescrever) {
                    continue;
                }
                $nome = mb_strtolower((string) $k['name']);
                foreach (self::SUGESTOES as [$padrao, $fonte, $param]) {
                    if (str_contains($nome, $padrao)) {
                        $DB->update(Kpi::getTable(), ['source' => $fonte, 'source_param' => $param ?: null], ['id' => $k['id']]);
                        $n++;
                        break;
                    }
                }
            }
        }
        return $n;
    }
}
