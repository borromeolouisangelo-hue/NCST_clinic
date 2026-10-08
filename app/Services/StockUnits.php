<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Piece/package state for inventory items (post-migration feature work).
 *
 * The schema is FROZEN - medicines has no pieces_per_unit column and no
 * per-medicine "fill level" column - so both states are DERIVED instead of
 * stored:
 *
 *  - Piece-tracked units (`tablet`, `box`) know their package size from the
 *    unit name alone. The pieces left in the currently open package are
 *    derived from the cumulative OUT movements of that medicine:
 *
 *        pieces = N - (totalOut mod N)      (N = 12 tablets, 50 boxes)
 *
 *    so a fresh item shows 12/12 (50/50), the display walks 11/12 ... 1/12,
 *    and the 12th deduction wraps back to 12/12 while medicine_batches still
 *    loses one unit - existing accounting (deduct_stock_trace, medicine_stock,
 *    low-stock badges, reports) is untouched.
 *
 *  - Non-piece units (bottle, tube, roll, piece, ...) are set manually by
 *    staff as Full / Half Empty / Empty. There is no column for it, so the
 *    level lives in the existing system_settings key/value table under
 *    `stock_level_{medicine_id}`. That table is only ever read back through
 *    known keys elsewhere (AdminSettingsController), so the extra keys are
 *    inert. A missing row falls back to Full when stock > 0, Empty otherwise.
 *
 * Every read is defensive: a missing table (the isolated SQLite test
 * connections only create the tables each test needs) yields an empty result
 * instead of an exception.
 */
class StockUnits
{
    /** Package size per medicine unit. Units not listed are not tracked. */
    public const PIECES_PER_PACKAGE = [
        'tablet' => 12,
        'box' => 50,
    ];

    /** Allowed manual fill levels, in display order. */
    public const LEVELS = ['full', 'half', 'empty'];

    /** Pieces per package for a unit, or null when the unit is not tracked. */
    public static function packageSize($unit): ?int
    {
        $key = strtolower(trim((string) $unit));

        return self::PIECES_PER_PACKAGE[$key] ?? null;
    }

    /**
     * Pieces left in the open package after $totalOut pieces were dispensed.
     *
     * totalOut = 0 -> N (fresh package), totalOut = N-1 -> 1, totalOut = N -> N
     * (wrap: a new package is opened while the batch quantity still fell by 1).
     */
    public static function pieces(int $totalOut, int $packageSize): int
    {
        if ($packageSize < 1) {
            return 1;
        }

        $mod = $totalOut % $packageSize;
        if ($mod < 0) {
            $mod += $packageSize;
        }

        return $packageSize - $mod;
    }

