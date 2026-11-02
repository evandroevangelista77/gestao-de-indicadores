<?php

namespace GlpiPlugin\Indicadores;

/**
 * Nota (0 a 100) e semáforo de um indicador a partir do valor, da meta e do limite.
 *
 * Direção "up": quanto maior, melhor (meta acima do limite). "down": quanto menor, melhor.
 * Valor na meta (ou melhor) = 100 e verde; entre a meta e o limite = 50 a 100 e amarelo;
 * além do limite = 0 a 50 e vermelho. Indicador informativo ("info") não tem nota.
 */
class Score
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const BAD  = 'bad';
    public const NONE = 'none';
    public const INFO = 'info';

    /**
     * @return array{0: ?float, 1: string} [nota, semáforo]
     */
    public static function of(?float $value, string $direction, ?float $meta, ?float $limit): array
    {
        if ($value === null) {
            return [null, self::NONE];
        }
        if ($direction === 'info' || $meta === null || $limit === null) {
            return [null, self::INFO];
        }
        if ($direction === 'up') {
            if ($value >= $meta) {
                return [100.0, self::OK];
            }
            if ($value >= $limit) {
                $span = max(0.0001, $meta - $limit);
                return [round(50 + 50 * ($value - $limit) / $span, 1), self::WARN];
            }
            return [round($limit > 0 ? max(0, 50 * $value / $limit) : 0, 1), self::BAD];
        }
        // down
        if ($value <= $meta) {
            return [100.0, self::OK];
        }
        if ($value <= $limit) {
            $span = max(0.0001, $limit - $meta);
            return [round(50 + 50 * ($limit - $value) / $span, 1), self::WARN];
        }
        $over = $limit > 0 ? ($value - $limit) / $limit : 1;
        return [round(max(0, 50 * (1 - $over)), 1), self::BAD];
    }

    /** Semáforo de uma nota de área (a nota mínima de cada faixa vem da configuração). */
    public static function band(?float $score): string
    {
        if ($score === null) {
            return self::NONE;
        }
        $cfg = Menu::config();
        return $score >= (float) $cfg['green_min'] ? self::OK : ($score >= (float) $cfg['yellow_min'] ? self::WARN : self::BAD);
    }

    public static function label(string $status): string
    {
        return [self::OK => 'Saudável', self::WARN => 'Atenção', self::BAD => 'Crítico', self::NONE => 'Sem dados', self::INFO => 'Informativo'][$status] ?? $status;
    }

    /** Formata o valor de um indicador conforme a unidade: %, d (dias), h (horas), n, R$ ou nota. */
    public static function format(?float $value, string $unit): string
    {
        if ($value === null) {
            return '—';
        }
        $dec = $unit === 'R$' ? 2 : (abs($value - round($value)) < 0.05 ? 0 : 1);
        $n = number_format($value, $dec, ',', '.');
        return match ($unit) {
            '%'     => "$n%",
            'd'     => "$n d",
            'h'     => "$n h",
            'R$'    => "R$ $n",
            default => $n,
        };
    }
}
