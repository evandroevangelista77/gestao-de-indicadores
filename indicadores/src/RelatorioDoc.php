<?php

namespace GlpiPlugin\Indicadores;

use TCPDF;

/**
 * Página do relatório executivo (TCPDF, que já vem com o GLPI): capa, cabeçalho e rodapé "Página x de y",
 * e os elementos desenhados direto no PDF (anel de nota, cartões, linhas de indicador com mini-gráfico,
 * barras, gráfico de evolução e cartões de plano de ação), para o relatório não depender do navegador.
 */
class RelatorioDoc extends TCPDF
{
    public const X = 14;
    public const W = 182;
    public const TOP = 30;
    public const BOTTOM = 278;

    public const INK = '#0f172a';
    public const MUTED = '#64748b';
    public const LINE = '#e2e8f0';
    public const ACCENT = '#1e40af';
    public const NAVY = '#0b1730';

    public const OK = '#16a34a';
    public const WARN = '#d97706';
    public const BAD = '#dc2626';
    public const NONE = '#94a3b8';

    public string $docTitle = '';
    public string $docSub = '';
    public string $docFoot = '';
    public bool $cover = false;
    private bool $coverDrawn = false;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8');
        $this->setLanguageArray(['a_meta_charset' => 'UTF-8', 'a_meta_dir' => 'ltr', 'a_meta_language' => 'pt', 'w_page' => 'página']);
        $this->SetCreator('GLPI · Indicadores');
        $this->SetMargins(self::X, self::TOP, self::X);
        $this->SetHeaderMargin(0);
        $this->SetFooterMargin(14);
        $this->SetAutoPageBreak(false);
        $this->SetFont('dejavusans', '', 8.5);
        $this->setCellHeightRatio(1.35);
    }

    public static function statusColor(string $status): string
    {
        return [Score::OK => self::OK, Score::WARN => self::WARN, Score::BAD => self::BAD][$status] ?? self::NONE;
    }

    public function Header()
    {
        // a primeira chamada do cabeçalho é a da capa
        if ($this->cover && !$this->coverDrawn) {
            $this->coverDrawn = true;
            $this->fill(self::NAVY);
            $this->Rect(0, 0, 210, 297, 'F');
            $this->fill(self::ACCENT);
            $this->Rect(0, 0, 210, 3, 'F');
            return;
        }
        $this->fill(self::ACCENT);
        $this->Rect(0, 0, 210, 2.4, 'F');
        $this->Rect(self::X, 8, 1.2, 14, 'F');
        $this->SetXY(17.5, 7.6);
        $this->SetFont('dejavusans', 'B', 6.5);
        $this->ink(self::ACCENT);
        $this->Cell(110, 4, 'RELATÓRIO EXECUTIVO DE QUALIDADE', 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 13);
        $this->ink(self::INK);
        $this->Cell(110, 7, $this->docTitle, 0, 0, 'L');
        $this->SetXY(110, 9);
        $this->SetFont('dejavusans', 'B', 9);
        $this->Cell(86, 5, $this->docSub, 0, 0, 'R');
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.2);
        $this->Line(self::X, 24.5, self::X + self::W, 24.5);
    }

    public function Footer()
    {
        if ($this->PageNo() === 1 && $this->cover) {
            return;
        }
        $this->SetY(-13);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.2);
        $this->Line(self::X, $this->GetY(), self::X + self::W, $this->GetY());
        $this->SetFont('dejavusans', '', 6.5);
        $this->ink(self::MUTED);
        $this->Cell(140, 6, $this->docFoot, 0, 0, 'L');
        $this->Cell(42, 6, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
    }

    // ------------------------------------------------------------------ cores e textos

    /** @return int[] */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    public function fill(string $hex): void
    {
        $this->SetFillColor(...self::rgb($hex));
    }

    public function ink(string $hex): void
    {
        $this->SetTextColor(...self::rgb($hex));
    }

    public function stroke(string $hex, float $width = 0.2): void
    {
        $this->SetDrawColor(...self::rgb($hex));
        $this->SetLineWidth($width);
    }

    /** Mistura a cor com branco (0 = branca, 1 = a própria cor), para fundos suaves. */
    public static function tint(string $hex, float $amount): string
    {
        $c = self::rgb($hex);
        return sprintf('#%02x%02x%02x', ...array_map(fn ($v) => (int) round(255 - (255 - $v) * $amount), $c));
    }

    /** Abre nova página quando $height não cabe no que sobra; devolve o Y onde seguir. */
    public function ensure(float $y, float $height): float
    {
        if ($y + $height > self::BOTTOM) {
            $this->AddPage();
            return self::TOP;
        }
        return $y;
    }

    /** Título de seção com a barra de cor. Devolve o Y de depois dele. */
    public function section(float $y, string $title, string $hint = ''): float
    {
        $y = $this->ensure($y, 18);
        $this->fill(self::ACCENT);
        $this->Rect(self::X, $y + 0.6, 1.4, 5, 'F');
        $this->SetXY(self::X + 3.5, $y);
        $this->SetFont('dejavusans', 'B', 11);
        $this->ink(self::INK);
        $this->Cell(self::W - 4, 6.5, $title, 0, 1, 'L');
        if ($hint !== '') {
            $this->SetXY(self::X + 3.5, $y + 6.5);
            $this->SetFont('dejavusans', '', 7.5);
            $this->ink(self::MUTED);
            $this->Cell(self::W - 4, 4, $hint, 0, 1, 'L');
            return $y + 12.5;
        }
        return $y + 9;
    }

    /** Texto corrido (aceita HTML simples). Devolve o Y de depois dele. */
    public function paragraph(float $y, string $html, float $size = 8.5, float $x = self::X, float $w = self::W, string $color = self::INK): float
    {
        $this->SetFont('dejavusans', '', $size);
        $this->ink($color);
        $this->writeHTMLCell($w, 0, $x, $y, $html, 0, 1, false, true, 'L', true);
        return $this->GetY();
    }

    /** Lista com marcadores. Devolve o Y de depois dela. */
    public function bullets(float $y, array $lines, float $x = self::X, float $w = self::W, string $color = self::ACCENT, float $size = 8.3): float
    {
        foreach ($lines as $line) {
            $y = $this->ensure($y, 9);
            $this->fill($color);
            $this->Circle($x + 1.6, $y + 2.3, 0.85, 0, 360, 'F');
            $y = $this->paragraph($y, $line, $size, $x + 4.5, $w - 4.5) + 1.2;
        }
        return $y;
    }

    /** Faixa de destaque: barra colorida, rótulo e a frase que resume. Devolve o Y de depois dela. */
    public function banner(float $y, string $label, string $text, string $color): float
    {
        $this->SetFont('dejavusans', 'B', 10.5);
        $h = 11 + $this->getStringHeight(self::W - 12, $text);
        $this->fill(self::tint($color, 0.12));
        $this->stroke(self::tint($color, 0.35), 0.25);
        $this->RoundedRect(self::X, $y, self::W, $h, 2, '1111', 'DF');
        $this->fill($color);
        $this->RoundedRect(self::X, $y, 3, $h, 1.5, '1001', 'F');
        $this->SetXY(self::X + 7, $y + 2.6);
        $this->SetFont('dejavusans', 'B', 7);
        $this->ink($color);
        $this->Cell(100, 3.8, mb_strtoupper($label), 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 10.5);
        $this->ink(self::INK);
        $this->MultiCell(self::W - 12, 5, $text, 0, 'L', false, 1);
        return $y + $h + 4;
    }

    // ------------------------------------------------------------------ elementos gráficos

    /** Anel de nota: arco colorido proporcional à nota, com o número no centro. */
    public function gauge(float $cx, float $cy, float $r, ?float $score, string $color, float $numSize = 22, string $trackColor = '#e2e8f0', string $textColor = self::INK, string $sub = '/ 100'): void
    {
        $this->fill($trackColor);
        $this->Circle($cx, $cy, $r, 0, 360, 'F');
        if ($score !== null && $score > 0) {
            $this->fill($color);
            $angle = min(359.9, 3.6 * $score);
            $this->PieSector($cx, $cy, $r, 0, $angle, 'F', true, 90);
        }
        $inner = $this->cover && $this->PageNo() === 1 ? self::NAVY : '#ffffff';
        $this->fill($inner);
        $this->Circle($cx, $cy, $r * 0.76, 0, 360, 'F');
        $this->SetFont('dejavusans', 'B', $numSize);
        $this->ink($textColor);
        $this->SetXY($cx - $r, $cy - $numSize * 0.2);
        $this->Cell($r * 2, $numSize * 0.4, $score === null ? '—' : (string) round($score), 0, 0, 'C');
        $this->SetFont('dejavusans', '', max(6, $numSize * 0.3));
        $this->ink($textColor === self::INK ? self::MUTED : '#94a3b8');
        $this->SetXY($cx - $r, $cy + $numSize * 0.22);
        $this->Cell($r * 2, 4, $sub, 0, 0, 'C');
    }

    /** Rótulo arredondado com o texto centralizado (situação). */
    public function pill(float $x, float $y, float $w, string $text, string $color, float $h = 5.2): void
    {
        $this->fill(self::tint($color, 0.16));
        $this->RoundedRect($x, $y, $w, $h, $h / 2, '1111', 'F');
        $this->SetFont('dejavusans', 'B', 6.8);
        $this->ink($color);
        $this->SetXY($x, $y + 0.5);
        $this->Cell($w, $h - 1, $text, 0, 0, 'C');
    }

    /** Cartão de indicador: rótulo, valor grande e texto de apoio. */
    public function card(float $x, float $y, float $w, float $h, string $label, string $value, string $sub, string $color): void
    {
        $this->stroke(self::LINE, 0.25);
        $this->fill('#ffffff');
        $this->RoundedRect($x, $y, $w, $h, 1.8, '1111', 'DF');
        $this->fill($color);
        $this->Rect($x, $y + 1.5, 1.2, $h - 3, 'F');
        $this->SetXY($x + 3.2, $y + 2);
        $this->SetFont('dejavusans', 'B', 6.2);
        $this->ink(self::MUTED);
        $this->Cell($w - 4, 3.6, mb_strtoupper($label), 0, 2, 'L');
        $this->SetFont('dejavusans', 'B', 17);
        $this->ink(self::INK);
        $this->Cell($w - 4, 9, $value, 0, 2, 'L');
        $this->SetFont('dejavusans', '', 6.8);
        $this->ink(self::MUTED);
        $this->Cell($w - 4, 3.6, $sub, 0, 2, 'L');
    }

    /** Linha de evolução com área suave e pontos. Valores nulos interrompem a linha. */
    public function spark(float $x, float $y, float $w, float $h, array $values, string $color, ?float $min = null, ?float $max = null, ?float $ref = null, bool $dots = true): void
    {
        $nums = array_filter($values, fn ($v) => $v !== null);
        if (!$nums) {
            return;
        }
        $lo = $min ?? min(array_merge($nums, $ref !== null ? [$ref] : []));
        $hi = $max ?? max(array_merge($nums, $ref !== null ? [$ref] : []));
        if ($hi == $lo) {
            $hi += 1;
            $lo -= 1;
        }
        $n = count($values);
        $px = fn (int $i) => $x + ($n === 1 ? $w / 2 : $w * $i / ($n - 1));
        $py = fn (float $v) => $y + $h - ($v - $lo) / ($hi - $lo) * $h;
        if ($ref !== null) {
            $this->SetLineStyle(['width' => 0.2, 'color' => self::rgb('#94a3b8'), 'dash' => '1.2,1.2']);
            $this->Line($x, $py($ref), $x + $w, $py($ref));
        }
        $this->SetLineStyle(['width' => 0.55, 'color' => self::rgb($color), 'dash' => 0, 'cap' => 'round', 'join' => 'round']);
        $prev = null;
        foreach ($values as $i => $v) {
            if ($v === null) {
                $prev = null;
                continue;
            }
            if ($prev !== null) {
                $this->Line($px($prev[0]), $py($prev[1]), $px($i), $py($v));
            }
            $prev = [$i, $v];
        }
        if ($dots || count($nums) <= 2) {
            $this->fill($color);
            foreach ($values as $i => $v) {
                if ($v !== null) {
                    $this->Circle($px($i), $py($v), $i === $n - 1 || $n <= 2 ? 0.95 : 0.6, 0, 360, 'F');
                }
            }
        }
        $this->SetLineStyle(['width' => 0.2, 'color' => self::rgb('#cbd5e1'), 'dash' => 0]);
    }

    /** Gráfico de evolução com eixos, grade e valores nos pontos. $labels: um rótulo por ponto. */
    public function chart(float $x, float $y, float $w, float $h, array $values, array $labels, string $color, float $min = 0, float $max = 100, ?float $ref = null): void
    {
        $base = $y + $h - 6;
        $top = $y + 3;
        $plot = $base - $top;
        $this->stroke(self::LINE, 0.15);
        for ($i = 0; $i <= 4; $i++) {
            $ly = $base - $plot * $i / 4;
            $this->Line($x + 8, $ly, $x + $w, $ly);
            $this->SetFont('dejavusans', '', 6.2);
            $this->ink(self::MUTED);
            $this->SetXY($x - 1, $ly - 1.8);
            $this->Cell(8, 3.6, (string) round($min + ($max - $min) * $i / 4), 0, 0, 'R');
        }
        $n = count($values);
        $cx = fn (int $i) => $x + 8 + 4 + ($w - 8 - 8) * ($n === 1 ? 0.5 : $i / ($n - 1));
        $cy = fn (float $v) => $base - $plot * ($v - $min) / ($max - $min);
        if ($ref !== null) {
            $this->SetLineStyle(['width' => 0.25, 'color' => self::rgb(self::OK), 'dash' => '1.5,1.5']);
            $this->Line($x + 8, $cy($ref), $x + $w, $cy($ref));
            $this->SetFont('dejavusans', '', 6);
            $this->ink(self::OK);
            $this->SetXY($x + $w - 30, $cy($ref) - 3.6);
            $this->Cell(30, 3, 'faixa saudável ' . round($ref), 0, 0, 'R');
        }
        $this->SetLineStyle(['width' => 0.8, 'color' => self::rgb($color), 'dash' => 0, 'cap' => 'round', 'join' => 'round']);
        $prev = null;
        foreach ($values as $i => $v) {
            if ($v === null) {
                $prev = null;
                continue;
            }
            if ($prev !== null) {
                $this->Line($cx($prev[0]), $cy($prev[1]), $cx($i), $cy($v));
            }
            $prev = [$i, $v];
        }
        foreach ($values as $i => $v) {
            $this->SetFont('dejavusans', '', 6.5);
            $this->ink(self::MUTED);
            $this->SetXY($cx($i) - 7, $base + 1.2);
            $this->Cell(14, 3.6, (string) ($labels[$i] ?? ''), 0, 0, 'C');
            if ($v === null) {
                continue;
            }
            $this->fill('#ffffff');
            $this->SetLineStyle(['width' => 0.5, 'color' => self::rgb($color)]);
            $this->Circle($cx($i), $cy($v), 1.15, 0, 360, 'DF');
            $this->SetFont('dejavusans', 'B', 6.5);
            $this->ink(self::INK);
            $this->SetXY($cx($i) - 7, $cy($v) - 5);
            $this->Cell(14, 3.6, (string) round($v), 0, 0, 'C');
        }
        $this->SetLineStyle(['width' => 0.2, 'color' => self::rgb('#cbd5e1'), 'dash' => 0]);
    }

    /** Barras horizontais de nota: rótulo, barra proporcional a 0-100 e o número. $rows: lista de [rótulo, nota, cor]. */
    public function hbars(float $x, float $y, float $w, array $rows, float $labelW = 56, float $rowH = 7.2): float
    {
        $barW = $w - $labelW - 14;
        $x0 = $x + $labelW;
        foreach ($rows as [$label, $value, $color]) {
            $y = $this->ensure($y, $rowH);
            $this->SetFont('dejavusans', '', 7.8);
            $this->ink(self::INK);
            $this->SetXY($x, $y);
            $this->Cell($labelW - 2, $rowH - 1, mb_strimwidth((string) $label, 0, 36, '…'), 0, 0, 'L');
            $this->fill('#f1f5f9');
            $this->RoundedRect($x0, $y + 1.2, $barW, 3.8, 1.3, '1111', 'F');
            if ($value !== null && $value > 0) {
                $this->fill($color);
                $this->RoundedRect($x0, $y + 1.2, max(1.6, $barW * min(100, $value) / 100), 3.8, 1.3, '1111', 'F');
            }
            $this->SetFont('dejavusans', 'B', 7.8);
            $this->SetXY($x0 + $barW + 1.5, $y);
            $this->Cell(12, $rowH - 1, $value === null ? '—' : (string) round($value), 0, 0, 'L');
            $y += $rowH;
        }
        return $y;
    }

    /**
     * Cartão de plano de ação: faixa de prioridade, título, o que está em jogo, causas, ações e rodapé com
     * responsável, prazo e meta. Devolve o Y de depois dele.
     */
    public function plano(float $y, array $p): float
    {
        $w = self::W;
        $bodyW = $w - 9;
        $pad = 4;
        $color = $p['cor'];
        // medidas
        $this->SetFont('dejavusans', 'B', 9);
        $hTitle = $this->getStringHeight($bodyW - 24, $p['titulo']);
        $this->SetFont('dejavusans', '', 7.6);
        $hRisk = $this->getStringHeight($bodyW, $p['risco']);
        $hCauses = 0;
        foreach ($p['causas'] as $c) {
            $hCauses += $this->getStringHeight($bodyW - 8, $c) + 1;
        }
        $this->SetFont('dejavusans', '', 7.8);
        $hActs = 0;
        foreach ($p['acoes'] as $a) {
            $hActs += $this->getStringHeight($bodyW - 12, $a) + 1.2;
        }
        $total = $pad + $hTitle + 3 + $hRisk + 4 + 4 + $hCauses + 2 + 4 + $hActs + 2 + 11 + $pad;
        $y = $this->ensure($y, $total);

        $this->fill('#ffffff');
        $this->stroke(self::LINE, 0.25);
        $this->RoundedRect(self::X, $y, $w, $total, 2, '1111', 'DF');
        $this->fill($color);
        $this->RoundedRect(self::X, $y, 3.4, $total, 1.6, '1001', 'F');

        $x = self::X + 7;
        $cy = $y + $pad;
        // prioridade e título
        $this->pill($x, $cy, 13, $p['prioridade'], $color, 5.4);
        $this->SetFont('dejavusans', 'B', 9);
        $this->ink(self::INK);
        $this->SetXY($x + 15.5, $cy);
        $this->MultiCell($bodyW - 24, 4.6, $p['titulo'], 0, 'L', false, 1);
        $cy += max($hTitle, 5.4) + 3;

        $this->SetFont('dejavusans', 'I', 7.6);
        $this->ink(self::MUTED);
        $this->SetXY($x, $cy);
        $this->MultiCell($bodyW, 4, $p['risco'], 0, 'L', false, 1);
        $cy += $hRisk + 4;

        $this->SetFont('dejavusans', 'B', 6.6);
        $this->ink($color);
        $this->SetXY($x, $cy);
        $this->Cell(60, 3.4, 'CAUSAS PROVÁVEIS', 0, 1, 'L');
        $cy += 4;
        foreach ($p['causas'] as $c) {
            $this->fill(self::MUTED);
            $this->Circle($x + 1.3, $cy + 2, 0.6, 0, 360, 'F');
            $this->SetFont('dejavusans', '', 7.6);
            $this->ink(self::INK);
            $this->SetXY($x + 4, $cy);
            $this->MultiCell($bodyW - 8, 3.6, $c, 0, 'L', false, 1);
            $cy += $this->getStringHeight($bodyW - 8, $c) + 1;
        }
        $cy += 2;
        $this->SetFont('dejavusans', 'B', 6.6);
        $this->ink($color);
        $this->SetXY($x, $cy);
        $this->Cell(60, 3.4, 'PLANO DE AÇÃO', 0, 1, 'L');
        $cy += 4;
        foreach ($p['acoes'] as $i => $a) {
            $this->fill($color);
            $this->Circle($x + 2.2, $cy + 2.2, 2.2, 0, 360, 'F');
            $this->SetFont('dejavusans', 'B', 6.4);
            $this->ink('#ffffff');
            $this->SetXY($x, $cy + 0.4);
            $this->Cell(4.4, 3.6, (string) ($i + 1), 0, 0, 'C');
            $this->SetFont('dejavusans', '', 7.8);
            $this->ink(self::INK);
            $this->SetXY($x + 6, $cy);
            $this->MultiCell($bodyW - 12, 3.8, $a, 0, 'L', false, 1);
            $cy += $this->getStringHeight($bodyW - 12, $a) + 1.2;
        }
        $cy += 2;
        // rodapé: responsável, prazo e meta
        $this->fill(self::tint($color, 0.08));
        $this->RoundedRect($x - 1, $cy, $bodyW + 1, 9, 1.4, '1111', 'F');
        $cols = [['RESPONSÁVEL SUGERIDO', $p['dono'], 78], ['PRAZO', $p['prazo'], 28], ['META DE RECUPERAÇÃO', $p['meta'], 56]];
        $cx = $x + 2;
        foreach ($cols as [$lab, $val, $cw]) {
            $this->SetFont('dejavusans', 'B', 5.8);
            $this->ink(self::MUTED);
            $this->SetXY($cx, $cy + 0.9);
            $this->Cell($cw, 3, $lab, 0, 2, 'L');
            $this->SetFont('dejavusans', 'B', 7.4);
            $this->ink(self::INK);
            $this->Cell($cw, 4, mb_strimwidth((string) $val, 0, (int) ($cw * 1.05), '…'), 0, 0, 'L');
            $cx += $cw;
        }
        return $y + $total + 3.5;
    }

    /** Cartão de área para a visão da diretoria: nome, nota grande, situação, variação, tendência e a frase do maior desvio. */
    public function tile(float $x, float $y, float $w, float $h, array $t): void
    {
        $cor = self::statusColor($t['status']);
        $this->fill('#ffffff');
        $this->stroke(self::LINE, 0.25);
        $this->RoundedRect($x, $y, $w, $h, 2.2, '1111', 'DF');
        $this->fill($cor);
        $this->RoundedRect($x, $y, $w, 2.2, 1.1, '1100', 'F');
        $this->SetFont('dejavusans', 'B', 7.6);
        $this->ink(self::INK);
        $this->SetXY($x + 3.5, $y + 4.2);
        $this->Cell($w - 7, 4, mb_strimwidth($t['nome'], 0, 30, '…'), 0, 0, 'L');
        $this->SetFont('dejavusans', 'B', 25);
        $this->ink($t['score'] === null ? self::NONE : $cor);
        $this->SetXY($x + 3.5, $y + 9.5);
        $this->Cell(26, 12, $t['score'] === null ? '—' : (string) round($t['score']), 0, 0, 'L');
        $this->pill($x + $w - 27.5, $y + 10.5, 24, mb_strtoupper(Score::label($t['status'])), $cor, 4.8);
        if (!empty($t['delta'])) {
            $this->SetFont('dejavusans', 'B', 6.8);
            $up = str_starts_with($t['delta'], '+');
            $down = str_starts_with($t['delta'], '−');
            $this->ink($up ? self::OK : ($down ? self::BAD : self::MUTED));
            $this->SetXY($x + $w - 27.5, $y + 16.2);
            $this->Cell(24, 4, ($up ? '▲ ' : ($down ? '▼ ' : '')) . $t['delta'], 0, 0, 'C');
        }
        $this->spark($x + 3.5, $y + 21.6, $w - 7, 5.4, $t['serie'], $cor, 0, 100, null, false);
        $this->SetFont('dejavusans', '', 6.2);
        $this->ink(self::MUTED);
        $this->SetXY($x + 3.5, $y + 28.8);
        $this->MultiCell($w - 7, 3, mb_strimwidth($t['msg'], 0, 70, '…'), 0, 'L', false, 1, '', '', true, 0, false, true, 6.5, 'T', true);
    }

    /** Cartão de decisão da diretoria: o desvio, a decisão pedida, quem conduz, o prazo e o risco de não agir. */
    public function decisao(float $y, array $d): float
    {
        $w = self::W;
        $bodyW = $w - 12;
        $this->SetFont('dejavusans', 'B', 9);
        $hTitle = $this->getStringHeight($bodyW - 20, $d['titulo']);
        $this->SetFont('dejavusans', '', 8.2);
        $hDec = $this->getStringHeight($bodyW - 22, $d['decisao']);
        $this->SetFont('dejavusans', 'I', 7.4);
        $riscoTxt = 'Se nada for feito: ' . lcfirst($d['risco']);
        $hRisk = $this->getStringHeight($bodyW, $riscoTxt);
        $total = 4 + max($hTitle, 5.4) + 2.5 + $hDec + 3 + 8.5 + $hRisk + 4;
        $y = $this->ensure($y, $total);
        $this->fill('#ffffff');
        $this->stroke(self::LINE, 0.25);
        $this->RoundedRect(self::X, $y, $w, $total, 2.2, '1111', 'DF');
        $this->fill($d['cor']);
        $this->RoundedRect(self::X, $y, 3.4, $total, 1.7, '1001', 'F');
        $x = self::X + 7;
        $cy = $y + 4;
        $this->pill($x, $cy, 13, $d['prioridade'], $d['cor'], 5.4);
        $this->SetFont('dejavusans', 'B', 9);
        $this->ink(self::INK);
        $this->SetXY($x + 16, $cy);
        $this->MultiCell($bodyW - 20, 4.6, $d['titulo'], 0, 'L', false, 1);
        $cy += max($hTitle, 5.4) + 2.5;
        $this->SetFont('dejavusans', 'B', 6.6);
        $this->ink($d['cor']);
        $this->SetXY($x, $cy + 0.4);
        $this->Cell(20, 3.4, 'DECISÃO', 0, 0, 'L');
        $this->SetFont('dejavusans', '', 8.2);
        $this->ink(self::INK);
        $this->SetXY($x + 22, $cy);
        $this->MultiCell($bodyW - 22, 4, $d['decisao'], 0, 'L', false, 1);
        $cy += $hDec + 3;
        $this->fill(self::tint($d['cor'], 0.08));
        $this->RoundedRect($x - 1, $cy, $bodyW + 1, 7.5, 1.3, '1111', 'F');
        $cx = $x + 2;
        foreach ([['RESPONSÁVEL', $d['dono'], 92], ['PRAZO', $d['prazo'] . ' dias', 26], ['META', $d['meta'], 50]] as [$lab, $val, $cw]) {
            $this->SetFont('dejavusans', 'B', 5.6);
            $this->ink(self::MUTED);
            $this->SetXY($cx, $cy + 0.7);
            $this->Cell($cw, 2.8, $lab, 0, 2, 'L');
            $this->SetFont('dejavusans', 'B', 7.2);
            $this->ink(self::INK);
            $this->Cell($cw, 3.6, mb_strimwidth((string) $val, 0, (int) ($cw * 1.15), '…'), 0, 0, 'L');
            $cx += $cw;
        }
        $cy += 8.5;
        $this->SetFont('dejavusans', 'I', 7.4);
        $this->ink(self::MUTED);
        $this->SetXY($x, $cy);
        $this->MultiCell($bodyW, 3.6, $riscoTxt, 0, 'L', false, 1);
        return $y + $total + 3.5;
    }
}
