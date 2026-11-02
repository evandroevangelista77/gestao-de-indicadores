<?php

namespace GlpiPlugin\Indicadores;

/**
 * Relatório executivo em PDF: capa, resumo executivo, mapa de calor, evolução do índice, uma seção por área
 * (leitura, indicadores com tendência e planos de ação para os desvios), matriz de prioridades, roadmap de
 * 15, 30, 60 e 90 dias, metodologia e as auditorias recentes.
 */
class Relatorio
{
    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const PRIO = ['P1' => '#dc2626', 'P2' => '#ea580c', 'P3' => '#ca8a04'];
    private const FAIXA = [Score::OK => 'saudável', Score::WARN => 'de atenção', Score::BAD => 'crítica', Score::NONE => 'sem dados'];

    public static function build(string $ym): string
    {
        return self::document($ym)->Output('', 'S');
    }

    /** Último mês fechado ou o corrente: o corrente é o padrão, porque é o que a diretoria quer ver. */
    public static function mesPadrao(): string
    {
        return date('Y-m');
    }

    public static function rotuloMes(string $ym): string
    {
        return ucfirst(self::MESES[(int) substr($ym, 5, 2) - 1]) . ' de ' . substr($ym, 0, 4);
    }

    private static function n(?float $v, int $dec = 0): string
    {
        return $v === null ? '—' : number_format($v, $dec, ',', '.');
    }

    /** Variação em pontos: "+3,2 pts" ou "−1,5 pts". */
    private static function delta(?float $cur, ?float $prev): ?string
    {
        if ($cur === null || $prev === null) {
            return null;
        }
        $d = round($cur - $prev, 1);
        return $d == 0 ? 'estável' : ($d > 0 ? '+' : '−') . self::n(abs($d), 1) . ' pts';
    }

    public static function document(string $ym): RelatorioDoc
    {
        $meses = Periodo::ultimosMeses($ym, 12);
        foreach ($meses as $m) {
            Motor::garantir($m);
        }
        $fotos = Motor::fotos($meses);
        $areas = Motor::disponiveis();
        $res = [];
        foreach ($meses as $m) {
            foreach ($areas as $code => $c) {
                $res[$m][$code] = Motor::resumoArea($code, $fotos[$m]);
            }
        }
        $prevYm = $meses[10];
        $cur = $res[$ym];
        $prev = $res[$prevYm];
        $geral = Motor::geral($cur);
        $geralPrev = Motor::geral($prev);
        $serieGeral = array_map(fn ($m) => Motor::geral($res[$m])['score'], $meses);
        $label = self::rotuloMes($ym);
        $partial = $ym === date('Y-m');
        $cfg = Menu::config();

        $pdf = new RelatorioDoc();
        $pdf->cover = true;
        $pdf->docTitle = 'Visão da diretoria';
        $pdf->docSub = $label;
        $pdf->docFoot = "Relatório executivo de qualidade · $label · uso interno";
        $pdf->SetTitle("Relatório executivo de qualidade · $label");
        $pdf->SetSubject('Indicadores de qualidade por área');

        $desvios = Painel::desvios($cur);

        self::capa($pdf, $label, $partial, $geral, $geralPrev);
        $pdf->AddPage();
        self::visaoCeo($pdf, $areas, $cur, $prev, $res, $meses, $geral, $geralPrev, $desvios, $label, $serieGeral);
        $pdf->AddPage();
        self::decisoes($pdf, $desvios);
        // anexos: o detalhe para quem quiser ir fundo
        $pdf->AddPage();
        $y = self::resumo($pdf, $areas, $cur, $prev, $res, $meses, $geral, $geralPrev, $desvios, $label);
        self::evolucao($pdf, $y, $areas, $cur, $prev, $meses, $serieGeral, $cfg);
        foreach ($areas as $code => $c) {
            self::areaPagina($pdf, $c, $code, $cur[$code], $prev[$code], $res, $meses, array_values(array_filter($desvios, fn ($d) => $d['area'] === $code)), $label);
        }
        self::matriz($pdf, $desvios);
        self::metodologia($pdf, $cfg, $partial);
        return $pdf;
    }


    // ------------------------------------------------------------------ visão da diretoria (uma página)

    private static function visaoCeo(RelatorioDoc $pdf, array $areas, array $cur, array $prev, array $res, array $meses, array $geral, array $geralPrev, array $desvios, string $label, array $serieGeral): void
    {
        $y = RelatorioDoc::TOP - 4;
        $cor = RelatorioDoc::statusColor($geral['status']);

        // o veredito, em uma frase
        $ord = $cur;
        uasort($ord, fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1));
        $com = array_filter($ord, fn ($r) => $r['score'] !== null);
        $best = array_key_first($com);
        $worst = array_key_last($com);
        $frentes = count(array_unique(array_column(array_filter($desvios, fn ($d) => $d['prioridade'] === 'P1'), 'area')));
        $verdict = 'A qualidade operacional está ' . self::FAIXA[$geral['status']] . ' (índice ' . self::n($geral['score']) . ')'
            . (($d = self::delta($geral['score'], $geralPrev['score'])) ? ', ' . ($d === 'estável' ? 'estável' : $d) . ' sobre o mês anterior' : '') . '. '
            . ($best && $worst && $best !== $worst ? $areas[$best]->nome() . ' é o destaque positivo e ' . $areas[$worst]->nome() . ' a maior preocupação. ' : '')
            . ($frentes ? 'Há ' . $frentes . ' frente(s) que pedem decisão da diretoria agora.' : 'Nenhuma frente pede decisão imediata.');
        $y = $pdf->banner($y, 'O que a diretoria precisa saber', $verdict, $cor);

