<?php

namespace GlpiPlugin\Indicadores;

use CommonDBTM;
use Session;
use Ticket;
use Throwable;

/**
 * Auditoria programada: de tempos em tempos (quinzenal, mensal, bimestral, trimestral, semestral ou anual)
 * avalia o período que acabou de fechar em uma área, ou em todas, e registra a nota, a evolução e os
 * achados (indicadores em atenção ou críticos). Pode abrir um chamado quando a nota fica abaixo do mínimo.
 */
class Auditoria extends CommonDBTM
{
    public static string $rightname = 'plugin_indicadores';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Auditorias' : 'Auditoria';
    }

    /** Áreas que uma auditoria pode cobrir: "all" ou o código do coletor. */
    public static function opcoesArea(): array
    {
        $out = ['all' => 'Todas as áreas'];
        foreach (Motor::coletores() as $code => $c) {
            $out[$code] = $c->nome();
        }
        return $out;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->limpar($input);
        if ($input === false) {
            return false;
        }
        $input['next_run'] = $input['next_run'] ?? Periodo::proxima($input['frequency'] ?? 'mensal', date('Y-m-d'));
        $input['date_creation'] = date('Y-m-d H:i:s');
        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->limpar($input);
        if ($input === false) {
            return false;
        }
        // trocar a frequência recalcula a próxima execução
        if (isset($input['frequency']) && $input['frequency'] !== ($this->fields['frequency'] ?? null)) {
            $input['next_run'] = Periodo::proxima($input['frequency'], date('Y-m-d'));
        }
        $input['date_mod'] = date('Y-m-d H:i:s');
        return parent::prepareInputForUpdate($input);
    }

    private function limpar(array $input)
    {
        if (isset($input['name'])) {
            $input['name'] = mb_substr(trim((string) $input['name']), 0, 255);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(__('Informe o nome da auditoria.'), false, ERROR);
                return false;
            }
        }
        if (isset($input['frequency']) && !isset(Periodo::FREQUENCIAS[$input['frequency']])) {
            Session::addMessageAfterRedirect(__('Frequência inválida.'), false, ERROR);
            return false;
        }
        if (isset($input['area']) && !isset(self::opcoesArea()[$input['area']])) {
            Session::addMessageAfterRedirect(__('Área inválida.'), false, ERROR);
            return false;
        }
        if (isset($input['min_score'])) {
            $input['min_score'] = max(0, min(100, (int) $input['min_score']));
        }
        foreach (['is_active', 'open_ticket'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = empty($input[$flag]) ? 0 : 1;
            }
        }
        return $input;
    }

    /** Auditorias ativas cuja data de execução chegou. */
    public static function devidas(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['is_active' => 1, 'next_run' => ['<=', date('Y-m-d')]], 'ORDER' => 'next_run']), false);
    }

    /**
     * Executa uma auditoria e devolve o código da execução gravada.
     *
     * @param string $origem "auto" (agendada) ou "manual" (botão); só a agendada empurra a próxima data
     */
    public static function executar(int $id, string $origem = 'manual'): ?int
    {
        global $DB;
        $a = new self();
        if (!$a->getFromDB($id)) {
            return null;
        }
        $f = $a->fields;
        $hoje = date('Y-m-d');
        [$de, $ate] = Periodo::janela($f['frequency'], $hoje);
        $areas = $f['area'] === 'all' ? null : [$f['area']];

        $valores = Motor::calcular($de, $ate, true, $areas);
        $resumo = ['areas' => [], 'achados' => []];
        $notas = [];
        foreach (Motor::disponiveis() as $code => $c) {
            if ($areas !== null && !in_array($code, $areas, true)) {
                continue;
            }
            $r = Motor::resumoArea($code, $valores);
            $resumo['areas'][$code] = ['nome' => $c->nome(), 'score' => $r['score'], 'status' => $r['status'], 'ok' => $r['ok'], 'warn' => $r['warn'], 'bad' => $r['bad']];
            if ($r['score'] !== null) {
                $notas[] = $r['score'];
            }
            foreach ($r['kpis'] as $kcode => $k) {
                if (in_array($k['status'], [Score::BAD, Score::WARN], true)) {
                    $resumo['achados'][] = ['area' => $code, 'area_nome' => $c->nome(), 'code' => $kcode, 'name' => $k['name'], 'unit' => $k['unit'], 'dir' => $k['dir'],
                        'value' => $k['value'], 'meta' => $k['meta'], 'limite' => $k['limite'], 'status' => $k['status'], 'score' => $k['score'], 'vivo' => (bool) $k['vivo']];
                }
            }
        }
        usort($resumo['achados'], fn ($x, $y) => [$x['status'] === Score::BAD ? 0 : 1, $x['score']] <=> [$y['status'] === Score::BAD ? 0 : 1, $y['score']]);
        $nota = $notas ? round(array_sum($notas) / count($notas), 1) : null;
        $status = Score::band($nota);

        // nota da execução anterior da mesma auditoria, para mostrar a evolução
        $prev = $DB->request(['SELECT' => ['score'], 'FROM' => Execucao::getTable(), 'WHERE' => ['plugin_indicadores_auditorias_id' => $id], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        $resumo['anterior'] = $prev && $prev['score'] !== null ? (float) $prev['score'] : null;

        $ticket = 0;
        if ($nota !== null && $nota < (int) $f['min_score'] && (int) $f['open_ticket'] === 1 && $resumo['achados']) {
            $ticket = self::abrirChamado($f, $de, $ate, $nota, $resumo);
        }

        $execId = (new Execucao())->add([
            'plugin_indicadores_auditorias_id' => $id,
            'name'        => $f['name'],
            'area'        => $f['area'],
            'frequency'   => $f['frequency'],
            'period_from' => $de,
            'period_to'   => $ate,
            'score'       => $nota,
            'status'      => $status,
            'kpis_bad'    => count(array_filter($resumo['achados'], fn ($x) => $x['status'] === Score::BAD)),
            'kpis_warn'   => count(array_filter($resumo['achados'], fn ($x) => $x['status'] === Score::WARN)),
            'resumo'      => json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tickets_id'  => $ticket,
            'origin'      => $origem,
            'users_id'    => (int) Session::getLoginUserID(),
        ]);

        $update = ['last_run' => date('Y-m-d H:i:s')];
        if ($origem === 'auto' || $f['next_run'] <= $hoje) {
            $update['next_run'] = Periodo::proxima($f['frequency'], $hoje);
        }
        $DB->update(self::getTable(), $update, ['id' => $id]);
        return $execId ?: null;
    }

    /** Abre um chamado para o responsável com os achados da auditoria. */
    private static function abrirChamado(array $f, string $de, string $ate, float $nota, array $resumo): int
    {
        $cfg = Menu::config();
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linhas = '';
        foreach (array_slice($resumo['achados'], 0, 15) as $x) {
            $linhas .= '<li><b>' . $e($x['area_nome']) . '</b> · ' . $e($x['name']) . ': ' . $e(Score::format($x['value'], $x['unit']))
                . ' (meta ' . $e(Score::format($x['meta'], $x['unit'])) . ') — ' . $e(Score::label($x['status'])) . '</li>';
        }
        $content = '<p>A auditoria <b>' . $e($f['name']) . '</b> avaliou ' . $e(Periodo::texto($de, $ate)) . ' e a nota foi <b>'
            . $e(number_format($nota, 1, ',', '.')) . '</b>, abaixo do mínimo de ' . (int) $f['min_score'] . '.</p><p>Pontos de atenção:</p><ul>' . $linhas . '</ul>'
            . "<p><a href='" . $e(Menu::absolute('/front/auditoria.php')) . "'>Abrir as auditorias</a></p>";
        try {
            $input = [
                'name'        => 'Auditoria de qualidade: ' . $f['name'] . ' (' . Periodo::texto($de, $ate) . ') — nota ' . number_format($nota, 1, ',', '.'),
                'content'     => $content,
                'entities_id' => (int) $cfg['ticket_entity'],
                'type'        => (int) $cfg['ticket_type'],
                'urgency'     => 3,
                'impact'      => 3,
            ];
            if ((int) $f['users_id'] > 0) {
                $input['_users_id_assign'] = (int) $f['users_id'];
            }
            return (int) (new Ticket())->add($input);
        } catch (Throwable $err) {
            trigger_error('Indicadores: não foi possível abrir o chamado da auditoria: ' . $err->getMessage(), E_USER_WARNING);
            return 0;
        }
    }
}
