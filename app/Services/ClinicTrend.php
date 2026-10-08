<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Port of clinic_trend_range() from includes/functions.php:419-462
 * (read-only reference) - the 7/14/30-day requests-vs-consultations series
 * behind the staff dashboard line chart. staff/reports.php (Phase 22) will
 * render the identical trend chart from this same class, exactly like the
 * original's shared function.
 *
 * SQL parity: the original statements (DATE() groups, bound start date) are
 * SQLite-compatible as written; only the fetch style changes (objects ->
 * key => count map instead of PDO::FETCH_KEY_PAIR).
 */
class ClinicTrend
{
    /**
     * @return array{labels: string[], requests: int[], consultations: int[], total: int, change: float|int}
     */
    public static function range(int $days): array
    {
        $start = date('Y-m-d', strtotime('-'.($days - 1).' days'));

        $requests = [];
        foreach (DB::select(
            'SELECT DATE(requested_at) AS d, COUNT(*) AS c FROM medicine_requests WHERE requested_at >= ? GROUP BY DATE(requested_at)',
            [$start],
        ) as $row) {
            $requests[$row->d] = (int) $row->c;
        }

        $consultations = [];
        foreach (DB::select(
            'SELECT DATE(consultation_date) AS d, COUNT(*) AS c FROM consultations WHERE consultation_date >= ? GROUP BY DATE(consultation_date)',
            [$start],
        ) as $row) {
            $consultations[$row->d] = (int) $row->c;
        }

        $labels = [];
        $requestSeries = [];
        $consultationSeries = [];
        $total = 0;
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $count = $requests[$date] ?? 0;
            $labels[] = $days > 7 ? date('d M', strtotime($date)) : date('D', strtotime($date));
            $requestSeries[] = $count;
            $consultationSeries[] = $consultations[$date] ?? 0;
            $total += $count;
        }

        $previousTotal = 0;
        for ($i = $days * 2 - 1; $i >= $days; $i--) {
            $previousTotal += $requests[date('Y-m-d', strtotime("-$i days"))] ?? 0;
        }

        return [
            'labels' => $labels,
            'requests' => $requestSeries,
            'consultations' => $consultationSeries,
            'total' => $total,
            'change' => $previousTotal > 0 ? round((($total - $previousTotal) / $previousTotal) * 100, 1) : 0,
        ];
    }
}