        // índice geral + evolução
        $pdf->fill('#ffffff');
        $pdf->stroke(RelatorioDoc::LINE, 0.25);
        $pdf->RoundedRect(RelatorioDoc::X, $y, RelatorioDoc::W, 44, 2.5, '1111', 'DF');
        $pdf->gauge(RelatorioDoc::X + 25, $y + 22, 16, $geral['score'], $cor, 22);
        $pdf->SetFont('dejavusans', 'B', 6.6);
        $pdf->ink(RelatorioDoc::MUTED);
        $pdf->SetXY(RelatorioDoc::X + 48, $y + 6);
        $pdf->Cell(40, 4, 'ÍNDICE GERAL', 0, 2, 'L');
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->ink($cor);
        $pdf->Cell(40, 6, Score::label($geral['status']), 0, 2, 'L');
        $pdf->SetFont('dejavusans', '', 7.6);
        $pdf->ink(RelatorioDoc::MUTED);
        $pdf->Cell(40, 4.4, ($d ?? null) ? $d . ' vs. mês anterior' : '', 0, 2, 'L');
        $pdf->Cell(40, 4.4, $geral['ok'] . ' saudáveis · ' . $geral['warn'] . ' em atenção · ' . $geral['bad'] . ' críticas', 0, 0, 'L');
        $pdf->chart(RelatorioDoc::X + 92, $y + 2, 86, 40, $serieGeral, array_map(fn ($m) => mb_substr(Periodo::mes($m), 0, 3), $meses), $cor, 0, 100, (float) Menu::config()['green_min']);
        $y += 50;

        // áreas
        $y = $pdf->section($y, 'Situação por área', 'Nota de 0 a 100, variação sobre o mês anterior e o maior desvio de cada área');
        $w = (RelatorioDoc::W - 8) / 3;
        $h = 35;
        $i = 0;
        foreach ($cur as $code => $r) {
            $col = $i % 3;
            $row = intdiv($i, 3);
            $pdf->tile(RelatorioDoc::X + $col * ($w + 4), $y + $row * ($h + 3), $w, $h, [
                'nome' => $areas[$code]->nome(), 'score' => $r['score'], 'status' => $r['status'], 'delta' => self::delta($r['score'], $prev[$code]['score']),
                'serie' => array_map(fn ($m) => $res[$m][$code]['score'], $meses), 'msg' => Painel::mensagem($r),
            ]);
            $i++;
        }
        $y += (int) ceil(count($cur) / 3) * ($h + 3) + 1;

