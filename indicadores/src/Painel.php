<?php

namespace GlpiPlugin\Indicadores;

/**
 * Dados do painel executivo: a grade mês a mês (área × mês), o índice geral e o que cada célula mostra
 * ao passar o mouse (indicadores do mês com a tendência dos meses anteriores).
 */
class Painel
{
    /**
     * Grade de um ano.
     *
     * @return array{ano: int, meses: string[], areas: array, grade: array, geral: array, atual: ?string}
     */
    public static function grade(int $ano): array
    {
        $dados = Motor::ano($ano);
        $meses = array_keys($dados);
        $areas = [];
        foreach (Motor::disponiveis() as $code => $c) {
            $areas[$code] = ['nome' => $c->nome(), 'icone' => $c->icone(), 'cor' => $c->cor(), 'descricao' => $c->descricao()];
        }
        $grade = [];
        $geral = [];
        foreach ($dados as $ym => $porArea) {
            foreach ($porArea as $code => $r) {
                $grade[$code][$ym] = ['score' => $r['score'], 'status' => $r['status'], 'bad' => $r['bad'], 'warn' => $r['warn']];
            }
            $geral[$ym] = Motor::geral($porArea);
        }
        return ['ano' => $ano, 'meses' => $meses, 'areas' => $areas, 'grade' => $grade, 'geral' => $geral, 'atual' => $meses ? end($meses) : null];
    }

    /**
     * Conteúdo do balão de uma célula (área e mês) ou da linha do índice geral ("all").
     * Cada indicador leva a série dos últimos 12 meses terminando no mês pedido.
     */
    public static function celula(string $area, string $ym): array
    {
        $meses = Periodo::ultimosMeses($ym, 12);
        foreach ($meses as $m) {
            Motor::garantir($m);
        }
        $fotos = Motor::fotos($meses);
        $resumos = [];
        foreach ($meses as $m) {
            foreach (Motor::disponiveis() as $code => $c) {
                $resumos[$m][$code] = Motor::resumoArea($code, $fotos[$m]);
            }
        }
        $rotulo = Periodo::mes($ym);

        if ($area === 'all') {
            $geral = Motor::geral($resumos[$ym] ?? []);
            $serie = [];
            foreach ($meses as $m) {
                $serie[$m] = Motor::geral($resumos[$m] ?? [])['score'];
            }
            $linhas = [];
            foreach (Motor::disponiveis() as $code => $c) {
                $s = [];
                foreach ($meses as $m) {
                    $s[$m] = $resumos[$m][$code]['score'];
                }
                $r = $resumos[$ym][$code];
                $linhas[] = ['code' => $code, 'nome' => $c->nome(), 'icone' => $c->icone(), 'cor' => $c->cor(), 'score' => $r['score'], 'status' => $r['status'], 'bad' => $r['bad'], 'warn' => $r['warn'], 'serie' => array_values($s)];
            }
            return ['tipo' => 'geral', 'titulo' => 'Índice geral de qualidade', 'mes' => $rotulo, 'ym' => $ym, 'score' => $geral['score'], 'status' => $geral['status'],
                'anterior' => self::anterior($serie, $ym), 'serie' => array_values($serie), 'meses' => array_map([Periodo::class, 'mes'], $meses), 'areas' => $linhas];
        }

        $c = Motor::coletores()[$area] ?? null;
        if (!$c || !isset($resumos[$ym][$area])) {
            return ['tipo' => 'vazio', 'titulo' => 'Sem dados', 'mes' => $rotulo];
        }
        $r = $resumos[$ym][$area];
        $serieArea = [];
        foreach ($meses as $m) {
            $serieArea[$m] = $resumos[$m][$area]['score'];
        }
        $kpis = [];
        foreach ($r['kpis'] as $code => $k) {
            $kpis[] = [
                'code' => $code, 'nome' => $k['name'], 'unit' => $k['unit'], 'dir' => $k['dir'], 'valor' => $k['value'], 'status' => $k['status'],
                'meta' => $k['meta'], 'limite' => $k['limite'], 'vivo' => (bool) $k['vivo'], 'dica' => $k['dica'],
                'fmt' => Score::format($k['value'], $k['unit']), 'fmtMeta' => $k['meta'] === null ? null : Score::format($k['meta'], $k['unit']),
                'serie' => array_values(array_map(fn ($m) => $fotos[$m][$code] ?? null, $meses)),
            ];
        }
        return ['tipo' => 'area', 'titulo' => $c->nome(), 'icone' => $c->icone(), 'cor' => $c->cor(), 'mes' => $rotulo, 'ym' => $ym, 'score' => $r['score'], 'status' => $r['status'],
            'anterior' => self::anterior($serieArea, $ym), 'serie' => array_values($serieArea), 'meses' => array_map([Periodo::class, 'mes'], $meses), 'kpis' => $kpis];
    }

