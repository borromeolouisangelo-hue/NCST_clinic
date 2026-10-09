<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tracking state for inventory items - the realistic tracking methods that
 * replaced the old Full / Half Empty / Empty fill-level display.
 *
 * The schema is FROZEN - medicines has no tracking-method column and no
 * per-medicine fill column - so the method is DERIVED from the unit name and
 * the one piece of mutable state lives in the existing system_settings
 * key/value table:
 *
 *  - Individual (tablet, box, piece, ...): exact piece counts. The TOTAL
 *    PIECES cell shows the package position for counted units - "11/12
 *    deducted" for tablets (12 per package), "20/50 deducted" for boxes
 *    (50 per box), wrapping to 0/N when a fresh package starts - and the
 *    plain remaining count for units without a known package size (piece).
 *    The dispensing form still shows the plain total ("Stock: 103
 *    tablets") and dispensing keeps deducting exact pieces through
 *    deduct_stock_trace, unchanged.
 *
 *  - Container + Partial (bottle, tube, jar, ...): items are consumed from an
 *    opened container while unopened stock stays sealed. The cell reads
 *    "0/16 opened" (opened / total); the longer "8 unopened bottles - 1
 *    opened bottle" line stays as the dispensing modal's Stock label. The
 *    opened count is stored under
 *    `stock_opened_{medicine_id}` (integer). Opening a new container moves one
 *    unit from unopened to opened (no batch change), dispensing from the
 *    opened container records usage only, and Mark Depleted removes the
 *    finished container from stock (one OUT movement + opened -1).
 *
 *  - Roll + Partial (roll): the same model with roll wording -
 *    the cell reads "1/4 opened".
 *
 * Total stock always counts unopened + opened units, so a fresh row shows
 * 9 total = 8 unopened + 1 opened, and stock only drops when an opened
 * container/roll is marked depleted.
 *
 * Every read is defensive: a missing table (the isolated SQLite test
 * connections only create the tables each test needs) yields an empty result
 * or a false return instead of an exception.
 */
class StockUnits
{
    /** Package size per medicine unit, for the Total Pieces "X/N deducted" cell. */
    public const PIECES_PER_PACKAGE = [
        'tablet' => 12,
        'box' => 50,
    ];

    /** Units tracked as Roll + Partial (bandages, tape, gauze rolls). */
    public const ROLL_UNITS = ['roll'];

    /** Units tracked as Container + Partial (bottles, tubes, jars, cans). */
    public const CONTAINER_UNITS = ['bottle', 'tube', 'jar', 'tub', 'can', 'container'];

    /** Pieces per package for a unit, or null when the unit is not tracked. */
    public static function packageSize($unit): ?int
    {
        $key = strtolower(trim((string) $unit));

        return self::PIECES_PER_PACKAGE[$key] ?? null;
    }

    /**
     * Tracking method for a unit: 'individual' | 'container' | 'roll'.
     *
     * Derived from the unit name alone - the schema has no method column.
     */
    public static function method($unit): string
    {
        $key = strtolower(trim((string) $unit));

        if (in_array($key, self::ROLL_UNITS, true)) {
            return 'roll';
        }
        if (in_array($key, self::CONTAINER_UNITS, true)) {
            return 'container';
        }

        return 'individual';
    }

    /** Whether a unit uses Container/Roll + Partial tracking. */
    public static function partial($unit): bool
    {
        return self::method($unit) !== 'individual';
    }

    /** UI noun for a partial item: 'container' or 'roll'. */
    public static function noun($unit): string
    {
        return self::method($unit) === 'roll' ? 'roll' : 'container';
    }

    /**
     * Opened container/roll counts (key = medicine_id) for the given items.
     *
     * Stored in system_settings under `stock_opened_{medicine_id}`; a missing
     * table or key reads as 0 (everything unopened).
     *
     * @param  array<int, int>  $medicineIds
     * @return array<int, int>
     */
    public static function openedCounts(array $medicineIds): array
    {
        $ids = self::cleanIds($medicineIds);
        if ($ids === []) {
            return [];
        }

        $keys = [];
        foreach ($ids as $id) {
            $keys[] = 'stock_opened_'.$id;
        }

        try {
            $rows = DB::table('system_settings')
                ->whereIn('setting_key', $keys)
                ->get();
        } catch (\Throwable $ex) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $id = (int) substr((string) $row->setting_key, strlen('stock_opened_'));
            $opened = (int) $row->setting_value;
            if ($id > 0 && $opened > 0) {
                $counts[$id] = $opened;
            }
        }

        return $counts;
    }

    /** Persist an opened container/roll count. Returns false on failure. */
    public static function setOpened(int $medicineId, int $opened): bool
    {
        $opened = max(0, $opened);
        if ($medicineId <= 0) {
            return false;
        }

        try {
            $key = 'stock_opened_'.$medicineId;
            $updated = DB::table('system_settings')
                ->where('setting_key', $key)
                ->update(['setting_value' => (string) $opened]);

            if ($updated === 0) {
                $exists = DB::table('system_settings')->where('setting_key', $key)->exists();
                if (! $exists) {
                    DB::table('system_settings')->insert([
                        'setting_key' => $key,
                        'setting_value' => (string) $opened,
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
     * `text` is the table-cell copy: "{deducted}/{size} deducted" for
     * package-counted units (tablet/box), the plain stock count for units
     * without a package size (piece), and "{opened}/{stock} opened" for
     * container/roll items. `label` is the longer dispensing-modal line
     * ("Stock: 8 unopened bottles - 1 opened bottle").
     *
     * @param  int|null  $openedCount  prefetched opened count (null = one lazy read)
     * @param  int  $totalOut  prefetched cumulative OUT quantity (package position)
     * @return array{method: string, unit: string, stock: int, opened: int, unopened: int, text: string, label: string, badge: string}
     */
    public static function state(int $medicineId, $unit, int $stock, ?int $openedCount = null, int $totalOut = 0): array
    {
        $method = self::method($unit);
        $unitWord = trim((string) $unit) !== '' ? trim((string) $unit) : 'unit';
        $stock = max(0, $stock);

        if ($method === 'individual') {
            // Quantitative items: how much of the current package has been
            // dispensed - "11/12 deducted", wrapping to 0/12 when the 12th
            // piece opens a fresh package. Units without a known package
            // size (piece) keep the plain remaining count.
            $size = self::packageSize($unitWord);
            if ($size !== null) {
                $deducted = $totalOut % $size;
                if ($deducted < 0) {
                    $deducted += $size;
                }
                $text = $deducted.'/'.$size.' deducted';
            } else {
                $text = (string) $stock;
            }

            return [
                'method' => 'individual',
                'unit' => $unitWord,
                'stock' => $stock,
                'opened' => 0,
                'unopened' => $stock,
                'text' => $text,
                'label' => $stock.' '.self::unitLabel($unitWord, $stock),
                'badge' => 'bg-info text-dark',
            ];
        }

        // Non-quantitative (container/roll) items: "0/16 opened" in the
        // table, the verbose unopened/opened line as the modal label.
        $opened = max(0, $openedCount ?? (self::openedCounts([$medicineId])[$medicineId] ?? 0));
        $unopened = max(0, $stock - $opened);
        $label = $unopened.' unopened '.self::unitLabel($unitWord, $unopened)
            .' · '.$opened.' opened '.self::unitLabel($unitWord, $opened);

        return [
            'method' => $method,
            'unit' => $unitWord,
            'stock' => $stock,
            'opened' => $opened,
            'unopened' => $unopened,
            'text' => min($opened, $stock).'/'.$stock.' opened',
            'label' => $label,
            'badge' => 'bg-secondary',
        ];
    }

    /**
     * Display state for a page of inventory rows (one settings query for the
     * whole page).
     *
     * Each row needs `id`, `unit` and `total_qty` (or `stock` - the dispensing
     * form's medicine list uses that name).
     *
     * @param  array<int, object|array>  $rows
     * @return array<int, array{method: string, unit: string, stock: int, opened: int, unopened: int, text: string, label: string, badge: string}>
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

        $opened = self::openedCounts($ids);
        $outTotals = self::outTotals($ids);

        $states = [];
        foreach ($rows as $row) {
            $id = (int) (is_array($row) ? ($row['id'] ?? 0) : ($row->id ?? 0));
            if ($id <= 0 || isset($states[$id])) {
                continue;
            }
            $unit = (string) (is_array($row) ? ($row['unit'] ?? '') : ($row->unit ?? ''));
            $stock = (int) (is_array($row)
                ? ($row['total_qty'] ?? $row['stock'] ?? 0)
                : ($row->total_qty ?? $row->stock ?? 0));

            $states[$id] = self::state($id, $unit, $stock, $opened[$id] ?? 0, $outTotals[$id] ?? 0);
        }

        return $states;
    }

    /**
     * Cumulative OUT movement quantity per medicine - the package position
     * behind the "{deducted}/{size} deducted" cell. A missing table (the
     * isolated SQLite test connections) reads as no movements.
     *
     * @param  array<int, int>  $medicineIds
     * @return array<int, int>
     */
    private static function outTotals(array $medicineIds): array
    {
        $ids = self::cleanIds($medicineIds);
        if ($ids === []) {
            return [];
        }

        try {
            $rows = DB::table('stock_movements')
                ->whereIn('medicine_id', $ids)
                ->where('type', 'OUT')
                ->select('medicine_id')
                ->selectRaw('COALESCE(SUM(quantity), 0) AS total_out')
                ->groupBy('medicine_id')
                ->get();
        } catch (\Throwable $ex) {
            return [];
        }

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row->medicine_id] = (int) $row->total_out;
        }

        return $totals;
    }

    /**
     * Mark one opened container/roll as depleted - the real deduction of a
     * finished container: one unit leaves the oldest usable batch (OUT
     * movement, ref_type 'depletion') and the opened count drops by one.
     *
     * Returns success/error/unit for the caller's flash and audit row.
     *
     * @return array{success: bool, error: string|null, unit: string}
     */
    public static function depleteOpened(int $medicineId, int $staffId): array
    {
        $row = $medicineId > 0
            ? DB::selectOne('SELECT id, unit FROM medicines WHERE id = ? LIMIT 1', [$medicineId])
            : null;

        if (! $row || ! self::partial($row->unit)) {
            return ['success' => false, 'error' => 'This item does not use container tracking.', 'unit' => ''];
        }

        $unit = (string) $row->unit;
        $opened = self::openedCounts([$medicineId])[$medicineId] ?? 0;
        if ($opened < 1) {
            return ['success' => false, 'error' => 'There is no opened '.self::noun($unit).' to deplete.', 'unit' => $unit];
        }

        try {
            DB::transaction(function () use ($medicineId, $staffId, $opened) {
                // Oldest usable batch first - the same order deduct_stock_trace
                // consumes batches in, so depletion lands on the right row.
                $sql = 'SELECT id, quantity FROM medicine_batches
                          WHERE medicine_id = ? AND quantity > 0
                            AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                          ORDER BY (expiry_date IS NULL), expiry_date, id';
                if (DB::connection()->getDriverName() === 'mysql') {
                    $sql .= ' FOR UPDATE';
                }
                $batch = DB::selectOne($sql, [$medicineId]);

                if ($batch) {
                    DB::table('medicine_batches')
                        ->where('id', (int) $batch->id)
                        ->update(['quantity' => (int) $batch->quantity - 1]);
                    DB::insert(
                        'INSERT INTO stock_movements (medicine_id, batch_id, type, quantity, reason, ref_type, performed_by)
                           VALUES (?,?, "OUT", 1, "Opened container depleted", "depletion", ?)',
                        [$medicineId, (int) $batch->id, $staffId],
                    );
                }

                if (! self::setOpened($medicineId, $opened - 1)) {
                    // Roll back: stock must never move without the state that
                    // explains it (and vice versa).
                    throw new \RuntimeException('Opened-container state could not be updated.');
                }
            });
        } catch (\Throwable $ex) {
            return ['success' => false, 'error' => 'Stock could not be updated. Please try again.', 'unit' => $unit];
        }

        return ['success' => true, 'error' => null, 'unit' => $unit];
    }

    /** Unit word for a count: roll -> rolls, box -> boxes, piece -> pieces. */
    private static function unitLabel(string $unit, int $count): string
    {
        $unit = trim($unit) !== '' ? trim($unit) : 'unit';
        if ($count === 1) {
            return $unit;
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $unit)) {
            return $unit.'es';
        }
        if (preg_match('/[^aeiou]y$/', $unit)) {
            return substr($unit, 0, -1).'ies';
        }

        return $unit.'s';
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
