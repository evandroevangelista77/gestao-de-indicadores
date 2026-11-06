<?php

namespace GlpiPlugin\Indicadores;

use GlpiPlugin\Indicadores\Coletor\Ciclo;
use GlpiPlugin\Indicadores\Coletor\Comercial;
use GlpiPlugin\Indicadores\Coletor\Coletor;
use GlpiPlugin\Indicadores\Coletor\Csat;
use GlpiPlugin\Indicadores\Coletor\Enps;
use GlpiPlugin\Indicadores\Coletor\Equip;
use GlpiPlugin\Indicadores\Coletor\Eventos;
use GlpiPlugin\Indicadores\Coletor\Pmo;
use GlpiPlugin\Indicadores\Coletor\Reembolso;
use GlpiPlugin\Indicadores\Coletor\Rh;
use GlpiPlugin\Indicadores\Coletor\Si;
use GlpiPlugin\Indicadores\Coletor\Superintendencia;
use Throwable;

/**
 * Motor de indicadores: pede os valores aos coletores, guarda a foto de cada mês (para o histórico não
 * depender de dados que mudam) e resume cada área numa nota de 0 a 100.
 *
 * A nota de uma área é a média das notas dos seus indicadores ponderada pelo peso; indicadores
 * informativos não entram. Meta, limite, peso e ativação de cada indicador são editáveis em "Metas e limites".
 */
class Motor
{
    public const VALORES = 'glpi_plugin_indicadores_valores';

    private const COLETORES = [Pmo::class, Si::class, Reembolso::class, Comercial::class, Ciclo::class, Eventos::class, Rh::class, Equip::class, Csat::class, Enps::class, Superintendencia::class];

    /** @var array<string, Coletor>|null */
    private static ?array $coletores = null;

    private static ?array $catalogo = null;

    /** Coletores por código de área, na ordem do painel. */
    public static function coletores(): array
    {
        if (self::$coletores === null) {
            self::$coletores = [];
            foreach (self::COLETORES as $class) {
                $c = new $class();
                self::$coletores[$c->codigo()] = $c;
            }
            // áreas incluídas pelo usuário vêm depois das que acompanham o plugin
            self::$coletores += Area::coletores();
        }
        return self::$coletores;
    }

    /** Áreas cujo plugin está instalado. */
    public static function disponiveis(): array
    {
        return array_filter(self::coletores(), fn (Coletor $c) => $c->disponivel());
    }

    /**
     * Catálogo de indicadores por área: código => definição (com meta, limite, peso e ativação editados).
     *
     * @return array<string, array<string, array>> área => código => definição
     */
    public static function catalogo(): array
    {
        if (self::$catalogo !== null) {
            return self::$catalogo;
        }
        global $DB;
        $edit = [];
        if ($DB->tableExists(Meta::getTable())) {
            foreach ($DB->request(['FROM' => Meta::getTable()]) as $row) {
                $edit[$row['code']] = $row;
            }
        }
        $out = [];
        foreach (self::coletores() as $area => $c) {
            foreach ($c->indicadores() as $code => $def) {
                if (isset($edit[$code])) {
                    $e = $edit[$code];
                    $def['meta']   = $e['meta'] === null ? null : (float) $e['meta'];
                    $def['limite'] = $e['limite'] === null ? null : (float) $e['limite'];
                    $def['peso']   = $def['dir'] === 'info' ? 0 : (int) $e['peso'];
                    $def['ativo']  = (int) $e['is_active'] === 1;
                } else {
                    $def['ativo'] = true;
                }
                $out[$area][$code] = $def;
            }
        }
        return self::$catalogo = $out;
    }

    public static function esquecerCatalogo(): void
    {
        self::$catalogo = null;
    }

    /** Esquece os coletores e o catálogo (depois de criar, alterar ou excluir uma área). */
    public static function esquecer(): void
    {
        self::$coletores = null;
        self::$catalogo = null;
    }

    // ------------------------------------------------------------------ coleta e fotos

    /**
     * Valores de todas as áreas disponíveis para um período, sem gravar nada.
     *
     * @return array<string, ?float> código => valor
     */
    public static function calcular(string $de, string $ate, bool $vivo, ?array $areas = null): array
    {
        $out = [];
        foreach (self::disponiveis() as $area => $c) {
            if ($areas !== null && !in_array($area, $areas, true)) {
                continue;
            }
            try {
                $out += $c->coletar($de, $ate, $vivo);
            } catch (Throwable $e) {
                trigger_error('Indicadores: falha ao coletar ' . $area . ': ' . $e->getMessage(), E_USER_WARNING);
            }
        }
        return $out;
    }

    /** Garante a foto do mês: calcula e grava quando ainda não existe (e recalcula o mês corrente de tempos em tempos). */
    public static function garantir(string $ym, bool $forcar = false): void
    {
        global $DB;
        $hoje = date('Y-m');
        if ($ym > $hoje || !$DB->tableExists(self::VALORES)) {
            return;
        }
        // as linhas das áreas personalizadas (código "x...") não contam: lançamentos manuais não significam que o mês foi calculado
        $row = $DB->request(['SELECT' => ['COUNT' => 'id AS n', 'MAX' => 'date_calc AS ult'], 'FROM' => self::VALORES, 'WHERE' => ['period' => $ym, 'NOT' => ['code' => ['LIKE', 'x%']]]])->current();
        $vivo = $ym === $hoje;
        $stale = $vivo && $row['ult'] && strtotime($row['ult']) < time() - 60 * max(1, (int) Menu::config()['refresh_min']);
        if (!$forcar && (int) $row['n'] > 0 && !$stale) {
            return;
        }
        [$de, $ate] = Periodo::limitesMes($ym);
        self::gravar($ym, self::calcular($de, $ate, $vivo));
    }