        // o que funciona e onde há exposição
        $y = $pdf->ensure($y, 36);
        $half = (RelatorioDoc::W - 6) / 2;
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->ink(RelatorioDoc::OK);
        $pdf->SetXY(RelatorioDoc::X, $y);
        $pdf->Cell($half, 4, 'O QUE ESTÁ FUNCIONANDO', 0, 0, 'L');
        $pdf->ink(RelatorioDoc::BAD);
        $pdf->SetXY(RelatorioDoc::X + $half + 6, $y);
        $pdf->Cell($half, 4, 'EXPOSIÇÃO PARA O NEGÓCIO', 0, 0, 'L');
        $bons = [];
        foreach ($ord as $code => $r) {
            if ($r['status'] === Score::OK) {
                $bons[] = '<b>' . htmlspecialchars($areas[$code]->nome()) . '</b>: nota ' . self::n($r['score']) . ', ' . $r['ok'] . ' indicador(es) na meta.';
            }
        }
        foreach ($cur as $code => $r) {
            $dd = self::delta($r['score'], $prev[$code]['score']);
            if ($dd && str_starts_with($dd, '+') && $r['score'] - $prev[$code]['score'] >= 10 && $r['status'] !== Score::OK) {
                $bons[] = '<b>' . htmlspecialchars($areas[$code]->nome()) . '</b> reagiu: ' . $dd . ' sobre o mês anterior.';
            }
        }
        $expo = [];
        $vistos = [];
        foreach ($desvios as $d2) {
            if (isset($vistos[$d2['area']]) || count($vistos) >= 3) {
                continue;
            }
            $vistos[$d2['area']] = true;
            $expo[] = '<b>' . htmlspecialchars($d2['area_nome']) . '</b>: ' . htmlspecialchars($d2['risco']);
        }
        $pdf->bullets($y + 5, array_slice($bons ?: ['Nenhuma área está plenamente saudável neste mês.'], 0, 2), RelatorioDoc::X, $half, RelatorioDoc::OK, 7.4);
        $pdf->bullets($y + 5, $expo ?: ['Sem exposição relevante neste mês.'], RelatorioDoc::X + $half + 6, $half, RelatorioDoc::BAD, 7.4);
    }

    // ------------------------------------------------------------------ decisões da diretoria

    private static function decisoes(RelatorioDoc $pdf, array $desvios): void
    {
        $y = $pdf->section(RelatorioDoc::TOP, 'Decisões que pedem a diretoria', 'As frentes mais urgentes, com responsável, prazo e o risco de não agir');
        $top = array_slice($desvios, 0, 4);
        if (!$top) {
            $y = $pdf->banner($y, 'Sem decisões pendentes', 'Nenhum indicador fora da meta neste período.', RelatorioDoc::OK);
        }
        foreach ($top as $d) {
            $y = $pdf->decisao($y, [
                'prioridade' => $d['prioridade'], 'cor' => $d['cor'],
                'titulo' => $d['area_nome'] . ' · ' . $d['nome'] . ': ' . $d['valor'] . ' (meta ' . $d['metaTxt'] . ')',
                'decisao' => $d['acoes'][0], 'dono' => $d['dono'], 'prazo' => $d['prazo'], 'meta' => 'Atingir ' . $d['metaTxt'],
                'risco' => $d['risco'],
            ]);
        }
        if (count($desvios) > 4) {
            $pdf->SetFont('dejavusans', 'I', 7.4);
            $pdf->ink(RelatorioDoc::MUTED);
            $pdf->SetXY(RelatorioDoc::X, $y);
            $pdf->Cell(RelatorioDoc::W, 4, '+ ' . (count($desvios) - 4) . ' desvio(s) menos urgentes estão na matriz de prioridades (anexo).', 0, 1, 'L');
            $y += 7;
        }
        self::roadmap($pdf, $desvios, $y + 2);
    }

    // ------------------------------------------------------------------ capa

    private static function capa(RelatorioDoc $pdf, string $label, bool $partial, array $geral, array $geralPrev): void
    {
        $pdf->AddPage();
        $cor = RelatorioDoc::statusColor($geral['status']);
        $pdf->fill(RelatorioDoc::ACCENT);
        $pdf->Rect(RelatorioDoc::X, 34, 1.8, 40, 'F');
        $pdf->SetXY(RelatorioDoc::X + 6, 32);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->ink('#93c5fd');
        $pdf->Cell(150, 5, 'RELATÓRIO EXECUTIVO · USO INTERNO', 0, 2, 'L');
        $pdf->SetFont('dejavusans', 'B', 30);
        $pdf->ink('#ffffff');
        $pdf->MultiCell(160, 13, "Qualidade\noperacional", 0, 'L', false, 2);
        $pdf->SetFont('dejavusans', '', 13);
        $pdf->ink('#cbd5e1');
        $pdf->SetXY(RelatorioDoc::X + 6, 86);
        $pdf->Cell(160, 8, 'Painel executivo, desvios e plano de ação por área', 0, 0, 'L');
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->ink('#ffffff');
        $pdf->SetXY(RelatorioDoc::X + 6, 95);
        $pdf->Cell(160, 8, $label . ($partial ? ' · mês em andamento' : ''), 0, 0, 'L');

        $pdf->gauge(105, 150, 42, $geral['score'], $cor, 42, '#1e293b', '#ffffff');
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->ink('#94a3b8');
        $pdf->SetXY(55, 197);
        $pdf->Cell(100, 5, 'ÍNDICE GERAL DE QUALIDADE', 0, 2, 'C');
        $pdf->pill(80, 204, 50, mb_strtoupper(Score::label($geral['status'])), $cor, 6.4);
        $d = self::delta($geral['score'], $geralPrev['score']);
        if ($d) {
            $pdf->SetFont('dejavusans', '', 8.5);
            $pdf->ink('#cbd5e1');
            $pdf->SetXY(55, 213);
            $pdf->Cell(100, 5, $d . ' em relação ao mês anterior', 0, 0, 'C');
        }

        $x = RelatorioDoc::X;
        $w = (RelatorioDoc::W - 8) / 3;
        foreach ([['Áreas saudáveis', $geral['ok'], RelatorioDoc::OK], ['Em atenção', $geral['warn'], RelatorioDoc::WARN], ['Críticas', $geral['bad'], RelatorioDoc::BAD]] as $i => [$lab, $n, $c]) {
            $cx = $x + $i * ($w + 4);
            $pdf->fill('#111f3d');
            $pdf->RoundedRect($cx, 236, $w, 24, 2.5, '1111', 'F');
            $pdf->fill($c);
            $pdf->Rect($cx, 239, 1.4, 18, 'F');
            $pdf->SetFont('dejavusans', 'B', 22);
            $pdf->ink('#ffffff');
            $pdf->SetXY($cx + 6, 238);
            $pdf->Cell($w - 8, 11, (string) $n, 0, 2, 'L');
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->ink('#94a3b8');
            $pdf->Cell($w - 8, 5, $lab, 0, 0, 'L');
        }
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->ink('#64748b');
        $pdf->SetXY(RelatorioDoc::X, 275);
        $pdf->Cell(RelatorioDoc::W, 4, 'Gerado em ' . date('d/m/Y H:i') . ' · Fonte: GLPI · plugin Indicadores · Dados sujeitos às metas e limites configurados', 0, 0, 'L');
    }

    // ------------------------------------------------------------------ resumo executivo

    private static function resumo(RelatorioDoc $pdf, array $areas, array $cur, array $prev, array $res, array $meses, array $geral, array $geralPrev, array $desvios, string $label): float
    {
        $y = RelatorioDoc::TOP;
        $y = $pdf->section($y, 'Anexo · Resumo analítico', $label);

        $criticas = array_keys(array_filter($cur, fn ($r) => $r['status'] === Score::BAD));
        $nomes = fn (array $codes) => implode(', ', array_map(fn ($c) => $areas[$c]->nome(), $codes));
        $faixa = self::FAIXA[$geral['status']];
        $headline = 'O índice geral de qualidade é ' . self::n($geral['score']) . ' (faixa ' . $faixa . ')'
            . (($d = self::delta($geral['score'], $geralPrev['score'])) ? ', ' . $d . ' sobre o mês anterior' : '') . '. '
            . ($criticas ? count($criticas) . ' de ' . count($cur) . ' áreas exigem ação imediata: ' . $nomes($criticas) . '.' : 'Nenhuma área está em situação crítica.');
        $y = $pdf->banner($y, 'Leitura da diretoria', $headline, RelatorioDoc::statusColor($geral['status']));

        // cartões
        $totBad = count(array_filter($desvios, fn ($d) => $d['status'] === Score::BAD));
        $totWarn = count($desvios) - $totBad;
        $p1 = count(array_filter($desvios, fn ($d) => $d['prioridade'] === 'P1'));
        $w = (RelatorioDoc::W - 12) / 4;
        $cards = [
            ['Índice geral', self::n($geral['score']), 'de 100 pontos', RelatorioDoc::statusColor($geral['status'])],
            ['Áreas saudáveis', $geral['ok'] . ' de ' . count($cur), $geral['warn'] . ' em atenção · ' . $geral['bad'] . ' críticas', RelatorioDoc::OK],
            ['Indicadores críticos', (string) $totBad, $totWarn . ' em atenção', RelatorioDoc::BAD],
            ['Prioridade máxima (P1)', (string) $p1, 'ações em até 30 dias', '#dc2626'],
        ];
        foreach ($cards as $i => [$lab, $val, $sub, $c]) {
            $pdf->card(RelatorioDoc::X + $i * ($w + 4), $y, $w, 24, $lab, $val, $sub, $c);
        }
        $y += 30;

        // mensagens-chave
        $y = $pdf->section($y, 'Mensagens-chave');
        $ord = $cur;
        uasort($ord, fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1));
        $best = array_key_first($ord);
        $worst = array_key_last($ord);
        $msgs = [];
        if ($best && $ord[$best]['score'] !== null) {
            $msgs[] = '<b>' . htmlspecialchars($areas[$best]->nome()) . '</b> lidera com nota ' . self::n($ord[$best]['score']) . ' e ' . $ord[$best]['ok'] . ' indicador(es) na meta.';
        }
        if ($worst && $worst !== $best && $ord[$worst]['score'] !== null) {
            $pior = array_values(array_filter($desvios, fn ($d) => $d['area'] === $worst))[0] ?? null;
            $msgs[] = '<b>' . htmlspecialchars($areas[$worst]->nome()) . '</b> é o principal ponto de atenção (nota ' . self::n($ord[$worst]['score']) . ')'
                . ($pior ? ': ' . htmlspecialchars($pior['nome']) . ' em ' . htmlspecialchars($pior['valor']) . ' contra meta de ' . htmlspecialchars($pior['metaTxt']) . '.' : '.');
        }
        $msgs[] = '<b>' . $totBad . ' indicador(es) crítico(s)</b> pedem plano de ação em até 30 dias e <b>' . $totWarn . '</b> em atenção devem ser acompanhados em até 60 dias (detalhes nas seções por área e na matriz de prioridades).';
        $melhorou = [];
        $piorou = [];
        foreach ($cur as $code => $r) {
            $d = $r['score'] !== null && $prev[$code]['score'] !== null ? $r['score'] - $prev[$code]['score'] : null;
            if ($d !== null && $d >= 5) {
                $melhorou[] = $areas[$code]->nome();
            } elseif ($d !== null && $d <= -5) {
                $piorou[] = $areas[$code]->nome();
            }
        }
        if ($melhorou) {
            $msgs[] = 'Melhora relevante (5 pontos ou mais) em: <b>' . htmlspecialchars(implode(', ', $melhorou)) . '</b>.';
        }
        if ($piorou) {
            $msgs[] = 'Piora relevante (5 pontos ou mais) em: <b>' . htmlspecialchars(implode(', ', $piorou)) . '</b>.';
        }
        $y = $pdf->bullets($y, $msgs) + 3;

        // nota por área
        $y = $pdf->section($y, 'Nota por área', 'Média ponderada dos indicadores de cada área (0 a 100)');
        $rows = [];
        foreach ($cur as $code => $r) {
            $rows[] = [$areas[$code]->nome(), $r['score'], RelatorioDoc::statusColor($r['status'])];
        }
        usort($rows, fn ($a, $b) => ($b[1] ?? -1) <=> ($a[1] ?? -1));
        $y = $pdf->hbars(RelatorioDoc::X, $y, RelatorioDoc::W, $rows) + 3;

        // mapa de calor: 6 meses
        $y = $pdf->ensure($y, 22 + 7.6 * count($areas));
        $y = $pdf->section($y, 'Mapa de calor — últimos 6 meses', 'Nota mensal de cada área; a cor segue as faixas de saúde');
        $last6 = array_slice($meses, -6);
        $nameW = 58;
        $cw = (RelatorioDoc::W - $nameW) / 6;
        $y = $pdf->ensure($y, 10 + 7.6 * count($areas));
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->ink(RelatorioDoc::MUTED);
        foreach ($last6 as $i => $m) {
            $pdf->SetXY(RelatorioDoc::X + $nameW + $i * $cw, $y);
            $pdf->Cell($cw, 4, mb_strtoupper(Periodo::mes($m)), 0, 0, 'C');
        }
        $y += 5;
        foreach ($areas as $code => $c) {
            $pdf->SetFont('dejavusans', '', 7.8);
            $pdf->ink(RelatorioDoc::INK);
            $pdf->SetXY(RelatorioDoc::X, $y);
            $pdf->Cell($nameW - 2, 6.4, $c->nome(), 0, 0, 'L');
            foreach ($last6 as $i => $m) {
                $r = $res[$m][$code];
                $col = RelatorioDoc::statusColor($r['status']);
                $pdf->fill($r['score'] === null ? '#f1f5f9' : RelatorioDoc::tint($col, 0.22));
                $pdf->RoundedRect(RelatorioDoc::X + $nameW + $i * $cw + 0.7, $y, $cw - 1.4, 6.4, 1.2, '1111', 'F');
                $pdf->SetFont('dejavusans', 'B', 7.8);
                $pdf->ink($r['score'] === null ? '#94a3b8' : $col);
                $pdf->SetXY(RelatorioDoc::X + $nameW + $i * $cw, $y);
                $pdf->Cell($cw, 6.4, self::n($r['score']), 0, 0, 'C');
            }
            $y += 7.6;
        }
        return $y + 4;
    }

    // ------------------------------------------------------------------ evolução

    private static function evolucao(RelatorioDoc $pdf, float $y, array $areas, array $cur, array $prev, array $meses, array $serie, array $cfg): void
    {
        $y = $pdf->ensure($y, 76);
        $y = $pdf->section($y, 'Evolução do índice geral', 'Últimos 12 meses');
        $labels = array_map(fn ($m) => Periodo::mes($m), $meses);
        $pdf->chart(RelatorioDoc::X, $y, RelatorioDoc::W, 44, $serie, $labels, RelatorioDoc::ACCENT, 0, 100, (float) $cfg['green_min']);
        $y += 48;

        $y = $pdf->section($y, 'Situação por área', 'Nota do mês, variação sobre o mês anterior e indicadores fora da meta');
        $cols = [['Área', 62, 'L'], ['Nota', 18, 'C'], ['Variação', 26, 'C'], ['Críticos', 20, 'C'], ['Atenção', 20, 'C'], ['Situação', 36, 'C']];
        $y = self::cabecalho($pdf, $y, $cols);
        foreach ($cur as $code => $r) {
            $y = $pdf->ensure($y, 8);
            $col = RelatorioDoc::statusColor($r['status']);
            $d = self::delta($r['score'], $prev[$code]['score']);
            $vals = [$areas[$code]->nome(), self::n($r['score']), $d ?? '—', (string) $r['bad'], (string) $r['warn']];
            $x = RelatorioDoc::X;
            $pdf->stroke('#f1f5f9', 0.2);
            $pdf->Line(RelatorioDoc::X, $y + 6.8, RelatorioDoc::X + RelatorioDoc::W, $y + 6.8);
            foreach ($vals as $i => $v) {
                $pdf->SetFont('dejavusans', $i === 1 ? 'B' : '', 8);
                $pdf->ink($i === 2 && $d ? (str_starts_with($d, '+') ? RelatorioDoc::OK : (str_starts_with($d, '−') ? RelatorioDoc::BAD : RelatorioDoc::MUTED)) : RelatorioDoc::INK);
                $pdf->SetXY($x, $y);
                $pdf->Cell($cols[$i][1], 7, $v, 0, 0, $cols[$i][2]);
                $x += $cols[$i][1];
            }
            $pdf->pill($x + 4, $y + 0.9, 28, Score::label($r['status']), $col);
            $y += 7.2;
        }
    }

    private static function cabecalho(RelatorioDoc $pdf, float $y, array $cols): float
    {
        $pdf->fill('#f1f5f9');
        $pdf->Rect(RelatorioDoc::X, $y, RelatorioDoc::W, 6.4, 'F');
        $x = RelatorioDoc::X;
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->ink('#475569');
        foreach ($cols as [$lab, $w, $al]) {
            $pdf->SetXY($x, $y + 0.4);
            $pdf->Cell($w, 5.6, mb_strtoupper($lab), 0, 0, $al);
            $x += $w;
        }
        return $y + 7.4;
    }

    // ------------------------------------------------------------------ uma página por área

    private static function areaPagina(RelatorioDoc $pdf, $c, string $code, array $r, array $prev, array $res, array $meses, array $desvios, string $label): void
    {
        $pdf->AddPage();
        $cor = RelatorioDoc::statusColor($r['status']);
        $y = RelatorioDoc::TOP;

        // faixa da área
        $pdf->fill(RelatorioDoc::tint($c->cor(), 0.1));
        $pdf->stroke(RelatorioDoc::tint($c->cor(), 0.35), 0.25);
        $pdf->RoundedRect(RelatorioDoc::X, $y, RelatorioDoc::W, 34, 2.5, '1111', 'DF');
        $pdf->fill($c->cor());
        $pdf->RoundedRect(RelatorioDoc::X, $y, 3.4, 34, 1.7, '1001', 'F');
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->ink(RelatorioDoc::INK);
        $pdf->SetXY(RelatorioDoc::X + 8, $y + 4);
        $pdf->Cell(110, 8, $c->nome(), 0, 2, 'L');
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->ink(RelatorioDoc::MUTED);
        $pdf->Cell(110, 5, $c->descricao(), 0, 2, 'L');
        $pdf->pill(RelatorioDoc::X + 8, $y + 21, 30, mb_strtoupper(Score::label($r['status'])), $cor, 6);
        if (($d = self::delta($r['score'], $prev['score']))) {
            $pdf->SetFont('dejavusans', 'B', 7.5);
            $pdf->ink(str_starts_with($d, '+') ? RelatorioDoc::OK : (str_starts_with($d, '−') ? RelatorioDoc::BAD : RelatorioDoc::MUTED));
            $pdf->SetXY(RelatorioDoc::X + 41, $y + 21.4);
            $pdf->Cell(56, 5, $d . ' vs. mês anterior', 0, 0, 'L');
        }
        $serie = array_map(fn ($m) => $res[$m][$code]['score'], $meses);
        $pdf->spark(RelatorioDoc::X + 100, $y + 8, 34, 16, $serie, $c->cor(), 0, 100);
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->ink(RelatorioDoc::MUTED);
        $pdf->SetXY(RelatorioDoc::X + 100, $y + 25);
        $pdf->Cell(34, 3, 'nota nos últimos 12 meses', 0, 0, 'C');
        $pdf->gauge(RelatorioDoc::X + 160, $y + 17, 12.5, $r['score'], $cor, 15);
        $y += 40;

        // leitura executiva
        $y = $pdf->section($y, 'Leitura executiva');
        $y = $pdf->paragraph($y, self::leitura($c, $r, $prev, $label), 8.6) + 3;

        // indicadores
        $y = $pdf->section($y, 'Indicadores', 'Valor do mês, meta e tendência dos últimos 12 meses (linha tracejada = meta)');
        $cols = [['Indicador', 66, 'L'], ['Valor', 22, 'R'], ['Meta', 22, 'R'], ['Situação', 28, 'C'], ['Tendência', 44, 'C']];
        $y = $pdf->ensure($y, 40);
        $y = self::cabecalho($pdf, $y, $cols);
        foreach ($r['kpis'] as $kcode => $k) {
            $y = $pdf->ensure($y, 10);
            $st = $k['dir'] === 'info' ? Score::INFO : $k['status'];
            $col = RelatorioDoc::statusColor($st);
            $pdf->stroke('#f1f5f9', 0.2);
            $pdf->Line(RelatorioDoc::X, $y + 9, RelatorioDoc::X + RelatorioDoc::W, $y + 9);
            $pdf->SetFont('dejavusans', '', 7.8);
            $pdf->ink(RelatorioDoc::INK);
            $pdf->SetXY(RelatorioDoc::X, $y);
            $pdf->Cell(66, 4.6, mb_strimwidth($k['name'], 0, 44, '…'), 0, 2, 'L');
            $pdf->SetFont('dejavusans', '', 5.8);
            $pdf->ink(RelatorioDoc::MUTED);
            $pdf->Cell(66, 3, $k['vivo'] ? 'situação atual' : 'no período', 0, 0, 'L');
            $pdf->SetFont('dejavusans', 'B', 8.6);
            $pdf->ink($st === Score::NONE || $st === Score::INFO ? RelatorioDoc::INK : $col);
            $pdf->SetXY(RelatorioDoc::X + 66, $y + 1);
            $pdf->Cell(22, 6, Score::format($k['value'], $k['unit']), 0, 0, 'R');
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->ink(RelatorioDoc::MUTED);
            $pdf->Cell(22, 6, $k['meta'] === null ? '—' : Score::format($k['meta'], $k['unit']), 0, 0, 'R');
            $pdf->pill(RelatorioDoc::X + 110 + 1, $y + 1.4, 26, $k['dir'] === 'info' ? 'Informativo' : Score::label($k['status']), $col);
            $serieK = array_map(fn ($m) => $res[$m][$code]['kpis'][$kcode]['value'] ?? null, $meses);
            $pdf->spark(RelatorioDoc::X + 142, $y + 1.2, 36, 6.4, $serieK, $col, null, null, $k['meta'], false);
            $y += 9.6;
        }
        $y += 4;

        // planos
        if (!$desvios) {
            $pdf->banner($y, $r['score'] === null ? 'Sem dados' : 'Sem desvios',
                $r['score'] === null ? 'Não há dados suficientes no período para avaliar esta área. Acompanhar o início da coleta no próximo mês.' : 'Todos os indicadores com meta estão dentro do esperado. Manter a rotina atual e o acompanhamento mensal.',
                $r['score'] === null ? RelatorioDoc::NONE : RelatorioDoc::OK);
            return;
        }
        $y = $pdf->section($y, 'Desvios e plano de ação', count($desvios) . ' indicador(es) fora da meta, em ordem de prioridade');
        foreach ($desvios as $d) {
            $y = $pdf->plano($y, [
                'prioridade' => $d['prioridade'], 'cor' => $d['cor'],
                'titulo' => $d['nome'] . ' — ' . $d['valor'] . ' (meta ' . $d['metaTxt'] . ')',
                'risco' => $d['risco'], 'causas' => $d['causas'], 'acoes' => $d['acoes'],
                'dono' => $d['dono'], 'prazo' => $d['prazo'] . ' dias', 'meta' => 'Atingir ' . $d['metaTxt'] . ($d['vivo'] ? ' (situação atual)' : ' no próximo período'),
            ]);
        }
    }

    /** Texto de leitura da área, montado a partir dos números. */
    private static function leitura($c, array $r, array $prev, string $label): string
    {
        $nome = htmlspecialchars($c->nome());
        if ($r['score'] === null) {
            return "<b>$nome</b> ainda não tem dados suficientes em <b>$label</b> para compor a nota (por exemplo, poucas respostas ou nenhum registro no período).";
        }
        $txt = "<b>$nome</b> fechou <b>$label</b> com nota <b>" . self::n($r['score']) . '</b>, na faixa ' . self::FAIXA[$r['status']];
        if (($d = self::delta($r['score'], $prev['score']))) {
            $txt .= ', ' . ($d === 'estável' ? 'estável em relação ao mês anterior' : $d . ' sobre o mês anterior');
        }
        $txt .= '. ';
        $avaliados = array_filter($r['kpis'], fn ($k) => $k['score'] !== null);
        $txt .= count(array_filter($avaliados, fn ($k) => $k['status'] === Score::OK)) . ' de ' . count($avaliados) . ' indicadores avaliados estão na meta';
        $pior = null;
        foreach ($avaliados as $k) {
            if ($pior === null || $k['score'] < $pior['score']) {
                $pior = $k;
            }
        }
        if ($pior && $pior['status'] !== Score::OK) {
            $txt .= '; o maior desvio está em <b>' . htmlspecialchars($pior['name']) . '</b>: ' . htmlspecialchars(Score::format($pior['value'], $pior['unit'])) . ' contra meta de ' . htmlspecialchars(Score::format($pior['meta'], $pior['unit'])) . '.';
        } else {
            $txt .= '.';
        }
        if (array_filter($r['kpis'], fn ($k) => $k['vivo'] && $k['score'] !== null)) {
            $txt .= ' <i>Indicadores de situação atual refletem o momento da geração do relatório.</i>';
        }
        return $txt;
    }

    // ------------------------------------------------------------------ matriz e roadmap

    private static function matriz(RelatorioDoc $pdf, array $desvios): void
    {
        $pdf->AddPage();
        $y = $pdf->section(RelatorioDoc::TOP, 'Matriz de prioridades', 'Todos os desvios, do mais urgente ao menos urgente');
        if (!$desvios) {
            $pdf->banner($y, 'Sem desvios', 'Nenhum indicador fora da meta no período.', RelatorioDoc::OK);
            return;
        }
        $cols = [['Prior.', 14, 'C'], ['Área', 30, 'L'], ['Indicador', 54, 'L'], ['Atual → meta', 28, 'C'], ['Responsável', 42, 'L'], ['Prazo', 14, 'C']];
        $y = self::cabecalho($pdf, $y, $cols);
        foreach ($desvios as $d) {
            if ($y + 9 > RelatorioDoc::BOTTOM) {
                $pdf->AddPage();
                $y = self::cabecalho($pdf, RelatorioDoc::TOP, $cols);
            }
            $x = RelatorioDoc::X;
            $pdf->stroke('#f1f5f9', 0.2);
            $pdf->Line(RelatorioDoc::X, $y + 8.2, RelatorioDoc::X + RelatorioDoc::W, $y + 8.2);
            $pdf->pill($x + 1.5, $y + 1.2, 11, $d['prioridade'], $d['cor'], 5);
            $vals = [[$d['area_nome'], 30], [$d['nome'], 54], [$d['valor'] . ' → ' . $d['metaTxt'], 28], [$d['dono'], 42], [$d['prazo'] . ' d', 14]];
            $x += 14;
            foreach ($vals as $i => [$v, $w]) {
                $pdf->SetFont('dejavusans', $i === 2 ? 'B' : '', 7.2);
                $pdf->ink(RelatorioDoc::INK);
                $pdf->SetXY($x, $y + 0.6);
                $pdf->Cell($w, 7, mb_strimwidth($v, 0, (int) ($w * 0.7), '…'), 0, 0, $i === 2 || $i === 4 ? 'C' : 'L');
                $x += $w;
            }
            $y += 8.6;
        }
    }

    private static function roadmap(RelatorioDoc $pdf, array $desvios, float $y): void
    {
        $y = $pdf->ensure($y, 90);
        $y = $pdf->section($y, 'Roadmap de 90 dias', 'O que fazer e quando, a partir da aprovação deste plano');
        $faixas = [
            ['0 a 15 dias', 'Estabilizar', '#dc2626', fn ($d) => $d['prazo'] <= 15],
            ['16 a 30 dias', 'Corrigir', '#ea580c', fn ($d) => $d['prazo'] > 15 && $d['prazo'] <= 30],
            ['31 a 60 dias', 'Consolidar', '#ca8a04', fn ($d) => $d['prazo'] > 30],
        ];
        $w = (RelatorioDoc::W - 12) / 4;
        $alturaMax = 0;
        $listas = [];
        foreach ($faixas as $i => [$lab, $tit, $c, $filtro]) {
            $itens = array_map(fn ($d) => '<b>' . htmlspecialchars($d['area_nome']) . '</b> · ' . htmlspecialchars($d['nome']), array_values(array_filter($desvios, $filtro)));
            $listas[$i] = $itens ? array_slice($itens, 0, 7) : ['<i>Nenhuma ação nesta faixa neste período.</i>'];
            if (count($itens) > 7) {
                $listas[$i][] = '<i>+ ' . (count($itens) - 7) . ' ação(ões) — ver matriz</i>';
            }
        }
        $listas[3] = ['Auditoria de acompanhamento das áreas com desvio', 'Revisão de metas e limites no comitê', 'Replanejar o que não avançou e escalar à diretoria'];
        $faixas[] = ['61 a 90 dias', 'Verificar', '#1e40af', null];
        foreach ($listas as $i => $itens) {
            $h = 14;
            foreach ($itens as $t) {
                $h += $pdf->getStringHeight($w - 9, strip_tags($t)) + 2;
            }
            $alturaMax = max($alturaMax, $h);
        }
        $alturaMax = min($alturaMax, 110);
        $y = $pdf->ensure($y, $alturaMax + 4);
        foreach ($faixas as $i => [$lab, $tit, $c]) {
            $x = RelatorioDoc::X + $i * ($w + 4);
            $pdf->fill('#ffffff');
            $pdf->stroke(RelatorioDoc::LINE, 0.25);
            $pdf->RoundedRect($x, $y, $w, $alturaMax, 2, '1111', 'DF');
            $pdf->fill($c);
            $pdf->RoundedRect($x, $y, $w, 11, 2, '1100', 'F');
            $pdf->SetFont('dejavusans', 'B', 7.6);
            $pdf->ink('#ffffff');
            $pdf->SetXY($x + 3, $y + 1.2);
            $pdf->Cell($w - 6, 4.4, mb_strtoupper($lab), 0, 2, 'L');
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->Cell($w - 6, 4, $tit, 0, 0, 'L');
            $iy = $y + 13;
            foreach ($listas[$i] as $t) {
                if (!$t) {
                    continue;
                }
                $pdf->fill($c);
                $pdf->Circle($x + 3.4, $iy + 2.1, 0.8, 0, 360, 'F');
                $iy = $pdf->paragraph($iy, $t, 6.9, $x + 6, $w - 8) + 1.4;
            }
        }
    }

    // ------------------------------------------------------------------ metodologia

    private static function metodologia(RelatorioDoc $pdf, array $cfg, bool $partial): void
    {
        $y = $pdf->ensure($pdf->GetY() + 6, 100);
        if ($y < RelatorioDoc::TOP) {
            $y = RelatorioDoc::TOP;
        }
        $y = $pdf->section($y + 4, 'Metodologia e auditorias');
        $bul = [
            '<b>Nota do indicador:</b> valor na meta = 100; entre a meta e o limite = 50 a 100; além do limite = 0 a 50. Indicadores informativos não pontuam.',
            '<b>Nota da área:</b> média das notas dos indicadores ponderada pelo peso. <b>Índice geral:</b> média simples das áreas.',
            '<b>Faixas:</b> saudável a partir de ' . (int) $cfg['green_min'] . '; atenção a partir de ' . (int) $cfg['yellow_min'] . '; abaixo disso, crítica. Metas, limites e pesos são editáveis em "Metas e limites".',
            '<b>Prioridade:</b> P1 = crítico de maior peso ou muito distante da meta (ação em até 15 a 30 dias); P2 = crítico ou de peso alto (30 dias); P3 = em atenção (60 dias).',
            '<b>Situação atual:</b> pendências e atrasos abertos refletem o momento da geração' . ($partial ? ' (mês em andamento: os demais indicadores consideram os dias já decorridos)' : '') . ' e não têm histórico antes da primeira foto gravada.',
            '<b>Planos de ação:</b> biblioteca de boas práticas por indicador; os responsáveis e prazos são sugestões e devem ser validados pela gestão de cada área.',
        ];
        $y = $pdf->bullets($y, $bul, RelatorioDoc::X, RelatorioDoc::W, RelatorioDoc::ACCENT, 7.8) + 3;

        $ex = Execucao::recentes(null, 5);
        if ($ex) {
            $y = $pdf->ensure($y, 12 + 7.6 * count($ex));
            $y = $pdf->section($y, 'Últimas auditorias');
            $cols = [['Auditoria', 70, 'L'], ['Período avaliado', 50, 'L'], ['Nota', 20, 'C'], ['Críticos', 20, 'C'], ['Atenção', 22, 'C']];
            $y = self::cabecalho($pdf, $y, $cols);
            foreach ($ex as $x) {
                $vals = [$x['name'], Periodo::texto($x['period_from'], $x['period_to']), self::n($x['score'] === null ? null : (float) $x['score']), (string) (int) $x['kpis_bad'], (string) (int) $x['kpis_warn']];
                $cx = RelatorioDoc::X;
                $pdf->stroke('#f1f5f9', 0.2);
                $pdf->Line(RelatorioDoc::X, $y + 7.2, RelatorioDoc::X + RelatorioDoc::W, $y + 7.2);
                foreach ($vals as $i => $v) {
                    $pdf->SetFont('dejavusans', $i === 2 ? 'B' : '', 7.8);
                    $pdf->ink(RelatorioDoc::INK);
                    $pdf->SetXY($cx, $y);
                    $pdf->Cell($cols[$i][1], 6.8, mb_strimwidth($v, 0, (int) ($cols[$i][1] * 0.6), '…'), 0, 0, $cols[$i][2]);
                    $cx += $cols[$i][1];
                }
                $y += 7.6;
            }
        }
    }
}