    /**
     * Cumulative OUT quantity per medicine (key = medicine_id).
     *
     * @param  array<int, int>  $medicineIds
     * @return array<int, int>
     */
    public static function outTotals(array $medicineIds): array
    {
        $ids = self::cleanIds($medicineIds);
        if ($ids === []) {
            return [];
        }

        try {
            $rows = DB::select(
                'SELECT medicine_id, COALESCE(SUM(quantity), 0) AS total_out
                   FROM stock_movements
                  WHERE type = \'OUT\'
                    AND medicine_id IN ('.implode(',', array_fill(0, count($ids), '?')).')
                  GROUP BY medicine_id',
                $ids,
            );
        } catch (\Throwable $ex) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->medicine_id] = (int) $row->total_out;
        }

        return $out;
    }

    /**
     * Stored manual fill levels (key = medicine_id) for the given items.
     *
     * @param  array<int, int>  $medicineIds
     * @return array<int, string>
     */
    public static function levels(array $medicineIds): array
    {
        $ids = self::cleanIds($medicineIds);
        if ($ids === []) {
            return [];
        }

        $keys = [];
        foreach ($ids as $id) {
            $keys[] = 'stock_level_'.$id;
        }

        try {
            $rows = DB::table('system_settings')
                ->whereIn('setting_key', $keys)
                ->get();
        } catch (\Throwable $ex) {
            return [];
        }

        $levels = [];
        foreach ($rows as $row) {
            $id = (int) substr((string) $row->setting_key, strlen('stock_level_'));
            $level = strtolower(trim((string) $row->setting_value));
            if ($id > 0 && in_array($level, self::LEVELS, true)) {
                $levels[$id] = $level;
            }
        }

        return $levels;
    }

    /** Stored level for one item, falling back to Full/Empty from its stock. */
    public static function level(int $medicineId, int $stock): string
    {
        $stored = self::levels([$medicineId])[$medicineId] ?? null;
        if ($stored !== null) {
            return $stored;
        }

        return $stock > 0 ? 'full' : 'empty';
    }

    /** Persist a manual fill level. Returns false for an invalid input. */
    public static function setLevel(int $medicineId, string $level): bool
    {
        $level = strtolower(trim($level));
        if ($medicineId <= 0 || ! in_array($level, self::LEVELS, true)) {
            return false;
        }

        try {
            $key = 'stock_level_'.$medicineId;
            $updated = DB::table('system_settings')
                ->where('setting_key', $key)
                ->update(['setting_value' => $level]);

            if ($updated === 0) {
                $exists = DB::table('system_settings')->where('setting_key', $key)->exists();
                if (! $exists) {
                    DB::table('system_settings')->insert([
                        'setting_key' => $key,
                        'setting_value' => $level,
                    ]);
                }
            }
        } catch (\Throwable $ex) {
            return false;
        }

        return true;
    }

    /**
     * Display state for one inventory row.
     *
     * @return array{tracked: bool, size: int|null, pieces: int|null, level: string|null, text: string, badge: string}
     */
    public static function state(int $medicineId, $unit, int $stock, ?int $totalOut = null, ?string $level = null): array
    {
        $size = self::packageSize($unit);

        if ($size !== null) {
            if ($totalOut === null) {
                $totalOut = self::outTotals([$medicineId])[$medicineId] ?? 0;
            }
            $pieces = self::pieces($totalOut, $size);

            return [
                'tracked' => true,
                'size' => $size,
                'pieces' => $pieces,
                'level' => null,
                'text' => $pieces.'/'.$size,
                'badge' => 'bg-info text-dark',
            ];
        }

        $level = $level ?? self::level($medicineId, $stock);

        return [
            'tracked' => false,
            'size' => null,
            'pieces' => null,
            'level' => $level,
            'text' => self::levelLabel($level),
            'badge' => self::levelBadge($level),
        ];
    }

    /**
     * Display state for a page of inventory rows (one OUT query + one
     * settings query for the whole page).
     *
     * Each row needs `id`, `unit` and `total_qty`.
     *
     * @param  array<int, object|array>  $rows
     * @return array<int, array{tracked: bool, size: int|null, pieces: int|null, level: string|null, text: string, badge: string}>
     */
    public static function states($rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) (is_array($row) ? ($row['id'] ?? 0) : ($row->id ?? 0));
        }
        $ids = self::cleanIds($ids);
        if ($ids === []) {
            return [];
        }

        $out = self::outTotals($ids);
        $levels = self::levels($ids);

        $states = [];
        foreach ($rows as $row) {
            $id = (int) (is_array($row) ? ($row['id'] ?? 0) : ($row->id ?? 0));
            if ($id <= 0 || isset($states[$id])) {
                continue;
            }
            $unit = (string) (is_array($row) ? ($row['unit'] ?? '') : ($row->unit ?? ''));
            $stock = (int) (is_array($row) ? ($row['total_qty'] ?? 0) : ($row->total_qty ?? 0));

            $states[$id] = self::state(
                $id,
                $unit,
                $stock,
                $out[$id] ?? 0,
                $levels[$id] ?? null,
            );
        }

        return $states;
    }

    /** Human label for a manual fill level. */
    public static function levelLabel(string $level): string
    {
        return match ($level) {
            'full' => 'Full',
            'half' => 'Half Empty',
            'empty' => 'Empty',
            default => ucfirst($level),
        };
    }

    /** Badge class for a manual fill level: green / orange / red. */
    public static function levelBadge(string $level): string
    {
        return match ($level) {
            'full' => 'bg-success',
            'half' => 'bg-orange',
            'empty' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    /**
     * @param  array<int, mixed>  $medicineIds
     * @return array<int, int>
     */
    private static function cleanIds(array $medicineIds): array
    {
        $ids = [];
        foreach ($medicineIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