    /** Recalcula só uma área (para as de chamados recém-criadas) nos últimos meses. */
    public static function recalcularArea(string $area, int $meses = 12): void
    {
        $c = self::coletores()[$area] ?? null;
        if (!$c || !$c->disponivel()) {
            return;
        }
        $hoje = date('Y-m');
        foreach (Periodo::ultimosMeses($hoje, $meses) as $ym) {
            [$de, $ate] = Periodo::limitesMes($ym);
            self::gravar($ym, $c->coletar($de, $ate, $ym === $hoje));
        }
    }

    /** Grava a foto do mês (um valor por indicador; indicadores sem coleta ficam como estão). */
    public static function gravar(string $ym, array $valores): void
    {
        global $DB;
        $now = date('Y-m-d H:i:s');
        foreach ($valores as $code => $value) {
            $DB->updateOrInsert(self::VALORES, ['value' => $value, 'date_calc' => $now], ['code' => $code, 'period' => $ym]);
        }
    }

    /** Recalcula as fotos dos últimos meses (ao instalar, ao pedir "Recalcular" e no cron). */
    public static function recalcular(int $meses = 12): int
    {
        $n = 0;
        foreach (Periodo::ultimosMeses(date('Y-m'), $meses) as $ym) {
            self::garantir($ym, true);
            $n++;
        }
        return $n;
    }

    /**
     * Valores gravados de vários meses.
     *
     * @return array<string, array<string, ?float>> mês => código => valor
     */
    public static function fotos(array $meses): array
    {
        global $DB;
        $out = array_fill_keys($meses, []);
        if (!$meses || !$DB->tableExists(self::VALORES)) {
            return $out;
        }
        foreach ($DB->request(['SELECT' => ['code', 'period', 'value'], 'FROM' => self::VALORES, 'WHERE' => ['period' => $meses]]) as $r) {
            $out[$r['period']][$r['code']] = $r['value'] === null ? null : (float) $r['value'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ notas

    /**
     * Resumo de uma área para um conjunto de valores.
     *
     * @return array{score: ?float, status: string, bad: int, warn: int, ok: int, kpis: array<string, array>}
     */
    public static function resumoArea(string $area, array $valores): array
    {
        $kpis = [];
        $soma = 0.0;
        $pesos = 0;
        $n = ['bad' => 0, 'warn' => 0, 'ok' => 0];
        foreach (self::catalogo()[$area] ?? [] as $code => $def) {
            if (!$def['ativo']) {
                continue;
            }
            $v = array_key_exists($code, $valores) ? $valores[$code] : null;
            [$score, $status] = Score::of($v, $def['dir'], $def['meta'], $def['limite']);
            $kpis[$code] = ['value' => $v, 'score' => $score, 'status' => $status] + $def;
            if ($score !== null && $def['peso'] > 0) {
                $soma += $score * $def['peso'];
                $pesos += $def['peso'];
            }
            if (isset($n[$status])) {
                $n[$status]++;
            }
        }
        $nota = $pesos ? round($soma / $pesos, 1) : null;
        return ['score' => $nota, 'status' => Score::band($nota)] + $n + ['kpis' => $kpis];
    }

    /** Resumo de todas as áreas disponíveis num mês (usa a foto gravada). */
    public static function mes(string $ym): array
    {
        self::garantir($ym);
        $valores = self::fotos([$ym])[$ym];
        $out = [];
        foreach (self::disponiveis() as $area => $c) {
            $out[$area] = self::resumoArea($area, $valores);
        }
        return $out;
    }

    /** Resumo dos doze meses de um ano: mês => área => resumo (meses futuros ficam de fora). */
    public static function ano(int $ano): array
    {
        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $ano, $m);
            if ($ym <= date('Y-m')) {
                self::garantir($ym);
                $meses[] = $ym;
            }
        }
        $fotos = self::fotos($meses);
        $out = [];
        foreach ($meses as $ym) {
            foreach (self::disponiveis() as $area => $c) {
                $out[$ym][$area] = self::resumoArea($area, $fotos[$ym]);
            }
        }
        return $out;
    }

    /** Índice geral: média das notas das áreas (peso igual) e o semáforo. */
    public static function geral(array $areas): array
    {
        $notas = array_filter(array_column($areas, 'score'), fn ($s) => $s !== null);
        $nota = $notas ? round(array_sum($notas) / count($notas), 1) : null;
        return ['score' => $nota, 'status' => Score::band($nota),
            'bad' => count(array_filter($areas, fn ($a) => $a['status'] === Score::BAD)),
            'warn' => count(array_filter($areas, fn ($a) => $a['status'] === Score::WARN)),
            'ok' => count(array_filter($areas, fn ($a) => $a['status'] === Score::OK)),
            'sem' => count(array_filter($areas, fn ($a) => $a['status'] === Score::NONE))];
    }

    /** Série de um indicador nos meses informados (mês => valor; nulo = sem dado). */
    public static function serie(string $code, array $meses): array
    {
        $fotos = self::fotos($meses);
        $out = [];
        foreach ($meses as $ym) {
            $out[$ym] = $fotos[$ym][$code] ?? null;
        }
        return $out;
    }
}