    /** Nota do mês anterior ao informado (para a variação). */
    private static function anterior(array $serie, string $ym): ?float
    {
        $keys = array_keys($serie);
        $i = array_search($ym, $keys, true);
        return $i !== false && $i > 0 ? $serie[$keys[$i - 1]] : null;
    }

    /** Pontos de atenção do mês: indicadores críticos e em atenção de todas as áreas, do pior para o melhor. */
    public static function atencao(string $ym, int $limite = 8): array
    {
        $out = [];
        foreach (Motor::mes($ym) as $area => $r) {
            $c = Motor::coletores()[$area];
            foreach ($r['kpis'] as $code => $k) {
                if (in_array($k['status'], [Score::BAD, Score::WARN], true)) {
                    $out[] = ['area' => $area, 'area_nome' => $c->nome(), 'cor' => $c->cor(), 'code' => $code, 'nome' => $k['name'], 'status' => $k['status'], 'score' => $k['score'],
                        'fmt' => Score::format($k['value'], $k['unit']), 'fmtMeta' => $k['meta'] === null ? '—' : Score::format($k['meta'], $k['unit']), 'vivo' => (bool) $k['vivo']];
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['status'] === Score::BAD ? 0 : 1, $a['score']] <=> [$b['status'] === Score::BAD ? 0 : 1, $b['score']]);
        return array_slice($out, 0, $limite);
    }

    /**
     * Desvios de todas as áreas (indicadores em atenção ou críticos) com plano e prioridade, do mais urgente ao menos urgente.
     *
     * @param array<string, array> $cur resumo por área (Motor::resumoArea)
     */
    public static function desvios(array $cur): array
    {
        $areas = Motor::coletores();
        $out = [];
        foreach ($cur as $code => $r) {
            foreach ($r['kpis'] as $kcode => $k) {
                if (!in_array($k['status'], [Score::BAD, Score::WARN], true)) {
                    continue;
                }
                $plano = Planos::para($kcode, $k['status'], (bool) $k['vivo']);
                $prio = Planos::prioridade($k['status'], $k['score'], (int) $k['peso']);
                $out[] = $plano + [
                    'area' => $code, 'area_nome' => $areas[$code]->nome(), 'code' => $kcode, 'nome' => $k['name'], 'status' => $k['status'], 'score' => $k['score'],
                    'prioridade' => $prio, 'cor' => Planos::COR[$prio],
                    'valor' => Score::format($k['value'], $k['unit']), 'metaTxt' => Score::format($k['meta'], $k['unit']), 'vivo' => (bool) $k['vivo'],
                ];
            }
        }
        usort($out, fn ($a, $b) => [$a['prioridade'], $a['score']] <=> [$b['prioridade'], $b['score']]);
        return $out;
    }

    /** Uma frase sobre a área: o maior desvio, "tudo na meta" ou "sem dados". */
    public static function mensagem(array $r): string
    {
        if ($r['score'] === null) {
            return 'Sem dados suficientes no período';
        }
        $pior = null;
        foreach ($r['kpis'] as $k) {
            if ($k['score'] !== null && $k['status'] !== Score::OK && ($pior === null || $k['score'] < $pior['score'])) {
                $pior = $k;
            }
        }
        return $pior ? $pior['name'] . ': ' . Score::format($pior['value'], $pior['unit']) . ' (meta ' . Score::format($pior['meta'], $pior['unit']) . ')' : 'Todos os indicadores na meta';
    }
}
