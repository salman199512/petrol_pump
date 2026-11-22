<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\Station;
use App\Models\ShiftType;
use App\Models\Nozzle;
use App\Models\User;
use App\Models\ShiftReading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    private const CASH_DENOMINATIONS = [2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

    private function mayManageShifts(Request $request): bool
    {
        return $request->user()->hasPermission('shifts.manage');
    }

    private function stationsFor(Request $request)
    {
        return Station::where('active', true)
            ->when($request->user()->role !== 'Super Admin', fn($q) => $q->whereIn('id', $request->user()->stations()->select('stations.id')))
            ->orderBy('name')
            ->get();
    }

    private function canOperateShift(Request $request, Shift $shift): bool
    {
        $user = $request->user();
        return ($shift->operator_id === $user->id && $user->hasPermission('shifts.close'))
            || ($user->hasPermission('shifts.manage') && ($user->role === 'Super Admin' || $user->stations()->where('stations.id', $shift->station_id)->exists()));
    }

    private function denominationTotal(array $counts): int
    {
        $total = 0;
        foreach (self::CASH_DENOMINATIONS as $value) {
            $total += $value * (int)($counts[$value] ?? 0);
        }
        return $total;
    }

    private function latestCheckpointMeters(array $readingIds): array
    {
        if (!$readingIds) return [];
        return DB::table('shift_checkpoint_readings as detail')
            ->join('shift_checkpoints as checkpoint', 'checkpoint.id', '=', 'detail.checkpoint_id')
            ->whereIn('detail.shift_reading_id', $readingIds)
            ->orderByDesc('checkpoint.checkpoint_at')
            ->orderByDesc('checkpoint.id')
            ->get(['detail.shift_reading_id', 'detail.meter_reading'])
            ->groupBy('shift_reading_id')
            ->map(fn($rows) => (string)$rows->first()->meter_reading)
            ->all();
    }

    private function recordShiftExpense(Request $request, Shift $shift, float $amount, string $mode, ?string $remarks): void
    {
        if ($amount <= 0) return;
        $category = DB::table('expense_categories')->where('station_id', $shift->station_id)->where('name', 'Shift expense')->first();
        $categoryId = $category?->id ?? DB::table('expense_categories')->insertGetId([
            'station_id' => $shift->station_id,
            'name' => 'Shift expense',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $id = DB::table('expenses')->insertGetId([
            'station_id' => $shift->station_id,
            'category_id' => $categoryId,
            'shift_id' => $shift->id,
            'operator_id' => $shift->operator_id,
            'created_by' => $request->user()->id,
            'expense_date' => $shift->shift_date->toDateString(),
            'amount' => $amount,
            'payment_mode' => $mode,
            'remarks' => $remarks,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'station_id' => $shift->station_id,
            'action' => 'shift_expense_recorded',
            'entity_type' => 'expense',
            'entity_id' => $id,
            'details' => json_encode(['shift_id' => $shift->id, 'amount' => $amount, 'mode' => $mode]),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($this->mayManageShifts($request), 403);
        $stations = $this->stationsFor($request);
        $stationId = $request->integer('station_id') ?: $stations->first()?->id;
        abort_unless(!$stationId || $stations->contains('id', $stationId), 403);
        $date = $request->date('shift_date')?->toDateString() ?? now()->toDateString();
        $types = collect();
        $operators = collect();
        $nozzles = collect();
        if ($stationId) {
            $types = ShiftType::where('station_id', $stationId)->where('active', true)->orderBy('starts_at')->get();
            $operators = User::where('active', true)->whereHas('roleRecord.permissions', fn($q) => $q->where('permissions.slug', 'shifts.close'))->whereHas('stations', fn($q) => $q->where('stations.id', $stationId))->orderBy('name')->get();
            $busy = DB::table('shift_readings')->join('shifts', 'shifts.id', '=', 'shift_readings.shift_id')->where('shifts.station_id', $stationId)->where('shifts.status', 'Open')->whereDate('shifts.shift_date', $date)->pluck('shift_readings.nozzle_id')->all();
            $nozzles = Nozzle::with('fuelType')->where('station_id', $stationId)->where('active', true)->orderBy('name')->get()->map(function($nozzle) use ($stationId, $date, $busy) {
                $nozzle->already_open = in_array($nozzle->id, $busy);
                $nozzle->daily_rate = DB::table('daily_rates')->where('station_id', $stationId)->where('fuel_type_id', $nozzle->fuel_type_id)->where('rate_date', $date)->value('rate');
                $nozzle->opening_suggestion = DB::table('shift_readings')->join('shifts', 'shifts.id', '=', 'shift_readings.shift_id')->where('shifts.station_id', $stationId)->where('shift_readings.nozzle_id', $nozzle->id)->whereNotNull('shift_readings.closing_reading')->whereDate('shifts.shift_date', '<=', $date)->orderByDesc('shifts.shift_date')->orderByDesc('shift_readings.id')->value('shift_readings.closing_reading') ?? '0.000';
                return $nozzle;
            });
        }
        return view('shifts.create', compact('stations', 'stationId', 'date', 'types', 'operators', 'nozzles'));
    }

    public function store(Request $request)
    {
        abort_unless($this->mayManageShifts($request), 403);
        $data = $request->validate([
            'station_id' => 'required|integer|exists:stations,id',
            'shift_type_id' => 'required|integer|exists:shift_types,id',
            'operator_id' => 'required|integer|exists:users,id',
            'shift_date' => 'required|date',
            'nozzle_ids' => 'required|array|min:1',
            'nozzle_ids.*' => 'required|integer|distinct|exists:nozzles,id',
            'openings' => 'sometimes|array',
            'openings.*' => 'nullable|numeric|min:0|max:999999999.999'
        ]);
        $station = Station::where('active', true)->findOrFail($data['station_id']);
        $user = $request->user();
        abort_unless($user->role === 'Super Admin' || $user->stations()->where('stations.id', $station->id)->exists(), 403);
        abort_unless(ShiftType::where('id', $data['shift_type_id'])->where('station_id', $station->id)->where('active', true)->exists(), 422);
        abort_unless(User::where('id', $data['operator_id'])->where('active', true)->whereHas('roleRecord.permissions', fn($q) => $q->where('permissions.slug', 'shifts.close'))->whereHas('stations', fn($q) => $q->where('stations.id', $station->id))->exists(), 422);

        DB::transaction(function() use ($request, $data, $station) {
            $nozzles = Nozzle::where('station_id', $station->id)->where('active', true)->whereIn('id', $data['nozzle_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($nozzles->count() !== count($data['nozzle_ids'])) {
                throw ValidationException::withMessages(['nozzle_ids' => 'Choose active nozzles assigned to this station.']);
            }
            foreach ($nozzles as $nozzle) {
                if (!isset($data['openings'][$nozzle->id]) || $data['openings'][$nozzle->id] === '') {
                    throw ValidationException::withMessages(['openings.' . $nozzle->id => 'Enter an opening reading for every selected nozzle.']);
                }
            }
            $busy = DB::table('shift_readings')->join('shifts', 'shifts.id', '=', 'shift_readings.shift_id')->where('shifts.station_id', $station->id)->whereDate('shifts.shift_date', $data['shift_date'])->where('shifts.status', 'Open')->whereIn('shift_readings.nozzle_id', $nozzles->pluck('id'))->exists();
            if ($busy) {
                throw ValidationException::withMessages(['nozzle_ids' => 'One or more selected nozzles already have an open shift for this date.']);
            }
            $rates = DB::table('daily_rates')->where('station_id', $station->id)->where('rate_date', $data['shift_date'])->whereIn('fuel_type_id', $nozzles->pluck('fuel_type_id'))->pluck('rate', 'fuel_type_id');
            foreach ($nozzles as $nozzle) {
                if (!$rates->has($nozzle->fuel_type_id)) {
                    throw ValidationException::withMessages(['nozzle_ids' => "Set the {$nozzle->fuelType->name} daily rate for " . \Illuminate\Support\Carbon::parse($data['shift_date'])->format('d M Y') . '.']);
                }
            }
            $shift = Shift::create([
                'station_id' => $station->id,
                'shift_type_id' => $data['shift_type_id'],
                'operator_id' => $data['operator_id'],
                'shift_date' => $data['shift_date'],
                'status' => 'Open',
                'sales_total' => 0,
                'cash_collected' => 0,
                'upi_collected' => 0,
                'bank_collected' => 0,
                'card_collected' => 0,
                'credit_sales' => 0,
                'short_excess' => 0
            ]);
            foreach ($nozzles as $nozzle) {
                ShiftReading::create([
                    'shift_id' => $shift->id,
                    'nozzle_id' => $nozzle->id,
                    'fuel_type_id' => $nozzle->fuel_type_id,
                    'opening_reading' => $data['openings'][$nozzle->id],
                    'rate' => $rates[$nozzle->fuel_type_id],
                    'quantity' => 0,
                    'amount' => 0
                ]);
            }
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $station->id,
                'action' => 'shift_opened',
                'entity_type' => 'shift',
                'entity_id' => $shift->id,
                'details' => json_encode(['operator_id' => $data['operator_id'], 'nozzles' => $nozzles->pluck('id')]),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });
        return redirect()->route('shifts.index')->with('success', 'Shift opened with the selected nozzle readings.');
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('shifts.view'), 403);
        $stations = $this->stationsFor($request);
        $stationId = $request->integer('station_id') ?: $stations->first()?->id;
        abort_unless(!$stationId || $stations->contains('id', $stationId), 403);
        $canManage = $this->mayManageShifts($request);
        return view('shifts.index', compact('stations', 'stationId', 'canManage'));
    }

    public function day(Request $request, string $date)
    {
        abort_unless($request->user()->hasPermission('shifts.view'), 403);
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        abort_unless($parsed && $parsed->format('Y-m-d') === $date, 404);
        $stations = $this->stationsFor($request);
        $stationId = $request->integer('station_id') ?: $stations->first()?->id;
        abort_unless($stationId && $stations->contains('id', $stationId), 403);
        $station = $stations->firstWhere('id', $stationId);

        $shifts = Shift::with([
            'type',
            'operator',
            'readings.nozzle',
            'readings.fuelType',
            'checkpoints.recorder',
            'checkpoints.readings.shiftReading.nozzle',
            'collections.collector',
            'expenses.creator'
        ])
        ->where('station_id', $stationId)
        ->whereDate('shift_date', $date)
        ->orderBy('shift_type_id')
        ->orderBy('id')
        ->get();

        abort_if($shifts->isEmpty(), 404);

        $activeShifts = $shifts->where('status', '<>', 'Voided');

        foreach ($shifts as $shift) {
            $readingIds = $shift->readings->pluck('id')->all();
            $latestMeters = $this->latestCheckpointMeters($readingIds);

            $shiftSales = BigDecimal::zero();
            foreach ($shift->readings as $reading) {
                $latest = $latestMeters[$reading->id] ?? $reading->opening_reading;
                $reading->latest_reading = $latest;

                if ($shift->status === 'Open') {
                    $qty = BigDecimal::of($latest)->minus(BigDecimal::of($reading->opening_reading))->toScale(3, RoundingMode::HALF_UP);
                    $amt = $qty->multipliedBy((string)$reading->rate)->toScale(2, RoundingMode::HALF_UP);
                    $reading->display_quantity = (float)(string)$qty;
                    $reading->display_amount = (float)(string)$amt;
                    $shiftSales = $shiftSales->plus($amt);
                } else {
                    $reading->display_quantity = (float)$reading->quantity;
                    $reading->display_amount = (float)$reading->amount;
                    $shiftSales = $shiftSales->plus(BigDecimal::of((string)$reading->amount));
                }
            }

            $collSums = DB::table('shift_collections')->where('shift_id', $shift->id)
                ->selectRaw('COALESCE(SUM(cash_amount),0) as cash, COALESCE(SUM(upi_amount),0) as upi, COALESCE(SUM(bank_amount),0) as bank, COALESCE(SUM(card_amount),0) as card, COALESCE(SUM(credit_amount),0) as credit')
                ->first();

            $cashTotal = (float)($collSums->cash ?? 0);
            $upiTotal = (float)($collSums->upi ?? 0);
            $bankTotal = (float)($collSums->bank ?? 0);
            $cardTotal = (float)($collSums->card ?? 0);
            $creditTotal = (float)($collSums->credit ?? 0);
            $shiftCollected = $cashTotal + $upiTotal + $bankTotal + $cardTotal + $creditTotal;

            $shiftExpenseTotal = (float)DB::table('expenses')->where('shift_id', $shift->id)->whereNull('deleted_at')->sum('amount');

            if ($shift->status === 'Open') {
                $shift->display_sales = (float)(string)$shiftSales->toScale(2, RoundingMode::HALF_UP);
                $shift->display_cash = $cashTotal;
                $shift->display_upi = $upiTotal;
                $shift->display_bank = $bankTotal;
                $shift->display_card = $cardTotal;
                $shift->display_credit = $creditTotal;
                $shift->display_collected = $shiftCollected;
                $shift->display_expenses = $shiftExpenseTotal;
                $shift->display_diff = $shiftCollected - $shiftExpenseTotal - $shift->display_sales;
            } else {
                $shift->display_sales = (float)$shift->sales_total;
                $shift->display_cash = (float)$shift->cash_collected;
                $shift->display_upi = (float)$shift->upi_collected;
                $shift->display_bank = (float)$shift->bank_collected;
                $shift->display_card = (float)$shift->card_collected;
                $shift->display_credit = (float)$shift->credit_sales;
                $shift->display_collected = (float)$shift->cash_collected + (float)$shift->upi_collected + (float)$shift->bank_collected + (float)$shift->card_collected + (float)$shift->credit_sales;
                $shift->display_expenses = $shiftExpenseTotal;
                $shift->display_diff = (float)$shift->short_excess;
            }

            // Unified Activity Timeline: group by entry timestamp (within 10s) to combine readings, collections & expenses of the same entry
            $rawEvents = collect();

            foreach ($shift->checkpoints as $cp) {
                $rawEvents->push([
                    'type' => 'reading',
                    'checkpoint_id' => $cp->id,
                    'timestamp' => $cp->checkpoint_at,
                    'user' => $cp->recorder?->name ?? 'Operator',
                    'remarks' => $cp->remarks,
                    'data' => $cp->readings->map(fn($r) => [
                        'id' => $r->shift_reading_id,
                        'nozzle' => $r->shiftReading?->nozzle?->name ?? 'Nozzle',
                        'fuel' => $r->shiftReading?->fuelType?->name ?? 'Fuel',
                        'unit' => $r->shiftReading?->fuelType?->unit ?? 'unit',
                        'rate' => (float)($r->shiftReading?->rate ?? 0),
                        'meter' => (float)$r->meter_reading,
                        'qty' => (float)$r->quantity,
                        'amount' => (float)$r->amount,
                    ])->values()->all()
                ]);
            }

            foreach ($shift->collections as $col) {
                $rawEvents->push([
                    'type' => 'collection',
                    'collection_id' => $col->id,
                    'timestamp' => $col->collected_at,
                    'user' => $col->collector?->name ?? 'Operator',
                    'remarks' => $col->remarks,
                    'data' => [
                        'id' => $col->id,
                        'cash' => (float)$col->cash_amount,
                        'upi' => (float)$col->upi_amount,
                        'bank' => (float)$col->bank_amount,
                        'card' => (float)$col->card_amount,
                        'credit' => (float)$col->credit_amount,
                        'total' => (float)$col->cash_amount + (float)$col->upi_amount + (float)$col->bank_amount + (float)$col->card_amount + (float)$col->credit_amount,
                        'denominations' => is_array($col->denominations) ? array_filter($col->denominations, fn($v) => (int)$v > 0) : []
                    ]
                ]);
            }

            foreach ($shift->expenses as $exp) {
                $rawEvents->push([
                    'type' => 'expense',
                    'expense_id' => $exp->id,
                    'timestamp' => $exp->created_at,
                    'user' => $exp->creator?->name ?? 'Staff',
                    'remarks' => $exp->remarks,
                    'data' => [
                        'id' => $exp->id,
                        'amount' => (float)$exp->amount,
                        'mode' => $exp->payment_mode
                    ]
                ]);
            }

            $sorted = $rawEvents->sortBy('timestamp')->values();
            $grouped = collect();

            foreach ($sorted as $item) {
                $time = $item['timestamp'];
                $matchIndex = $grouped->search(function($g) use ($time) {
                    return abs($g['timestamp']->getTimestamp() - $time->getTimestamp()) <= 10;
                });

                if ($matchIndex !== false) {
                    $entry = $grouped[$matchIndex];
                    if ($item['type'] === 'reading') {
                        $entry['checkpoint_id'] = $item['checkpoint_id'];
                        $entry['readings'] = $item['data'];
                        if (!empty($item['remarks'])) $entry['remarks'] = $item['remarks'];
                    } elseif ($item['type'] === 'collection') {
                        $entry['collection_id'] = $item['collection_id'];
                        $entry['collection'] = $item['data'];
                        if (!empty($item['remarks'])) $entry['remarks'] = $item['remarks'];
                    } elseif ($item['type'] === 'expense') {
                        $entry['expense_id'] = $item['expense_id'];
                        $entry['expenses'][] = array_merge($item['data'], ['remarks' => $item['remarks']]);
                    }
                    if ($entry['user'] === 'Operator' || $entry['user'] === 'Staff') {
                        $entry['user'] = $item['user'];
                    }
                    $grouped[$matchIndex] = $entry;
                } else {
                    $entry = [
                        'checkpoint_id' => $item['checkpoint_id'] ?? null,
                        'collection_id' => $item['collection_id'] ?? null,
                        'expense_id' => $item['expense_id'] ?? null,
                        'timestamp' => $time,
                        'date_str' => $time->format('d M Y'),
                        'time_str' => $time->format('h:i A'),
                        'user' => $item['user'],
                        'remarks' => $item['remarks'] ?? null,
                        'readings' => $item['type'] === 'reading' ? $item['data'] : null,
                        'collection' => $item['type'] === 'collection' ? $item['data'] : null,
                        'expenses' => $item['type'] === 'expense' ? [array_merge($item['data'], ['remarks' => $item['remarks']])] : [],
                    ];
                    $grouped->push($entry);
                }
            }

            $shift->activities = $grouped->sortByDesc('timestamp')->values()->map(fn($e) => (object)$e);
        }

        // Daily Fuel Totals
        $fuelTotals = $activeShifts->flatMap->readings->groupBy('fuel_type_id')->map(function($items) {
            $fuel = $items->first()->fuelType;
            $qty = $items->sum('display_quantity');
            $amt = $items->sum('display_amount');
            return (object)[
                'name' => $fuel->name,
                'unit' => $fuel->unit,
                'quantity' => (float)$qty,
                'amount' => (float)$amt
            ];
        })->values();

        // Daily Overview Totals
        $dailyTotals = (object)[
            'sales' => (float)$activeShifts->sum('display_sales'),
            'cash' => (float)$activeShifts->sum('display_cash'),
            'upi' => (float)$activeShifts->sum('display_upi'),
            'bank' => (float)$activeShifts->sum('display_bank'),
            'card' => (float)$activeShifts->sum('display_card'),
            'credit' => (float)$activeShifts->sum('display_credit'),
            'collected' => (float)$activeShifts->sum('display_collected'),
            'expenses' => (float)$activeShifts->sum('display_expenses'),
            'short_excess' => (float)$activeShifts->sum('display_diff'),
        ];

        $canManage = $this->mayManageShifts($request);
        $canCloseIds = $shifts->filter(fn($shift) => $shift->status === 'Open' && (($shift->operator_id === $request->user()->id && $request->user()->hasPermission('shifts.close')) || ($canManage && ($request->user()->role === 'Super Admin' || $request->user()->stations()->where('stations.id', $shift->station_id)->exists()))))->pluck('id');

        return view('shifts.day', compact('date', 'station', 'stationId', 'shifts', 'fuelTotals', 'dailyTotals', 'canManage', 'canCloseIds'));
    }

    public function edit(Request $request, Shift $shift)
    {
        abort_unless($this->mayManageShifts($request) && ($request->user()->role === 'Super Admin' || $request->user()->stations()->where('stations.id', $shift->station_id)->exists()), 403);
        abort_unless($shift->status === 'Open', 422, 'Only open shifts can be edited.');
        $shift->load(['station', 'type', 'operator', 'readings.nozzle', 'readings.fuelType']);
        $types = ShiftType::where('station_id', $shift->station_id)->where('active', true)->orderBy('starts_at')->get();
        $operators = User::where('active', true)->whereHas('roleRecord.permissions', fn($q) => $q->where('permissions.slug', 'shifts.close'))->whereHas('stations', fn($q) => $q->where('stations.id', $shift->station_id))->orderBy('name')->get();
        return view('shifts.edit', compact('shift', 'types', 'operators'));
    }

    public function update(Request $request, Shift $shift)
    {
        abort_unless($this->mayManageShifts($request) && ($request->user()->role === 'Super Admin' || $request->user()->stations()->where('stations.id', $shift->station_id)->exists()), 403);
        abort_unless($shift->status === 'Open', 422, 'Only open shifts can be edited.');
        $data = $request->validate([
            'shift_type_id' => 'required|integer|exists:shift_types,id',
            'operator_id' => 'required|integer|exists:users,id',
            'shift_date' => 'required|date',
            'readings' => 'required|array',
            'readings.*.opening' => 'required|numeric|min:0|max:999999999.999'
        ]);
        abort_unless(ShiftType::where('id', $data['shift_type_id'])->where('station_id', $shift->station_id)->where('active', true)->exists(), 422);
        abort_unless(User::where('id', $data['operator_id'])->where('active', true)->whereHas('roleRecord.permissions', fn($q) => $q->where('permissions.slug', 'shifts.close'))->whereHas('stations', fn($q) => $q->where('stations.id', $shift->station_id))->exists(), 422);
        $readings = $shift->readings()->get();
        $readingIds = $readings->pluck('id')->map(fn($id) => (int)$id)->all();
        $submittedIds = array_map('intval', array_keys($data['readings']));
        if (array_diff($readingIds, $submittedIds) || array_diff($submittedIds, $readingIds)) {
            throw ValidationException::withMessages(['readings' => 'The nozzle readings changed. Reload this form and try again.']);
        }
        $nozzleIds = $readings->pluck('nozzle_id');
        $busy = DB::table('shift_readings')->join('shifts', 'shifts.id', '=', 'shift_readings.shift_id')->where('shifts.station_id', $shift->station_id)->whereDate('shifts.shift_date', $data['shift_date'])->where('shifts.status', 'Open')->where('shifts.id', '<>', $shift->id)->whereIn('shift_readings.nozzle_id', $nozzleIds)->exists();
        if ($busy) {
            throw ValidationException::withMessages(['shift_date' => 'A selected nozzle already has another open shift on this date.']);
        }
        $rates = DB::table('daily_rates')->where('station_id', $shift->station_id)->where('rate_date', $data['shift_date'])->whereIn('fuel_type_id', $readings->pluck('fuel_type_id'))->pluck('rate', 'fuel_type_id');
        foreach ($readings as $reading) {
            if (!$rates->has($reading->fuel_type_id)) {
                throw ValidationException::withMessages(['shift_date' => 'Set every assigned fuel rate for this date before editing the shift.']);
            }
        }
        DB::transaction(function() use ($request, $shift, $data, $readings, $rates) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Open') {
                throw ValidationException::withMessages(['shift' => 'Only open shifts can be edited.']);
            }
            $locked->update([
                'shift_type_id' => $data['shift_type_id'],
                'operator_id' => $data['operator_id'],
                'shift_date' => $data['shift_date']
            ]);
            foreach ($readings as $reading) {
                $reading->update([
                    'opening_reading' => $data['readings'][$reading->id]['opening'],
                    'rate' => $rates[$reading->fuel_type_id]
                ]);
            }
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $shift->station_id,
                'action' => 'shift_updated',
                'entity_type' => 'shift',
                'entity_id' => $shift->id,
                'details' => json_encode(['shift_type_id' => $data['shift_type_id'], 'operator_id' => $data['operator_id'], 'shift_date' => $data['shift_date']]),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });
        return redirect()->route('shifts.day', ['date' => $data['shift_date'], 'station_id' => $shift->station_id])->with('success', 'Open shift updated.');
    }

    public function assignNozzle(Request $request, Shift $shift)
    {
        abort_unless($this->mayManageShifts($request), 403);
        $user = $request->user();
        abort_unless($user->role === 'Super Admin' || $user->stations()->where('stations.id', $shift->station_id)->exists(), 403);
        abort_unless($shift->status === 'Open', 422);
        $data = $request->validate([
            'nozzle_id' => 'required|integer|exists:nozzles,id',
            'operator_id' => 'required|integer|exists:users,id',
            'opening_reading' => 'required|numeric|min:0|max:999999999.999'
        ]);
        DB::transaction(function() use ($request, $shift, $data) {
            $lockedShift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($lockedShift->status !== 'Open') {
                throw ValidationException::withMessages(['shift' => 'Nozzles can only be assigned to an open shift.']);
            }
            $nozzle = Nozzle::whereKey($data['nozzle_id'])->where('station_id', $lockedShift->station_id)->where('active', true)->lockForUpdate()->first();
            if (!$nozzle) {
                throw ValidationException::withMessages(['nozzle_id' => 'Select an active nozzle at this station.']);
            }
            if ($lockedShift->readings()->where('nozzle_id', $nozzle->id)->exists()) {
                throw ValidationException::withMessages(['nozzle_id' => 'This nozzle is already assigned to the selected shift.']);
            }
            $shiftDate = $lockedShift->shift_date->toDateString();
            $busy = DB::table('shift_readings')->join('shifts', 'shifts.id', '=', 'shift_readings.shift_id')->where('shifts.station_id', $lockedShift->station_id)->whereDate('shifts.shift_date', $shiftDate)->where('shifts.status', 'Open')->where('shift_readings.nozzle_id', $nozzle->id)->where('shifts.id', '<>', $lockedShift->id)->exists();
            if ($busy) {
                throw ValidationException::withMessages(['nozzle_id' => 'This nozzle is already assigned to another open shift on this date.']);
            }
            $operator = User::whereKey($data['operator_id'])->where('active', true)->whereHas('roleRecord.permissions', fn($q) => $q->where('permissions.slug', 'shifts.close'))->whereHas('stations', fn($q) => $q->where('stations.id', $lockedShift->station_id))->first();
            if (!$operator) {
                throw ValidationException::withMessages(['operator_id' => 'Choose an active shift operator assigned to this station.']);
            }
            $rate = DB::table('daily_rates')->where('station_id', $lockedShift->station_id)->where('fuel_type_id', $nozzle->fuel_type_id)->where('rate_date', $shiftDate)->value('rate');
            if ($rate === null) {
                throw ValidationException::withMessages(['nozzle_id' => 'Set the daily rate for this fuel before assigning the nozzle.']);
            }
            $lockedShift->update(['operator_id' => $operator->id]);
            $lockedShift->readings()->create([
                'nozzle_id' => $nozzle->id,
                'fuel_type_id' => $nozzle->fuel_type_id,
                'opening_reading' => $data['opening_reading'],
                'rate' => $rate,
                'quantity' => 0,
                'amount' => 0
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $lockedShift->station_id,
                'action' => 'nozzle_assigned_to_shift',
                'entity_type' => 'shift',
                'entity_id' => $lockedShift->id,
                'details' => json_encode(['nozzle_id' => $nozzle->id, 'operator_id' => $operator->id]),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });
        return back()->with('success', 'Nozzle and operator assigned to the shift.');
    }

    public function checkpoint(Request $request, Shift $shift)
    {
        abort_unless($this->canOperateShift($request, $shift), 403);
        abort_unless($shift->status === 'Open', 422, 'Only an open shift can record activity.');

        $data = $request->validate([
            'readings' => 'sometimes|array',
            'readings.*.meter' => 'nullable|numeric|min:0|max:999999999.999',
            'denominations' => 'sometimes|array',
            'denominations.*' => 'nullable|integer|min:0|max:1000000',
            'upi_amount' => 'nullable|numeric|min:0',
            'bank_amount' => 'nullable|numeric|min:0',
            'card_amount' => 'nullable|numeric|min:0',
            'credit_amount' => 'nullable|numeric|min:0',
            'expense_amount' => 'nullable|numeric|min:0',
            'expense_mode' => 'nullable|in:Cash,UPI,Bank,Card',
            'remarks' => 'nullable|string|max:2000'
        ]);

        $shift->load(['readings.nozzle', 'readings.fuelType']);
        $expected = $shift->readings->pluck('id')->map(fn($id) => (int)$id)->all();

        $entered = array_filter($data['readings'] ?? [], fn($reading) => isset($reading['meter']) && $reading['meter'] !== '');

        $denominations = [];
        foreach (self::CASH_DENOMINATIONS as $denom) {
            $count = (int)($data['denominations'][$denom] ?? 0);
            if ($count > 0) {
                $denominations[$denom] = $count;
            }
        }
        $cash = $this->denominationTotal($denominations);
        $upi = (float)($data['upi_amount'] ?? 0);
        $bank = (float)($data['bank_amount'] ?? 0);
        $card = (float)($data['card_amount'] ?? 0);
        $credit = (float)($data['credit_amount'] ?? 0);
        $expense = (float)($data['expense_amount'] ?? 0);

        if ($expense > 0 && empty($data['expense_mode'])) {
            throw ValidationException::withMessages(['expense_mode' => 'Choose how the expense was paid (Cash, UPI, Bank, Card).']);
        }

        if (empty($entered) && $cash <= 0 && $upi <= 0 && $bank <= 0 && $card <= 0 && $credit <= 0 && $expense <= 0) {
            throw ValidationException::withMessages(['activity' => 'Enter at least one meter reading, cash denomination / collection, or expense.']);
        }

        DB::transaction(function() use ($request, $shift, $data, $entered, $expected, $denominations, $cash, $upi, $bank, $card, $credit, $expense) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Open') {
                throw ValidationException::withMessages(['shift' => 'Only an open shift can record activity.']);
            }
            $latest = $this->latestCheckpointMeters($expected);

            if (!empty($entered)) {
                $checkpointId = DB::table('shift_checkpoints')->insertGetId([
                    'shift_id' => $locked->id,
                    'recorded_by' => $request->user()->id,
                    'checkpoint_at' => now(),
                    'remarks' => $data['remarks'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                foreach ($locked->readings as $reading) {
                    $previous = BigDecimal::of($latest[$reading->id] ?? $reading->opening_reading);
                    if (isset($entered[$reading->id]) && $entered[$reading->id]['meter'] !== '') {
                        $meter = BigDecimal::of($entered[$reading->id]['meter']);
                        if ($meter->isLessThan($previous)) {
                            throw ValidationException::withMessages([
                                "readings.{$reading->id}.meter" => "Meter reading for {$reading->nozzle->name} cannot be below previous ({$previous})."
                            ]);
                        }
                    } else {
                        $meter = $previous;
                    }

                    $quantity = $meter->minus($previous)->toScale(3, RoundingMode::HALF_UP);
                    $amount = $quantity->multipliedBy((string)$reading->rate)->toScale(2, RoundingMode::HALF_UP);

                    DB::table('shift_checkpoint_readings')->insert([
                        'checkpoint_id' => $checkpointId,
                        'shift_reading_id' => $reading->id,
                        'meter_reading' => (string)$meter->toScale(3, RoundingMode::HALF_UP),
                        'quantity' => (string)$quantity,
                        'amount' => (string)$amount,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }

            if ($cash > 0 || $upi > 0 || $bank > 0 || $card > 0 || $credit > 0) {
                DB::table('shift_collections')->insert([
                    'shift_id' => $locked->id,
                    'collected_by' => $request->user()->id,
                    'collected_at' => now(),
                    'cash_amount' => $cash,
                    'upi_amount' => $upi,
                    'bank_amount' => $bank,
                    'card_amount' => $card,
                    'credit_amount' => $credit,
                    'denominations' => json_encode($denominations),
                    'remarks' => $data['remarks'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            if ($expense > 0) {
                $this->recordShiftExpense($request, $locked, $expense, $data['expense_mode'] ?? 'Cash', $data['remarks'] ?? null);
            }

            // Update cumulative quantities & amounts on shift_readings
            $latestAll = $this->latestCheckpointMeters($expected);
            $totalSales = BigDecimal::zero();
            foreach ($locked->readings as $reading) {
                $currentMeter = BigDecimal::of($latestAll[$reading->id] ?? $reading->opening_reading);
                $openMeter = BigDecimal::of($reading->opening_reading);
                $cumQty = $currentMeter->minus($openMeter)->toScale(3, RoundingMode::HALF_UP);
                $cumAmount = $cumQty->multipliedBy((string)$reading->rate)->toScale(2, RoundingMode::HALF_UP);
                $reading->update([
                    'quantity' => (string)$cumQty,
                    'amount' => (string)$cumAmount
                ]);
                $totalSales = $totalSales->plus($cumAmount);
            }

            // Update cumulative totals on shift
            $collections = DB::table('shift_collections')->where('shift_id', $locked->id)
                ->selectRaw('COALESCE(SUM(cash_amount),0) as cash, COALESCE(SUM(upi_amount),0) as upi, COALESCE(SUM(bank_amount),0) as bank, COALESCE(SUM(card_amount),0) as card, COALESCE(SUM(credit_amount),0) as credit')
                ->first();
            $collected = BigDecimal::zero();
            foreach (['cash', 'upi', 'bank', 'card', 'credit'] as $mode) {
                $collected = $collected->plus((string)($collections->$mode ?? 0));
            }
            $expenseTotal = (string)DB::table('expenses')->where('shift_id', $locked->id)->whereNull('deleted_at')->sum('amount');
            $difference = $collected->minus($expenseTotal)->minus($totalSales)->toScale(2, RoundingMode::HALF_UP);

            $locked->update([
                'sales_total' => (string)$totalSales->toScale(2, RoundingMode::HALF_UP),
                'cash_collected' => $collections->cash ?? 0,
                'upi_collected' => $collections->upi ?? 0,
                'bank_collected' => $collections->bank ?? 0,
                'card_collected' => $collections->card ?? 0,
                'credit_sales' => $collections->credit ?? 0,
                'short_excess' => (string)$difference
            ]);

            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $locked->station_id,
                'action' => 'shift_activity_recorded',
                'entity_type' => 'shift',
                'entity_id' => $locked->id,
                'details' => json_encode([
                    'meter_readings' => count($entered),
                    'cash' => $cash,
                    'upi' => $upi,
                    'bank' => $bank,
                    'card' => $card,
                    'credit' => $credit,
                    'expense' => $expense,
                    'denominations' => $denominations,
                    'timestamp' => now()->toIso8601String()
                ]),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });

        return back()->with('success', 'Shift reading and collection recorded with timestamp ' . now()->format('h:i A') . '.');
    }

    private function resyncShift(Shift $shift): void
    {
        $shift->load(['readings.nozzle', 'readings.fuelType']);
        
        $checkpoints = DB::table('shift_checkpoints')
            ->where('shift_id', $shift->id)
            ->orderBy('checkpoint_at')
            ->orderBy('id')
            ->get();

        $runningMeters = [];
        foreach ($shift->readings as $r) {
            $runningMeters[$r->id] = BigDecimal::of($r->opening_reading);
        }

        foreach ($checkpoints as $cp) {
            $cpReadings = DB::table('shift_checkpoint_readings')
                ->where('checkpoint_id', $cp->id)
                ->get();

            foreach ($cpReadings as $cpr) {
                $shiftReading = $shift->readings->first(fn($item) => (int)$item->id === (int)$cpr->shift_reading_id);
                if (!$shiftReading) continue;

                $prevMeter = $runningMeters[$shiftReading->id];
                $currentMeter = BigDecimal::of($cpr->meter_reading);
                $qty = $currentMeter->minus($prevMeter)->toScale(3, RoundingMode::HALF_UP);
                $amt = $qty->multipliedBy((string)$shiftReading->rate)->toScale(2, RoundingMode::HALF_UP);

                DB::table('shift_checkpoint_readings')->where('id', $cpr->id)->update([
                    'quantity' => (string)$qty,
                    'amount' => (string)$amt,
                    'updated_at' => now(),
                ]);

                $runningMeters[$shiftReading->id] = $currentMeter;
            }
        }

        $totalSales = BigDecimal::zero();
        foreach ($shift->readings as $r) {
            $finalMeter = $runningMeters[$r->id];
            $openMeter = BigDecimal::of($r->opening_reading);
            $cumQty = $finalMeter->minus($openMeter)->toScale(3, RoundingMode::HALF_UP);
            $cumAmt = $cumQty->multipliedBy((string)$r->rate)->toScale(2, RoundingMode::HALF_UP);

            $r->update([
                'quantity' => (string)$cumQty,
                'amount' => (string)$cumAmt,
                'closing_reading' => $shift->status !== 'Open' ? (string)$finalMeter : $r->closing_reading,
            ]);

            $totalSales = $totalSales->plus($cumAmt);
        }

        $collections = DB::table('shift_collections')->where('shift_id', $shift->id)
            ->selectRaw('COALESCE(SUM(cash_amount),0) as cash, COALESCE(SUM(upi_amount),0) as upi, COALESCE(SUM(bank_amount),0) as bank, COALESCE(SUM(card_amount),0) as card, COALESCE(SUM(credit_amount),0) as credit')
            ->first();

        $collected = BigDecimal::zero();
        foreach (['cash', 'upi', 'bank', 'card', 'credit'] as $mode) {
            $collected = $collected->plus((string)($collections->$mode ?? 0));
        }

        $expenseTotal = (string)DB::table('expenses')->where('shift_id', $shift->id)->whereNull('deleted_at')->sum('amount');
        $difference = $collected->minus($expenseTotal)->minus($totalSales)->toScale(2, RoundingMode::HALF_UP);

        $shift->update([
            'sales_total' => (string)$totalSales->toScale(2, RoundingMode::HALF_UP),
            'cash_collected' => $collections->cash ?? 0,
            'upi_collected' => $collections->upi ?? 0,
            'bank_collected' => $collections->bank ?? 0,
            'card_collected' => $collections->card ?? 0,
            'credit_sales' => $collections->credit ?? 0,
            'short_excess' => (string)$difference
        ]);
    }

    public function updateActivity(Request $request, Shift $shift)
    {
        abort_unless($this->canOperateShift($request, $shift), 403);
        abort_unless($shift->status === 'Open', 422, 'Only an open shift entry can be edited.');

        $data = $request->validate([
            'checkpoint_id' => 'nullable|integer|exists:shift_checkpoints,id',
            'collection_id' => 'nullable|integer|exists:shift_collections,id',
            'expense_id' => 'nullable|integer|exists:expenses,id',
            'readings' => 'sometimes|array',
            'readings.*.meter' => 'nullable|numeric|min:0|max:999999999.999',
            'denominations' => 'sometimes|array',
            'denominations.*' => 'nullable|integer|min:0|max:1000000',
            'upi_amount' => 'nullable|numeric|min:0',
            'bank_amount' => 'nullable|numeric|min:0',
            'card_amount' => 'nullable|numeric|min:0',
            'credit_amount' => 'nullable|numeric|min:0',
            'expense_amount' => 'nullable|numeric|min:0',
            'expense_mode' => 'nullable|in:Cash,UPI,Bank,Card',
            'remarks' => 'nullable|string|max:2000'
        ]);

        DB::transaction(function() use ($request, $shift, $data) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Open') {
                throw ValidationException::withMessages(['shift' => 'Only open shift entries can be edited.']);
            }

            if (!empty($data['checkpoint_id'])) {
                $cp = DB::table('shift_checkpoints')->where('id', $data['checkpoint_id'])->where('shift_id', $locked->id)->first();
                if ($cp) {
                    if (isset($data['remarks'])) {
                        DB::table('shift_checkpoints')->where('id', $cp->id)->update([
                            'remarks' => $data['remarks'],
                            'updated_at' => now(),
                        ]);
                    }

                    if (!empty($data['readings'])) {
                        foreach ($data['readings'] as $shiftReadingId => $row) {
                            if (isset($row['meter']) && $row['meter'] !== '') {
                                DB::table('shift_checkpoint_readings')->updateOrInsert(
                                    ['checkpoint_id' => $cp->id, 'shift_reading_id' => $shiftReadingId],
                                    ['meter_reading' => (string)BigDecimal::of($row['meter'])->toScale(3, RoundingMode::HALF_UP), 'updated_at' => now()]
                                );
                            }
                        }
                    }
                }
            }

            $denominations = [];
            foreach (self::CASH_DENOMINATIONS as $denom) {
                $count = (int)($data['denominations'][$denom] ?? 0);
                if ($count > 0) $denominations[$denom] = $count;
            }
            $cash = $this->denominationTotal($denominations);
            $upi = (float)($data['upi_amount'] ?? 0);
            $bank = (float)($data['bank_amount'] ?? 0);
            $card = (float)($data['card_amount'] ?? 0);
            $credit = (float)($data['credit_amount'] ?? 0);

            if (!empty($data['collection_id'])) {
                $col = DB::table('shift_collections')->where('id', $data['collection_id'])->where('shift_id', $locked->id)->first();
                if ($col) {
                    DB::table('shift_collections')->where('id', $col->id)->update([
                        'cash_amount' => $cash,
                        'upi_amount' => $upi,
                        'bank_amount' => $bank,
                        'card_amount' => $card,
                        'credit_amount' => $credit,
                        'denominations' => json_encode($denominations),
                        'remarks' => $data['remarks'] ?? $col->remarks,
                        'updated_at' => now(),
                    ]);
                }
            } elseif ($cash > 0 || $upi > 0 || $bank > 0 || $card > 0 || $credit > 0) {
                DB::table('shift_collections')->insert([
                    'shift_id' => $locked->id,
                    'collected_by' => $request->user()->id,
                    'collected_at' => now(),
                    'cash_amount' => $cash,
                    'upi_amount' => $upi,
                    'bank_amount' => $bank,
                    'card_amount' => $card,
                    'credit_amount' => $credit,
                    'denominations' => json_encode($denominations),
                    'remarks' => $data['remarks'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            $expenseAmount = (float)($data['expense_amount'] ?? 0);
            if (!empty($data['expense_id'])) {
                $exp = DB::table('expenses')->where('id', $data['expense_id'])->where('shift_id', $locked->id)->first();
                if ($exp) {
                    if ($expenseAmount > 0) {
                        DB::table('expenses')->where('id', $exp->id)->update([
                            'amount' => $expenseAmount,
                            'payment_mode' => $data['expense_mode'] ?? 'Cash',
                            'remarks' => $data['remarks'] ?? $exp->remarks,
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('expenses')->where('id', $exp->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                    }
                }
            } elseif ($expenseAmount > 0) {
                $this->recordShiftExpense($request, $locked, $expenseAmount, $data['expense_mode'] ?? 'Cash', $data['remarks'] ?? null);
            }

            $this->resyncShift($locked);

            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $locked->station_id,
                'action' => 'shift_activity_updated',
                'entity_type' => 'shift',
                'entity_id' => $locked->id,
                'details' => json_encode($data),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });

        return back()->with('success', 'Shift entry updated and totals recalculated.');
    }

    public function deleteActivity(Request $request, Shift $shift)
    {
        abort_unless($this->canOperateShift($request, $shift), 403);
        abort_unless($shift->status === 'Open', 422, 'Only open shift entries can be deleted.');

        $data = $request->validate([
            'checkpoint_id' => 'nullable|integer|exists:shift_checkpoints,id',
            'collection_id' => 'nullable|integer|exists:shift_collections,id',
            'expense_id' => 'nullable|integer|exists:expenses,id',
        ]);

        DB::transaction(function() use ($request, $shift, $data) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Open') {
                throw ValidationException::withMessages(['shift' => 'Only open shift entries can be deleted.']);
            }

            if (!empty($data['checkpoint_id'])) {
                DB::table('shift_checkpoint_readings')->where('checkpoint_id', $data['checkpoint_id'])->delete();
                DB::table('shift_checkpoints')->where('id', $data['checkpoint_id'])->where('shift_id', $locked->id)->delete();
            }

            if (!empty($data['collection_id'])) {
                DB::table('shift_collections')->where('id', $data['collection_id'])->where('shift_id', $locked->id)->delete();
            }

            if (!empty($data['expense_id'])) {
                DB::table('expenses')->where('id', $data['expense_id'])->where('shift_id', $locked->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            }

            $this->resyncShift($locked);

            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $locked->station_id,
                'action' => 'shift_activity_deleted',
                'entity_type' => 'shift',
                'entity_id' => $locked->id,
                'details' => json_encode($data),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });

        return back()->with('success', 'Shift entry deleted and shift totals recalculated.');
    }

    public function close(Request $request, Shift $shift)
    {
        abort_unless($this->canOperateShift($request, $shift), 403);
        if ($shift->status !== 'Open') {
            throw ValidationException::withMessages(['shift' => 'Only open shifts can be submitted.']);
        }

        $input = $request->validate([
            'readings' => 'sometimes|array',
            'readings.*.closing' => 'nullable|numeric|min:0|max:999999999.999',
            'remarks' => 'nullable|string|max:2000'
        ]);

        $shift->load(['readings.nozzle', 'readings.fuelType']);
        $expected = $shift->readings->pluck('id')->map(fn($id) => (int)$id)->all();

        DB::transaction(function() use ($shift, $input, $request, $expected) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Open') {
                throw ValidationException::withMessages(['shift' => 'Only open shifts can be submitted.']);
            }
            $locked->load('readings');
            $latest = $this->latestCheckpointMeters($expected);

            $checkpointId = DB::table('shift_checkpoints')->insertGetId([
                'shift_id' => $locked->id,
                'recorded_by' => $request->user()->id,
                'checkpoint_at' => now(),
                'remarks' => 'Shift Closed: ' . ($input['remarks'] ?? 'Final shift closing'),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $sales = BigDecimal::zero();
            foreach ($locked->readings as $reading) {
                $latestMeter = BigDecimal::of($latest[$reading->id] ?? $reading->opening_reading);
                $submittedClose = isset($input['readings'][$reading->id]['closing']) && $input['readings'][$reading->id]['closing'] !== ''
                    ? BigDecimal::of($input['readings'][$reading->id]['closing'])
                    : $latestMeter;

                if ($submittedClose->isLessThan($latestMeter)) {
                    throw ValidationException::withMessages([
                        "readings.{$reading->id}.closing" => "Closing reading for {$reading->nozzle->name} cannot be below the last recorded reading ({$latestMeter})."
                    ]);
                }

                $opening = BigDecimal::of($reading->opening_reading);
                $totalQty = $submittedClose->minus($opening)->toScale(3, RoundingMode::HALF_UP);
                $totalAmount = $totalQty->multipliedBy((string)$reading->rate)->toScale(2, RoundingMode::HALF_UP);
                $intervalQty = $submittedClose->minus($latestMeter)->toScale(3, RoundingMode::HALF_UP);
                $intervalAmount = $intervalQty->multipliedBy((string)$reading->rate)->toScale(2, RoundingMode::HALF_UP);

                DB::table('shift_checkpoint_readings')->insert([
                    'checkpoint_id' => $checkpointId,
                    'shift_reading_id' => $reading->id,
                    'meter_reading' => (string)$submittedClose->toScale(3, RoundingMode::HALF_UP),
                    'quantity' => (string)$intervalQty,
                    'amount' => (string)$intervalAmount,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                $reading->update([
                    'closing_reading' => (string)$submittedClose->toScale(3, RoundingMode::HALF_UP),
                    'quantity' => (string)$totalQty,
                    'amount' => (string)$totalAmount
                ]);
                $sales = $sales->plus($totalAmount)->toScale(2, RoundingMode::HALF_UP);
            }

            $collections = DB::table('shift_collections')->where('shift_id', $locked->id)
                ->selectRaw('COALESCE(SUM(cash_amount),0) as cash, COALESCE(SUM(upi_amount),0) as upi, COALESCE(SUM(bank_amount),0) as bank, COALESCE(SUM(card_amount),0) as card, COALESCE(SUM(credit_amount),0) as credit')
                ->first();
            $collected = BigDecimal::zero();
            foreach (['cash', 'upi', 'bank', 'card', 'credit'] as $mode) {
                $collected = $collected->plus((string)($collections->$mode ?? 0));
            }
            $expenseTotal = (string)DB::table('expenses')->where('shift_id', $locked->id)->whereNull('deleted_at')->sum('amount');
            $difference = $collected->minus($expenseTotal)->minus($sales)->toScale(2, RoundingMode::HALF_UP);

            $locked->update([
                'cash_collected' => $collections->cash ?? 0,
                'upi_collected' => $collections->upi ?? 0,
                'bank_collected' => $collections->bank ?? 0,
                'card_collected' => $collections->card ?? 0,
                'credit_sales' => $collections->credit ?? 0,
                'sales_total' => (string)$sales,
                'short_excess' => (string)$difference,
                'remarks' => $input['remarks'] ?? $locked->remarks,
                'status' => 'Submitted',
                'submitted_at' => now()
            ]);

            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $locked->station_id,
                'action' => 'shift_closed',
                'entity_type' => 'shift',
                'entity_id' => $locked->id,
                'details' => json_encode([
                    'sales_total' => (string)$sales,
                    'collected' => (string)$collected,
                    'expenses' => $expenseTotal,
                    'short_excess' => (string)$difference
                ]),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });

        return back()->with('success', 'Shift closed and submitted for manager approval.');
    }

    public function decision(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('shifts.manage') && ($user->role === 'Super Admin' || $user->stations()->where('stations.id', $shift->station_id)->exists()), 403);
        $data = $request->validate([
            'decision' => 'required|in:Approved,Rejected',
            'remarks' => 'nullable|string|max:2000'
        ]);
        abort_unless($shift->status === 'Submitted', 422, 'Only submitted shifts can be approved or rejected.');
        DB::transaction(function() use ($shift, $data, $request) {
            $shift->update([
                'status' => $data['decision'],
                'remarks' => $data['remarks'] ?? $shift->remarks,
                'approved_by' => $request->user()->id
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $shift->station_id,
                'action' => 'shift_' . $data['decision'],
                'entity_type' => 'shift',
                'entity_id' => $shift->id,
                'details' => json_encode(['remarks' => $data['remarks'] ?? null]),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });
        return back()->with('success', 'Shift ' . $data['decision'] . '.');
    }

    public function void(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('shifts.manage') && ($user->role === 'Super Admin' || $user->stations()->where('stations.id', $shift->station_id)->exists()), 403);
        $data = $request->validate(['reason' => 'required|string|max:500']);
        DB::transaction(function() use ($request, $shift, $data) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'Open') {
                throw ValidationException::withMessages(['shift' => 'Only an open shift without submitted collections can be voided.']);
            }
            $locked->update([
                'status' => 'Voided',
                'remarks' => trim(($locked->remarks ? $locked->remarks . "\n" : '') . 'Voided: ' . $data['reason'])
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'station_id' => $locked->station_id,
                'action' => 'shift_voided',
                'entity_type' => 'shift',
                'entity_id' => $locked->id,
                'details' => json_encode(['reason' => $data['reason']]),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        });
        return back()->with('success', 'Open shift voided. Readings remain available for audit.');
    }
}
