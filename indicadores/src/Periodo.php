<?php

namespace GlpiPlugin\Indicadores;

use DateTimeImmutable;

/**
 * Períodos das auditorias: quinzenal, mensal, bimestral, trimestral, semestral e anual.
 *
 * Cada período começa numa "fronteira" (dia 1 e 16 na quinzenal; dia 1 do mês inicial nas demais,
 * por calendário: bimestre jan-fev, mar-abr..., trimestre jan-mar..., semestre jan-jun e jul-dez).
 * A auditoria roda no dia da fronteira e avalia o período que acabou de fechar.
 */
class Periodo
{
    public const FREQUENCIAS = [
        'quinzenal'  => 'Quinzenal',
        'mensal'     => 'Mensal',
        'bimestral'  => 'Bimestral',
        'trimestral' => 'Trimestral',
        'semestral'  => 'Semestral',
        'anual'      => 'Anual',
    ];

    /** Meses por período (a quinzenal fica de fora: é meio mês). */
    private const MESES = ['mensal' => 1, 'bimestral' => 2, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

    private const NOMES_MES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    public static function rotulo(string $freq): string
    {
        return self::FREQUENCIAS[$freq] ?? $freq;
    }

    /** Fronteiras (Y-m-d) de um ano, em ordem. */
    private static function fronteiras(string $freq, int $ano): array
    {
        $out = [];
        if ($freq === 'quinzenal') {
            for ($m = 1; $m <= 12; $m++) {
                $out[] = sprintf('%04d-%02d-01', $ano, $m);
                $out[] = sprintf('%04d-%02d-16', $ano, $m);
            }
            return $out;
        }
        $passo = self::MESES[$freq] ?? 1;
        for ($m = 1; $m <= 12; $m += $passo) {
            $out[] = sprintf('%04d-%02d-01', $ano, $m);
        }
        return $out;
    }

    /** Maior fronteira que não passa de $dia. */
    public static function fronteiraAte(string $freq, string $dia): string
    {
        $ano = (int) substr($dia, 0, 4);
        $lista = array_merge(self::fronteiras($freq, $ano - 1), self::fronteiras($freq, $ano));
        $found = $lista[0];
        foreach ($lista as $b) {
            if ($b <= $dia) {
                $found = $b;
            }
        }
        return $found;
    }

    /** Primeira fronteira depois de $dia: quando a próxima auditoria deve rodar. */
    public static function proxima(string $freq, string $dia): string
    {
        $ano = (int) substr($dia, 0, 4);
        foreach (array_merge(self::fronteiras($freq, $ano), self::fronteiras($freq, $ano + 1)) as $b) {
            if ($b > $dia) {
                return $b;
            }
        }
        return sprintf('%04d-01-01', $ano + 1);
    }

    /** Fronteira imediatamente anterior a $fronteira. */
    private static function anterior(string $freq, string $fronteira): string
    {
        $ano = (int) substr($fronteira, 0, 4);
        $found = null;
        foreach (array_merge(self::fronteiras($freq, $ano - 1), self::fronteiras($freq, $ano)) as $b) {
            if ($b < $fronteira) {
                $found = $b;
            }
        }
        return $found ?? sprintf('%04d-01-01', $ano - 1);
    }

    /**
     * Período fechado que a auditoria de $dia avalia: da fronteira anterior até o dia antes da fronteira vigente.
     *
     * @return array{0: string, 1: string} [início, fim] em Y-m-d
     */
    public static function janela(string $freq, string $dia): array
    {
        $vigente = self::fronteiraAte($freq, $dia);
        $inicio  = self::anterior($freq, $vigente);
        $fim     = (new DateTimeImmutable($vigente))->modify('-1 day')->format('Y-m-d');
        return [$inicio, $fim];
    }

    /** Texto do período: "01/10 a 31/10/2026" ou "Out/2026". */
    public static function texto(string $de, string $ate): string
    {
        $a = new DateTimeImmutable($de);
        $b = new DateTimeImmutable($ate);
        if ($a->format('Y-m') === $b->format('Y-m') && $a->format('d') === '01' && $b->format('Y-m-d') === $b->format('Y-m-t')) {
            return self::mes($a->format('Y-m'));
        }
        return $a->format('d/m/Y') . ' a ' . $b->format('d/m/Y');
    }

    /** "2026-10" => "out/2026". */
    public static function mes(string $ym): string
    {
        return self::NOMES_MES[(int) substr($ym, 5, 2) - 1] . '/' . substr($ym, 0, 4);
    }

    public static function mesCurto(int $n): string
    {
        return self::NOMES_MES[$n - 1] ?? '';
    }

    /** Primeiro e último dia de "YYYY-MM". */
    public static function limitesMes(string $ym): array
    {
        $d = new DateTimeImmutable($ym . '-01');
        return [$d->format('Y-m-d'), $d->format('Y-m-t')];
    }

    public static function mesValido(?string $ym): bool
    {
        return is_string($ym) && (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym);
    }

    /** Os 12 meses que terminam em $ym (do mais antigo ao mais novo). */
    public static function ultimosMeses(string $ym, int $n = 12): array
    {
        $out = [];
        $d = new DateTimeImmutable($ym . '-01');
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[] = $d->modify("-$i months")->format('Y-m');
        }
        return $out;
    }
}
