@extends('layouts.app')
@section('title','Shift day details')
@section('crumb','Shifts / Day details')
@section('content')
<div class="page-head">
    <div>
        <h1>{{ \Illuminate\Support\Carbon::parse($date)->format('l, d M Y') }}</h1>
        <div class="subtle">{{ $station->name }} · Complete shift, nozzle, sales and payment summary</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-light" href="{{ route('shifts.index',['station_id'=>$stationId]) }}">← Back to days</a>
        @if($canManage)
            <a class="btn btn-primary" href="{{ route('shifts.create',['station_id'=>$stationId,'shift_date'=>$date]) }}">＋ New shift</a>
        @endif
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="metric">
            <div class="metric-label">Fuel sales</div>
            <div class="metric-value">₹ {{ number_format($dailyTotals->sales, 2) }}</div>
            <div class="metric-note">Across {{ $shifts->where('status','<>','Voided')->count() }} active shifts</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric">
            <div class="metric-label">Total collections</div>
            <div class="metric-value">₹ {{ number_format($dailyTotals->collected, 2) }}</div>
            <div class="metric-note">Cash, UPI, bank, card and credit</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric">
            <div class="metric-label">Shifts / operators</div>
            <div class="metric-value">{{ $shifts->where('status','<>','Voided')->count() }} / {{ $shifts->where('status','<>','Voided')->pluck('operator_id')->unique()->count() }}</div>
            <div class="metric-note">{{ $shifts->flatMap->readings->count() }} nozzle readings</div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="metric">
            <div class="metric-label">Collection difference</div>
            <div class="metric-value {{ $dailyTotals->short_excess < 0 ? 'text-danger' : 'text-success' }}">₹ {{ number_format($dailyTotals->short_excess, 2) }}</div>
            <div class="metric-note">Collections minus expenses and sales</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-7">
        <section class="panel h-100">
            <h2 class="panel-title">Fuel sold today</h2>
            <div class="panel-sub">Metered volume and sales amount by fuel</div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Fuel</th>
                            <th>Quantity sold</th>
                            <th>Sales amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($fuelTotals as $fuel)
                            <tr>
                                <td><strong>{{ $fuel->name }}</strong></td>
                                <td>{{ number_format($fuel->quantity, 3) }} {{ $fuel->unit }}</td>
                                <td><strong>₹ {{ number_format($fuel->amount, 2) }}</strong></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-muted">No fuel readings yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="panel h-100">
            <h2 class="panel-title">Collection breakdown</h2>
            <div class="panel-sub">Payments &amp; expenses recorded for today</div>
            <div class="payment-list">
                <div>
                    <span><span class="pay-icon-dot bg-success"></span> Cash</span>
                    <strong>₹ {{ number_format($dailyTotals->cash, 2) }}</strong>
                </div>
                <div>
                    <span><span class="pay-icon-dot bg-primary"></span> UPI</span>
                    <strong>₹ {{ number_format($dailyTotals->upi, 2) }}</strong>
                </div>
                <div>
                    <span><span class="pay-icon-dot bg-info"></span> Bank Transfer</span>
                    <strong>₹ {{ number_format($dailyTotals->bank, 2) }}</strong>
                </div>
                <div>
                    <span><span class="pay-icon-dot bg-warning"></span> Card</span>
                    <strong>₹ {{ number_format($dailyTotals->card, 2) }}</strong>
                </div>
                <div>
                    <span><span class="pay-icon-dot bg-secondary"></span> Credit sales</span>
                    <strong>₹ {{ number_format($dailyTotals->credit, 2) }}</strong>
                </div>
                <div class="payment-expense-row">
                    <span><span class="pay-icon-dot bg-danger"></span> Total expenses</span>
                    <strong class="text-warning">₹ {{ number_format($dailyTotals->expenses, 2) }}</strong>
                </div>
                <div class="payment-total">
                    <span>Net collected (after expenses)</span>
                    <strong>₹ {{ number_format($dailyTotals->collected - $dailyTotals->expenses, 2) }}</strong>
                </div>
            </div>
        </section>
    </div>
</div>

<div class="d-flex justify-content-between align-items-end mb-3">
    <div>
        <h2 class="section-title">Shifts and nozzle readings</h2>
        <div class="subtle">Each shift contains its operator, assigned nozzles, meter movements, collections and settlement.</div>
    </div>
</div>

@foreach($shifts as $shift)
    <section class="panel shift-card mb-4" id="shift-card-{{ $shift->id }}">
        <header class="shift-head">
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h3>{{ $shift->type->name }}</h3>
                    <span class="badge-status {{ strtolower($shift->status) }}">{{ $shift->status }}</span>
                    @if($shift->status === 'Open')
                        <span class="live-dot-pill"><span class="live-pulse"></span> Active shift</span>
                    @endif
                </div>
                <div class="subtle mt-1">
                    Operator: <strong>{{ $shift->operator->name }}</strong> · {{ $shift->readings->count() }} nozzle{{ $shift->readings->count() === 1 ? '' : 's' }}
                    @if($shift->submitted_at)
                        · Submitted {{ $shift->submitted_at->format('h:i A') }}
                    @endif
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                @if($canManage && $shift->status === 'Open')
                    <a class="icon-action" href="{{ route('shifts.edit', $shift) }}" title="Edit shift" aria-label="Edit shift">
                        <svg viewBox="0 0 24 24"><path d="m14 5 5 5M4 20l4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16z"/></svg>
                    </a>
                    <button class="icon-action danger-icon" type="button" title="Void shift" aria-label="Void shift" onclick="document.getElementById('void-form-{{ $shift->id }}').classList.toggle('d-none')">
                        <svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg>
                    </button>
                @endif

                @if($canCloseIds->contains($shift->id))
                    {{-- Button 1: Record Reading & Collection (multiple times during shift) --}}
                    <button type="button" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                        data-entry-shift
                        data-shift-id="{{ $shift->id }}"
                        data-shift-label="{{ $shift->type->name }} · {{ $shift->operator->name }}"
                        data-readings="{{ $shift->readings->map(fn($r) => [
                            'id' => $r->id,
                            'name' => $r->nozzle->name,
                            'fuel' => $r->fuelType->name,
                            'unit' => $r->fuelType->unit,
                            'rate' => (float)$r->rate,
                            'opening' => (float)$r->opening_reading,
                            'latest' => (float)($r->latest_reading ?? $r->opening_reading)
                        ])->values()->toJson() }}"
                        data-cumulative="{{ json_encode([
                            'sales' => $shift->display_sales,
                            'cash' => $shift->display_cash,
                            'upi' => $shift->display_upi,
                            'bank' => $shift->display_bank,
                            'card' => $shift->display_card,
                            'credit' => $shift->display_credit,
                            'collected' => $shift->display_collected,
                            'expenses' => $shift->display_expenses,
                            'diff' => $shift->display_diff
                        ]) }}">
                        <svg class="btn-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>
                        Record Reading &amp; Collection
                    </button>

                    {{-- Button 2: Separate Button to ONLY Close Shift --}}
                    <button type="button" class="btn btn-outline-danger btn-sm d-inline-flex align-items-center gap-1"
                        data-close-shift
                        data-shift-id="{{ $shift->id }}"
                        data-shift-label="{{ $shift->type->name }} · {{ $shift->operator->name }}"
                        data-readings="{{ $shift->readings->map(fn($r) => [
                            'id' => $r->id,
                            'name' => $r->nozzle->name,
                            'fuel' => $r->fuelType->name,
                            'unit' => $r->fuelType->unit,
                            'rate' => (float)$r->rate,
                            'opening' => (float)$r->opening_reading,
                            'latest' => (float)($r->latest_reading ?? $r->opening_reading)
                        ])->values()->toJson() }}"
                        data-summary="{{ json_encode([
                            'sales' => $shift->display_sales,
                            'cash' => $shift->display_cash,
                            'upi' => $shift->display_upi,
                            'bank' => $shift->display_bank,
                            'card' => $shift->display_card,
                            'credit' => $shift->display_credit,
                            'collected' => $shift->display_collected,
                            'expenses' => $shift->display_expenses,
                            'diff' => $shift->display_diff
                        ]) }}">
                        <svg class="btn-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Close shift
                    </button>
                @endif
            </div>
        </header>

        @if($canManage && $shift->status === 'Open')
            <form id="void-form-{{ $shift->id }}" class="d-none void-form" method="post" action="{{ route('shifts.void', $shift) }}">
                @csrf
                @method('delete')
                <label class="form-label" for="void-reason-{{ $shift->id }}">Reason for voiding this open shift</label>
                <div class="d-flex gap-2">
                    <input id="void-reason-{{ $shift->id }}" class="form-control" name="reason" maxlength="500" required placeholder="Enter a reason">
                    <button class="btn btn-outline-danger" onclick="return confirm('Void this open shift?')">Confirm void</button>
                </div>
            </form>
        @endif

        <div class="table-responsive">
            <table class="table nozzle-table">
                <thead>
                    <tr>
                        <th>Nozzle</th>
                        <th>Fuel</th>
                        <th>Opening</th>
                        <th>{{ $shift->status === 'Open' ? 'Current Meter' : 'Closing' }}</th>
                        <th>Quantity sold</th>
                        <th>Rate</th>
                        <th>Sales amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($shift->readings as $reading)
                        <tr>
                            <td><strong>{{ $reading->nozzle->name }}</strong></td>
                            <td>{{ $reading->fuelType->name }}</td>
                            <td>{{ number_format($reading->opening_reading, 3) }}</td>
                            <td>
                                @if($shift->status === 'Open')
                                    <span class="badge-meter">{{ number_format($reading->latest_reading ?? $reading->opening_reading, 3) }}</span>
                                    <small class="text-muted ms-1">(latest)</small>
                                @else
                                    {{ $reading->closing_reading === null ? '—' : number_format($reading->closing_reading, 3) }}
                                @endif
                            </td>
                            <td>{{ number_format($reading->display_quantity, 3) }} {{ $reading->fuelType->unit }}</td>
                            <td>₹ {{ number_format($reading->rate, 2) }} / {{ $reading->fuelType->unit }}</td>
                            <td><strong>₹ {{ number_format($reading->display_amount, 2) }}</strong></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-muted">No nozzle readings assigned.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="fuel-shift-totals">
            <div class="d-flex align-items-center gap-2">
                <svg class="fuel-icon" viewBox="0 0 24 24"><path d="M3 22V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v17M15 11h2a2 2 0 0 1 2 2v5a2 2 0 0 0 2 2M6 8h6"/></svg>
                <strong>Shift fuel totals</strong>
            </div>
            <div class="d-flex gap-3 flex-wrap align-items-center ms-auto">
                @foreach($shift->readings->groupBy('fuel_type_id') as $fuelReadings)
                    @php($fuelReading = $fuelReadings->first())
                    <span class="fuel-total-pill">{{ $fuelReading->fuelType->name }}: <strong>{{ number_format($fuelReadings->sum('display_quantity'), 3) }} {{ $fuelReading->fuelType->unit }}</strong> · ₹ {{ number_format($fuelReadings->sum('display_amount'), 2) }}</span>
                @endforeach
            </div>
        </div>

        {{-- Shift Financial Metrics Summary Cards --}}
        <div class="shift-summary-grid">
            <div class="summary-item">
                <span class="item-label">Fuel sales</span>
                <span class="item-value">₹ {{ number_format($shift->display_sales, 2) }}</span>
            </div>
            <div class="summary-item">
                <span class="item-label">Cash</span>
                <span class="item-value text-success">₹ {{ number_format($shift->display_cash, 2) }}</span>
            </div>
            <div class="summary-item">
                <span class="item-label">UPI</span>
                <span class="item-value text-primary">₹ {{ number_format($shift->display_upi, 2) }}</span>
            </div>
            <div class="summary-item">
                <span class="item-label">Bank</span>
                <span class="item-value">₹ {{ number_format($shift->display_bank, 2) }}</span>
            </div>
            <div class="summary-item">
                <span class="item-label">Card</span>
                <span class="item-value">₹ {{ number_format($shift->display_card, 2) }}</span>
            </div>
            <div class="summary-item">
                <span class="item-label">Credit</span>
                <span class="item-value">₹ {{ number_format($shift->display_credit, 2) }}</span>
            </div>
            <div class="summary-item">
                <span class="item-label">Expenses</span>
                <span class="item-value text-warning">₹ {{ number_format($shift->display_expenses, 2) }}</span>
            </div>
            <div class="summary-item item-diff">
                <span class="item-label">Difference</span>
                <div>
                    @if($shift->display_diff < 0)
                        <span class="diff-pill diff-short">₹ {{ number_format($shift->display_diff, 2) }} (Short)</span>
                    @elseif($shift->display_diff > 0)
                        <span class="diff-pill diff-excess">₹ {{ number_format($shift->display_diff, 2) }} (Excess)</span>
                    @else
                        <span class="diff-pill diff-balanced">₹ 0.00 (Balanced)</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Shift Activity Timeline (Clean Grouped Cards by Entry with EDIT & DELETE) --}}
        <details class="shift-activity-accordion" open>
            <summary class="activity-summary-head">
                <span class="d-inline-flex align-items-center gap-2">
                    <svg class="activity-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <strong>Shift Activity Log &amp; Timestamps</strong>
                    <span class="badge bg-white text-dark border ms-1">{{ $shift->activities->count() }} recorded {{ $shift->activities->count() === 1 ? 'entry' : 'entries' }}</span>
                </span>
                <span class="d-inline-flex align-items-center gap-1 text-muted small">
                    <span>Click to toggle</span>
                    <svg class="activity-chevron" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </summary>

            <div class="activity-timeline-body">
                @forelse($shift->activities as $act)
                    <div class="entry-card">
                        {{-- Entry Header with Edit & Delete actions --}}
                        <div class="entry-card-header">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="entry-num-badge">Entry #{{ $shift->activities->count() - $loop->index }}</span>
                                <span class="entry-timestamp"><svg class="clock-mini" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> {{ $act->date_str }} at <strong>{{ $act->time_str }}</strong></span>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <div class="entry-user-tag">
                                    <svg class="user-mini" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <span>{{ $act->user }}</span>
                                </div>

                                @if($shift->status === 'Open' && $canCloseIds->contains($shift->id))
                                    {{-- Edit Entry Button --}}
                                    <button type="button" class="btn btn-outline-secondary btn-sm entry-action-btn"
                                        data-edit-entry
                                        data-shift-id="{{ $shift->id }}"
                                        data-shift-label="{{ $shift->type->name }} · {{ $shift->operator->name }}"
                                        data-entry="{{ json_encode([
                                            'checkpoint_id' => $act->checkpoint_id,
                                            'collection_id' => $act->collection_id,
                                            'expense_id' => $act->expense_id,
                                            'date_str' => $act->date_str,
                                            'time_str' => $act->time_str,
                                            'user' => $act->user,
                                            'remarks' => $act->remarks ?? '',
                                            'readings' => $act->readings ?? [],
                                            'collection' => $act->collection ?? null,
                                            'expenses' => $act->expenses ?? []
                                        ]) }}"
                                        data-all-nozzles="{{ $shift->readings->map(fn($r) => [
                                            'id' => $r->id,
                                            'name' => $r->nozzle->name,
                                            'fuel' => $r->fuelType->name,
                                            'unit' => $r->fuelType->unit,
                                            'rate' => (float)$r->rate,
                                            'opening' => (float)$r->opening_reading
                                        ])->values()->toJson() }}"
                                        title="Edit this entry">
                                        <svg class="entry-btn-icon" viewBox="0 0 24 24"><path d="m14 5 5 5M4 20l4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16z"/></svg>
                                        Edit
                                    </button>

                                    {{-- Delete Entry Button --}}
                                    <button type="button" class="btn btn-outline-danger btn-sm entry-action-btn"
                                        data-delete-entry
                                        data-shift-id="{{ $shift->id }}"
                                        data-checkpoint-id="{{ $act->checkpoint_id }}"
                                        data-collection-id="{{ $act->collection_id }}"
                                        data-expense-id="{{ $act->expense_id }}"
                                        data-time="{{ $act->time_str }}"
                                        title="Delete this entry">
                                        <svg class="entry-btn-icon" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg>
                                        Delete
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="entry-card-body">
                            {{-- 1. Meter Readings Module --}}
                            @if(!empty($act->readings))
                                <div class="entry-module mb-3">
                                    <div class="entry-module-title text-primary">
                                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m14 10-4 4"/></svg>
                                        <span>Nozzle Meter Readings Recorded</span>
                                    </div>
                                    <div class="nozzles-chips-grid">
                                        @foreach($act->readings as $r)
                                            <div class="nozzle-chip">
                                                <div class="nozzle-chip-name">{{ $r['nozzle'] }}</div>
                                                <div class="nozzle-chip-meter">{{ number_format($r['meter'], 3) }}</div>
                                                <div class="nozzle-chip-sales">+{{ number_format($r['qty'], 3) }} · ₹ {{ number_format($r['amount'], 2) }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- 2. Money Collected Module --}}
                            @if(!empty($act->collection) && $act->collection['total'] > 0)
                                <div class="entry-module mb-3">
                                    <div class="entry-module-title text-success">
                                        <svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M7 15h0M2 10h20"/></svg>
                                        <span>Money Collected</span>
                                        <span class="coll-total-pill ms-auto">Total: <strong>₹ {{ number_format($act->collection['total'], 2) }}</strong></span>
                                    </div>

                                    <div class="payments-strip">
                                        @if($act->collection['cash'] > 0)
                                            <span class="pay-tag bg-cash">Cash: <strong>₹ {{ number_format($act->collection['cash'], 2) }}</strong></span>
                                        @endif
                                        @if($act->collection['upi'] > 0)
                                            <span class="pay-tag bg-upi">UPI: <strong>₹ {{ number_format($act->collection['upi'], 2) }}</strong></span>
                                        @endif
                                        @if($act->collection['bank'] > 0)
                                            <span class="pay-tag bg-bank">Bank: <strong>₹ {{ number_format($act->collection['bank'], 2) }}</strong></span>
                                        @endif
                                        @if($act->collection['card'] > 0)
                                            <span class="pay-tag bg-card">Card: <strong>₹ {{ number_format($act->collection['card'], 2) }}</strong></span>
                                        @endif
                                        @if($act->collection['credit'] > 0)
                                            <span class="pay-tag bg-credit">Credit: <strong>₹ {{ number_format($act->collection['credit'], 2) }}</strong></span>
                                        @endif
                                    </div>

                                    @if(!empty($act->collection['denominations']))
                                        <div class="denoms-strip mt-2">
                                            <span class="denoms-strip-label">Currency Denominations:</span>
                                            <div class="d-flex gap-1 flex-wrap">
                                                @foreach($act->collection['denominations'] as $val => $cnt)
                                                    <span class="denom-chip">₹{{ number_format($val) }} × {{ $cnt }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- 3. Shift Expenses Module --}}
                            @if(!empty($act->expenses))
                                @foreach($act->expenses as $exp)
                                    @if($exp['amount'] > 0)
                                        <div class="entry-module mb-2">
                                            <div class="entry-module-title text-warning">
                                                <svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                                <span>Shift Expense Paid: <strong class="text-dark">₹ {{ number_format($exp['amount'], 2) }}</strong></span>
                                                <span class="badge bg-light text-dark border ms-2">via {{ $exp['mode'] }}</span>
                                            </div>
                                            @if(!empty($exp['remarks']))
                                                <div class="expense-reason-text">Reason: {{ $exp['remarks'] }}</div>
                                            @endif
                                        </div>
                                    @endif
                                @endforeach
                            @endif

                            @if(!empty($act->remarks) && empty($act->expenses))
                                <div class="entry-note-text">
                                    <em>Note: {{ $act->remarks }}</em>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-muted small py-3 text-center">No activity recorded yet for this shift. Click <strong>"Record Reading &amp; Collection"</strong> to log meter readings, currency notes and expenses during the shift.</div>
                @endforelse
            </div>
        </details>

        @if($shift->status === 'Submitted' && $canManage)
            <form class="approval-row" method="post" action="{{ route('shifts.decision', $shift) }}">
                @csrf
                <label class="visually-hidden" for="remarks-{{ $shift->id }}">Review remarks</label>
                <input id="remarks-{{ $shift->id }}" name="remarks" class="form-control" placeholder="Approval or rejection remarks">
                <button class="btn btn-primary btn-sm" name="decision" value="Approved">Approve</button>
                <button class="btn btn-outline-danger btn-sm" name="decision" value="Rejected">Reject</button>
            </form>
        @endif
    </section>
@endforeach

{{-- SIDEBAR DRAWER 1: Record Reading & Collection (Add New Entry) --}}
<div class="shift-drawer-backdrop" id="shift-entry-backdrop" hidden></div>
<aside class="shift-drawer" id="shift-entry-drawer" role="dialog" aria-modal="true" aria-labelledby="shift-entry-title" hidden>
    <header class="shift-drawer-head">
        <div>
            <h2 id="shift-entry-title">Record Reading &amp; Collection</h2>
            <p id="shift-entry-meta" class="panel-sub mb-0"></p>
        </div>
        <button type="button" class="icon-action" data-close-entry-drawer aria-label="Close form">×</button>
    </header>

    <div class="live-clock-card mb-3">
        <div class="live-clock-label">
            <span class="live-pulse"></span>
            <span>Recorded At (Date &amp; Timing):</span>
        </div>
        <div class="live-clock-time">
            <span id="entry-clock-date">{{ \Illuminate\Support\Carbon::parse($date)->format('d M Y') }}</span>
            <span class="live-clock-divider">·</span>
            <strong id="entry-clock-time">--:--:--</strong>
        </div>
    </div>

    <form method="post" id="shift-entry-form" data-url-template="{{ route('shifts.checkpoint', ['shift' => '__SHIFT__']) }}">
        @csrf

        {{-- Section 1: Nozzle Meter Readings --}}
        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">1</span>
                <div>
                    <h3 class="drawer-section-title">Nozzle Meter Readings</h3>
                    <p class="drawer-section-sub">Enter current meter reading for nozzles, or leave unchanged.</p>
                </div>
            </div>
            <div id="shift-entry-readings" class="nozzle-entry-grid"></div>
        </div>

        {{-- Section 2: Cash Denominations (Indian Currency: 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1) --}}
        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">2</span>
                <div>
                    <h3 class="drawer-section-title">Cash Collection (Indian Currency Denominations)</h3>
                    <p class="drawer-section-sub">Enter note or coin counts. Cash total calculates automatically.</p>
                </div>
            </div>

            <div class="denomination-card">
                <div class="denomination-table-header">
                    <span>Currency</span>
                    <span>Count</span>
                    <span class="text-end">Amount</span>
                </div>
                <div class="denomination-grid">
                    @foreach([2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] as $denom)
                        <div class="denom-row">
                            <span class="denom-badge">₹ {{ number_format($denom) }}</span>
                            <span class="denom-times">×</span>
                            <div class="denom-input-wrap">
                                <input type="number" min="0" step="1"
                                    class="form-control form-control-sm denom-input"
                                    name="denominations[{{ $denom }}]"
                                    data-value="{{ $denom }}"
                                    placeholder="0"
                                    id="denom-input-{{ $denom }}">
                            </div>
                            <span class="denom-eq">=</span>
                            <span class="denom-subtotal" id="denom-sub-{{ $denom }}">₹ 0.00</span>
                        </div>
                    @endforeach
                </div>
                <div class="denomination-total-bar">
                    <span>Total Cash from Denominations:</span>
                    <strong id="entry-total-cash">₹ 0.00</strong>
                </div>
            </div>
        </div>

        {{-- Section 3: Digital Collections --}}
        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">3</span>
                <div>
                    <h3 class="drawer-section-title">Digital &amp; Other Collections</h3>
                    <p class="drawer-section-sub">Online payments, card swipes and credit sales.</p>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label" for="entry-upi">UPI (₹)</label>
                    <input id="entry-upi" class="form-control payment-input" type="number" name="upi_amount" min="0" step="0.01" value="0">
                </div>
                <div class="col-6">
                    <label class="form-label" for="entry-bank">Bank Transfer (₹)</label>
                    <input id="entry-bank" class="form-control payment-input" type="number" name="bank_amount" min="0" step="0.01" value="0">
                </div>
                <div class="col-6">
                    <label class="form-label" for="entry-card">Card (₹)</label>
                    <input id="entry-card" class="form-control payment-input" type="number" name="card_amount" min="0" step="0.01" value="0">
                </div>
                <div class="col-6">
                    <label class="form-label" for="entry-credit">Credit Sales (₹)</label>
                    <input id="entry-credit" class="form-control payment-input" type="number" name="credit_amount" min="0" step="0.01" value="0">
                </div>
            </div>
            <div class="collection-total-bar mt-2">
                <span>Total Collection (Cash + Digital):</span>
                <strong id="entry-total-collection">₹ 0.00</strong>
            </div>
        </div>

        {{-- Section 4: Price Expense --}}
        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">4</span>
                <div>
                    <h3 class="drawer-section-title">Shift Expense</h3>
                    <p class="drawer-section-sub">Record any spot expense paid out during this shift.</p>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label" for="entry-expense">Expense Amount (₹)</label>
                    <input id="entry-expense" class="form-control" type="number" name="expense_amount" min="0" step="0.01" value="0">
                </div>
                <div class="col-6">
                    <label class="form-label" for="entry-expense-mode">Paid via</label>
                    <select id="entry-expense-mode" name="expense_mode" class="form-select">
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI</option>
                        <option value="Bank">Bank</option>
                        <option value="Card">Card</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="entry-expense-remarks">Expense reason / Remarks</label>
                    <input id="entry-expense-remarks" class="form-control" type="text" name="remarks" maxlength="2000" placeholder="e.g. Refreshments, Generator diesel, Minor repair...">
                </div>
            </div>
        </div>

        {{-- Section 5: Real-Time Summary & Real-Time Difference --}}
        <div class="realtime-summary-box mb-3">
            <div class="realtime-header">
                <div class="d-flex align-items-center gap-2">
                    <span class="live-pulse"></span>
                    <strong>Real-Time Live Calculation &amp; Difference</strong>
                </div>
                <small class="text-muted">Updates as you type</small>
            </div>
            <div class="realtime-metrics-grid">
                <div class="rt-metric">
                    <span class="rt-label">Metered Fuel Sales</span>
                    <strong class="rt-val text-primary" id="rt-entry-sales">₹ 0.00</strong>
                    <small class="rt-note" id="rt-entry-sales-note">This entry</small>
                </div>
                <div class="rt-metric">
                    <span class="rt-label">Total Collected</span>
                    <strong class="rt-val text-success" id="rt-entry-collected">₹ 0.00</strong>
                    <small class="rt-note">Cash + Digital</small>
                </div>
                <div class="rt-metric">
                    <span class="rt-label">Shift Expense</span>
                    <strong class="rt-val text-warning" id="rt-entry-expense">₹ 0.00</strong>
                    <small class="rt-note">Deducted</small>
                </div>
                <div class="rt-metric rt-metric-diff" id="rt-diff-container">
                    <span class="rt-label">Real-Time Difference (Diff)</span>
                    <strong class="rt-val" id="rt-entry-diff">₹ 0.00</strong>
                    <small class="rt-note" id="rt-entry-diff-status">Balanced</small>
                </div>
            </div>

            <div class="shift-cumulative-bar">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Cumulative Shift Balance (Prior Saved + This Entry):</span>
                </div>
                <div class="d-flex justify-content-between flex-wrap gap-2 mt-1 small">
                    <span>Total Sales: <strong id="rt-shift-sales">₹ 0.00</strong></span>
                    <span>Total Collected: <strong id="rt-shift-collected">₹ 0.00</strong></span>
                    <span>Shift Diff: <strong id="rt-shift-diff">₹ 0.00</strong></span>
                </div>
            </div>
        </div>

        <footer class="shift-drawer-actions">
            <button type="button" class="btn btn-light" data-close-entry-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Reading &amp; Collection</button>
        </footer>
    </form>
</aside>

{{-- SIDEBAR DRAWER 2: EDIT SHIFT ENTRY (Modify Existing Entry) --}}
<div class="shift-drawer-backdrop" id="shift-edit-backdrop" hidden></div>
<aside class="shift-drawer" id="shift-edit-drawer" role="dialog" aria-modal="true" aria-labelledby="shift-edit-title" hidden>
    <header class="shift-drawer-head">
        <div>
            <h2 id="shift-edit-title">Edit Shift Entry</h2>
            <p id="shift-edit-meta" class="panel-sub mb-0"></p>
        </div>
        <button type="button" class="icon-action" data-close-edit-drawer aria-label="Close form">×</button>
    </header>

    <div class="alert alert-warning py-2 px-3 small mb-3">
        <strong>Editing this entry:</strong> Any updated meter readings, collections or expenses will recalculate shift totals automatically.
    </div>

    <form method="post" id="shift-edit-form" data-url-template="{{ route('shifts.activity.update', ['shift' => '__SHIFT__']) }}">
        @csrf
        @method('PUT')

        <input type="hidden" name="checkpoint_id" id="edit-checkpoint-id">
        <input type="hidden" name="collection_id" id="edit-collection-id">
        <input type="hidden" name="expense_id" id="edit-expense-id">

        {{-- Section 1: Nozzle Meter Readings --}}
        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">1</span>
                <div>
                    <h3 class="drawer-section-title">Nozzle Meter Readings</h3>
                    <p class="drawer-section-sub">Adjust recorded meter readings if needed.</p>
                </div>
            </div>
            <div id="shift-edit-readings" class="nozzle-entry-grid"></div>
        </div>

        {{-- Section 2: Cash Denominations --}}
        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">2</span>
                <div>
                    <h3 class="drawer-section-title">Cash Collection (Denominations)</h3>
                    <p class="drawer-section-sub">Adjust notes and coins counts.</p>
                </div>
            </div>

            <div class="denomination-card">
                <div class="denomination-table-header">
                    <span>Currency</span>
                    <span>Count</span>
                    <span class="text-end">Amount</span>
                </div>
                <div class="denomination-grid">
                    @foreach([2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] as $denom)
                        <div class="denom-row">
                            <span class="denom-badge">₹ {{ number_format($denom) }}</span>
                            <span class="denom-times">×</span>
                            <div class="denom-input-wrap">
                                <input type="number" min="0" step="1"
                                    class="form-control form-control-sm edit-denom-input"
                                    name="denominations[{{ $denom }}]"
                                    data-value="{{ $denom }}"
                                    placeholder="0"
                                    id="edit-denom-input-{{ $denom }}">
                            </div>
                            <span class="denom-eq">=</span>
                            <span class="denom-subtotal" id="edit-denom-sub-{{ $denom }}">₹ 0.00</span>
                        </div>
                    @endforeach
                </div>
                <div class="denomination-total-bar">
                    <span>Total Cash from Denominations:</span>
                    <strong id="edit-total-cash">₹ 0.00</strong>
                </div>
            </div>
        </div>

        {{-- Section 3: Digital Collections --}}
        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">3</span>
                <div>
                    <h3 class="drawer-section-title">Digital &amp; Other Collections</h3>
                    <p class="drawer-section-sub">UPI, Bank, Card, Credit.</p>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label" for="edit-upi">UPI (₹)</label>
                    <input id="edit-upi" class="form-control" type="number" name="upi_amount" min="0" step="0.01" value="0">
                </div>
                <div class="col-6">
                    <label class="form-label" for="edit-bank">Bank Transfer (₹)</label>
                    <input id="edit-bank" class="form-control" type="number" name="bank_amount" min="0" step="0.01" value="0">
                </div>
                <div class="col-6">
                    <label class="form-label" for="edit-card">Card (₹)</label>
                    <input id="edit-card" class="form-control" type="number" name="card_amount" min="0" step="0.01" value="0">
                </div>
                <div class="col-6">
                    <label class="form-label" for="edit-credit">Credit Sales (₹)</label>
                    <input id="edit-credit" class="form-control" type="number" name="credit_amount" min="0" step="0.01" value="0">
                </div>
            </div>
            <div class="collection-total-bar mt-2">
                <span>Total Collection (Cash + Digital):</span>
                <strong id="edit-total-collection">₹ 0.00</strong>
            </div>
        </div>

        {{-- Section 4: Shift Expense --}}
        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">4</span>
                <div>
                    <h3 class="drawer-section-title">Shift Expense</h3>
                    <p class="drawer-section-sub">Adjust spot expense amount or payment mode.</p>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label" for="edit-expense">Expense Amount (₹)</label>
                    <input id="edit-expense" class="form-control" type="number" name="expense_amount" min="0" step="0.01" value="0">
                </div>
                <div class="col-6">
                    <label class="form-label" for="edit-expense-mode">Paid via</label>
                    <select id="edit-expense-mode" name="expense_mode" class="form-select">
                        <option value="Cash">Cash</option>
                        <option value="UPI">UPI</option>
                        <option value="Bank">Bank</option>
                        <option value="Card">Card</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="edit-expense-remarks">Expense reason / Remarks</label>
                    <input id="edit-expense-remarks" class="form-control" type="text" name="remarks" maxlength="2000" placeholder="Remarks...">
                </div>
            </div>
        </div>

        {{-- Section 5: Real-Time Summary for Edit Drawer --}}
        <div class="realtime-summary-box mb-3">
            <div class="realtime-header">
                <div class="d-flex align-items-center gap-2">
                    <span class="live-pulse"></span>
                    <strong>Updated Entry Totals</strong>
                </div>
                <small class="text-muted">Recalculated</small>
            </div>
            <div class="realtime-metrics-grid">
                <div class="rt-metric">
                    <span class="rt-label">Fuel Sales (This Entry)</span>
                    <strong class="rt-val text-primary" id="edit-rt-sales">₹ 0.00</strong>
                </div>
                <div class="rt-metric">
                    <span class="rt-label">Total Collected</span>
                    <strong class="rt-val text-success" id="edit-rt-collected">₹ 0.00</strong>
                </div>
                <div class="rt-metric">
                    <span class="rt-label">Shift Expense</span>
                    <strong class="rt-val text-warning" id="edit-rt-expense">₹ 0.00</strong>
                </div>
                <div class="rt-metric rt-metric-diff" id="edit-rt-diff-container">
                    <span class="rt-label">Entry Difference</span>
                    <strong class="rt-val" id="edit-rt-diff">₹ 0.00</strong>
                </div>
            </div>
        </div>

        <footer class="shift-drawer-actions">
            <button type="button" class="btn btn-light" data-close-edit-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Update Entry</button>
        </footer>
    </form>
</aside>

{{-- Hidden Form for Deleting a Shift Entry --}}
<form id="shift-delete-entry-form" method="post" data-url-template="{{ route('shifts.activity.destroy', ['shift' => '__SHIFT__']) }}" hidden>
    @csrf
    @method('delete')
    <input type="hidden" name="checkpoint_id" id="del-checkpoint-id">
    <input type="hidden" name="collection_id" id="del-collection-id">
    <input type="hidden" name="expense_id" id="del-expense-id">
</form>

{{-- SIDEBAR MODAL 3: Close Shift Confirmation Modal --}}
<div class="shift-drawer-backdrop" id="shift-close-backdrop" hidden></div>
<aside class="shift-drawer shift-drawer-compact" id="shift-close-drawer" role="dialog" aria-modal="true" aria-labelledby="shift-close-title" hidden>
    <header class="shift-drawer-head">
        <div>
            <h2 id="shift-close-title">Close Shift</h2>
            <p id="shift-close-meta" class="panel-sub mb-0"></p>
        </div>
        <button type="button" class="icon-action" data-close-close-drawer aria-label="Close form">×</button>
    </header>

    <form method="post" id="shift-close-form" data-url-template="{{ route('shifts.close', ['shift' => '__SHIFT__']) }}">
        @csrf

        <div class="alert alert-info py-2 px-3 small mb-3">
            <strong>Closing this shift:</strong> Status will change to <em>Submitted</em> for manager review. All previously recorded readings, collections and expenses will be locked and finalized.
        </div>

        <div class="close-summary-card mb-3">
            <div class="d-flex justify-content-between py-1 border-bottom">
                <span>Total Metered Sales:</span>
                <strong id="close-sum-sales">₹ 0.00</strong>
            </div>
            <div class="d-flex justify-content-between py-1 border-bottom">
                <span>Total Money Collected:</span>
                <strong class="text-success" id="close-sum-collected">₹ 0.00</strong>
            </div>
            <div class="d-flex justify-content-between py-1 border-bottom">
                <span>Total Shift Expenses:</span>
                <strong class="text-warning" id="close-sum-expenses">₹ 0.00</strong>
            </div>
            <div class="d-flex justify-content-between py-2 fw-bold">
                <span>Final Shift Difference:</span>
                <span id="close-sum-diff">₹ 0.00</span>
            </div>
        </div>

        <div class="drawer-section">
            <div class="drawer-section-head">
                <span class="section-step">✓</span>
                <div>
                    <h3 class="drawer-section-title">Final Closing Meter Readings</h3>
                    <p class="drawer-section-sub">Pre-filled with latest recorded readings. Adjust only if meter changed since last entry.</p>
                </div>
            </div>
            <div id="shift-close-readings"></div>
        </div>

        <div class="mt-3">
            <label class="form-label" for="shift-close-remarks">Final Closing Remarks (Optional)</label>
            <textarea id="shift-close-remarks" class="form-control" name="remarks" rows="2" maxlength="2000" placeholder="e.g. Shift handed over smoothly..."></textarea>
        </div>

        <footer class="shift-drawer-actions">
            <button type="button" class="btn btn-light" data-close-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-danger">Confirm &amp; Close Shift</button>
        </footer>
    </form>
</aside>

<script>
(()=>{
    // Entry Drawer Elements
    const entryBackdrop = document.getElementById('shift-entry-backdrop');
    const entryDrawer = document.getElementById('shift-entry-drawer');
    const entryForm = document.getElementById('shift-entry-form');
    const entryMeta = document.getElementById('shift-entry-meta');
    const entryReadings = document.getElementById('shift-entry-readings');
    const entryClockTime = document.getElementById('entry-clock-time');

    const entryTotalCash = document.getElementById('entry-total-cash');
    const entryTotalCollection = document.getElementById('entry-total-collection');
    const entryUpi = document.getElementById('entry-upi');
    const entryBank = document.getElementById('entry-bank');
    const entryCard = document.getElementById('entry-card');
    const entryCredit = document.getElementById('entry-credit');
    const entryExpense = document.getElementById('entry-expense');

    const rtEntrySales = document.getElementById('rt-entry-sales');
    const rtEntrySalesNote = document.getElementById('rt-entry-sales-note');
    const rtEntryCollected = document.getElementById('rt-entry-collected');
    const rtEntryExpense = document.getElementById('rt-entry-expense');
    const rtEntryDiff = document.getElementById('rt-entry-diff');
    const rtEntryDiffStatus = document.getElementById('rt-entry-diff-status');
    const rtDiffContainer = document.getElementById('rt-diff-container');

    const rtShiftSales = document.getElementById('rt-shift-sales');
    const rtShiftCollected = document.getElementById('rt-shift-collected');
    const rtShiftDiff = document.getElementById('rt-shift-diff');

    // Edit Drawer Elements
    const editBackdrop = document.getElementById('shift-edit-backdrop');
    const editDrawer = document.getElementById('shift-edit-drawer');
    const editForm = document.getElementById('shift-edit-form');
    const editMeta = document.getElementById('shift-edit-meta');
    const editReadings = document.getElementById('shift-edit-readings');
    const editCheckpointId = document.getElementById('edit-checkpoint-id');
    const editCollectionId = document.getElementById('edit-collection-id');
    const editExpenseId = document.getElementById('edit-expense-id');

    const editTotalCash = document.getElementById('edit-total-cash');
    const editTotalCollection = document.getElementById('edit-total-collection');
    const editUpi = document.getElementById('edit-upi');
    const editBank = document.getElementById('edit-bank');
    const editCard = document.getElementById('edit-card');
    const editCredit = document.getElementById('edit-credit');
    const editExpense = document.getElementById('edit-expense');
    const editExpenseMode = document.getElementById('edit-expense-mode');
    const editExpenseRemarks = document.getElementById('edit-expense-remarks');

    const editRtSales = document.getElementById('edit-rt-sales');
    const editRtCollected = document.getElementById('edit-rt-collected');
    const editRtExpense = document.getElementById('edit-rt-expense');
    const editRtDiff = document.getElementById('edit-rt-diff');
    const editRtDiffContainer = document.getElementById('edit-rt-diff-container');

    // Delete Form
    const delForm = document.getElementById('shift-delete-entry-form');
    const delCheckpointId = document.getElementById('del-checkpoint-id');
    const delCollectionId = document.getElementById('del-collection-id');
    const delExpenseId = document.getElementById('del-expense-id');

    // Close Modal Elements
    const closeBackdrop = document.getElementById('shift-close-backdrop');
    const closeDrawer = document.getElementById('shift-close-drawer');
    const closeForm = document.getElementById('shift-close-form');
    const closeMeta = document.getElementById('shift-close-meta');
    const closeReadings = document.getElementById('shift-close-readings');
    const closeSumSales = document.getElementById('close-sum-sales');
    const closeSumCollected = document.getElementById('close-sum-collected');
    const closeSumExpenses = document.getElementById('close-sum-expenses');
    const closeSumDiff = document.getElementById('close-sum-diff');

    let currentShiftReadings = [];
    let currentShiftCumulative = { sales: 0, cash: 0, upi: 0, bank: 0, card: 0, credit: 0, collected: 0, expenses: 0, diff: 0 };
    let editEntryData = null;

    const updateClock = () => {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        if (entryClockTime) entryClockTime.textContent = timeStr;
    };
    setInterval(updateClock, 1000);
    updateClock();

    const fmtMoney = (val) => '₹ ' + (Number(val) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtQty = (val) => (Number(val) || 0).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

    // Recalculate for Entry Drawer
    const recalculateRealtime = () => {
        let thisEntrySales = 0;
        let totalDispensedQty = 0;

        currentShiftReadings.forEach(r => {
            const input = document.getElementById(`meter-input-${r.id}`);
            const note = document.getElementById(`meter-note-${r.id}`);
            if (!input || !note) return;

            const enteredVal = input.value.trim();
            if (enteredVal !== '') {
                const enteredNum = Number(enteredVal);
                const prev = Number(r.latest || r.opening || 0);
                if (enteredNum >= prev) {
                    const delta = enteredNum - prev;
                    const amount = delta * Number(r.rate || 0);
                    thisEntrySales += amount;
                    totalDispensedQty += delta;
                    note.textContent = `+${fmtQty(delta)} ${r.unit} · ${fmtMoney(amount)}`;
                    note.className = 'meter-live-note text-success';
                } else {
                    note.textContent = `Reading cannot be below previous (${fmtQty(prev)})`;
                    note.className = 'meter-live-note text-danger';
                }
            } else {
                note.textContent = `Unchanged (at ${fmtQty(r.latest || r.opening)})`;
                note.className = 'meter-live-note text-muted';
            }
        });

        let totalCash = 0;
        document.querySelectorAll('.denom-input').forEach(input => {
            const val = Number(input.dataset.value);
            const count = Math.max(0, parseInt(input.value) || 0);
            const subtotal = count * val;
            totalCash += subtotal;
            const subEl = document.getElementById(`denom-sub-${val}`);
            if (subEl) subEl.textContent = fmtMoney(subtotal);
        });
        if (entryTotalCash) entryTotalCash.textContent = fmtMoney(totalCash);

        const upi = Math.max(0, parseFloat(entryUpi?.value) || 0);
        const bank = Math.max(0, parseFloat(entryBank?.value) || 0);
        const card = Math.max(0, parseFloat(entryCard?.value) || 0);
        const credit = Math.max(0, parseFloat(entryCredit?.value) || 0);
        const thisEntryCollected = totalCash + upi + bank + card + credit;
        if (entryTotalCollection) entryTotalCollection.textContent = fmtMoney(thisEntryCollected);

        const thisEntryExpense = Math.max(0, parseFloat(entryExpense?.value) || 0);
        const thisEntryDiff = thisEntryCollected - thisEntryExpense - thisEntrySales;

        if (rtEntrySales) rtEntrySales.textContent = fmtMoney(thisEntrySales);
        if (rtEntrySalesNote) rtEntrySalesNote.textContent = totalDispensedQty > 0 ? `+${fmtQty(totalDispensedQty)} dispensed` : 'This entry';
        if (rtEntryCollected) rtEntryCollected.textContent = fmtMoney(thisEntryCollected);
        if (rtEntryExpense) rtEntryExpense.textContent = fmtMoney(thisEntryExpense);

        if (rtEntryDiff) {
            rtEntryDiff.textContent = fmtMoney(thisEntryDiff);
            if (Math.abs(thisEntryDiff) < 0.01) {
                rtEntryDiff.className = 'rt-val text-secondary';
                if (rtEntryDiffStatus) rtEntryDiffStatus.textContent = 'Balanced';
                if (rtDiffContainer) rtDiffContainer.className = 'rt-metric rt-metric-diff bg-light';
            } else if (thisEntryDiff > 0) {
                rtEntryDiff.className = 'rt-val text-success';
                if (rtEntryDiffStatus) rtEntryDiffStatus.textContent = 'Excess (+)';
                if (rtDiffContainer) rtDiffContainer.className = 'rt-metric rt-metric-diff bg-success-subtle';
            } else {
                rtEntryDiff.className = 'rt-val text-danger';
                if (rtEntryDiffStatus) rtEntryDiffStatus.textContent = 'Short (-)';
                if (rtDiffContainer) rtDiffContainer.className = 'rt-metric rt-metric-diff bg-danger-subtle';
            }
        }

        const priorSales = Number(currentShiftCumulative.sales || 0);
        const priorCollected = Number(currentShiftCumulative.collected || 0);
        const priorExpenses = Number(currentShiftCumulative.expenses || 0);

        const totalShiftSales = priorSales + thisEntrySales;
        const totalShiftCollected = priorCollected + thisEntryCollected;
        const totalShiftExpenses = priorExpenses + thisEntryExpense;
        const totalShiftDiff = totalShiftCollected - totalShiftExpenses - totalShiftSales;

        if (rtShiftSales) rtShiftSales.textContent = fmtMoney(totalShiftSales);
        if (rtShiftCollected) rtShiftCollected.textContent = fmtMoney(totalShiftCollected);
        if (rtShiftDiff) {
            rtShiftDiff.textContent = fmtMoney(totalShiftDiff);
            rtShiftDiff.className = totalShiftDiff < 0 ? 'text-danger' : 'text-success';
        }
    };

    // Recalculate for Edit Drawer
    const recalculateEditRealtime = () => {
        let editSales = 0;
        document.querySelectorAll('.edit-meter-input').forEach(input => {
            const shiftReadingId = input.dataset.readingId;
            const rate = Number(input.dataset.rate || 0);
            const val = parseFloat(input.value) || 0;
            const prev = parseFloat(input.dataset.prev || 0);
            const delta = Math.max(0, val - prev);
            const amt = delta * rate;
            editSales += amt;
            const note = document.getElementById(`edit-meter-note-${shiftReadingId}`);
            if (note) note.textContent = `+${fmtQty(delta)} · ${fmtMoney(amt)}`;
        });

        let totalCash = 0;
        document.querySelectorAll('.edit-denom-input').forEach(input => {
            const val = Number(input.dataset.value);
            const count = Math.max(0, parseInt(input.value) || 0);
            const subtotal = count * val;
            totalCash += subtotal;
            const subEl = document.getElementById(`edit-denom-sub-${val}`);
            if (subEl) subEl.textContent = fmtMoney(subtotal);
        });
        if (editTotalCash) editTotalCash.textContent = fmtMoney(totalCash);

        const upi = Math.max(0, parseFloat(editUpi?.value) || 0);
        const bank = Math.max(0, parseFloat(editBank?.value) || 0);
        const card = Math.max(0, parseFloat(editCard?.value) || 0);
        const credit = Math.max(0, parseFloat(editCredit?.value) || 0);
        const totalCollected = totalCash + upi + bank + card + credit;
        if (editTotalCollection) editTotalCollection.textContent = fmtMoney(totalCollected);

        const expense = Math.max(0, parseFloat(editExpense?.value) || 0);
        const diff = totalCollected - expense - editSales;

        if (editRtSales) editRtSales.textContent = fmtMoney(editSales);
        if (editRtCollected) editRtCollected.textContent = fmtMoney(totalCollected);
        if (editRtExpense) editRtExpense.textContent = fmtMoney(expense);
        if (editRtDiff) {
            editRtDiff.textContent = fmtMoney(diff);
            editRtDiff.className = diff < 0 ? 'rt-val text-danger' : (diff > 0 ? 'rt-val text-success' : 'rt-val text-secondary');
        }
    };

    document.addEventListener('input', event => {
        if (event.target.closest('#shift-entry-drawer')) recalculateRealtime();
        if (event.target.closest('#shift-edit-drawer')) recalculateEditRealtime();
    });

    const closeEntry = () => {
        entryBackdrop.hidden = true;
        entryDrawer.hidden = true;
        document.body.classList.remove('shift-drawer-open');
    };

    const closeEdit = () => {
        editBackdrop.hidden = true;
        editDrawer.hidden = true;
        document.body.classList.remove('shift-drawer-open');
    };

    const closeClose = () => {
        closeBackdrop.hidden = true;
        closeDrawer.hidden = true;
        document.body.classList.remove('shift-drawer-open');
    };

    document.addEventListener('click', event => {
        // Trigger 1: Open Entry Drawer (Add New Entry)
        const entryTrigger = event.target.closest('[data-entry-shift]');
        if (entryTrigger) {
            try {
                currentShiftReadings = JSON.parse(entryTrigger.dataset.readings || '[]');
                currentShiftCumulative = JSON.parse(entryTrigger.dataset.cumulative || '{}');
            } catch {
                currentShiftReadings = [];
                currentShiftCumulative = {};
            }

            entryReadings.replaceChildren();
            currentShiftReadings.forEach(r => {
                const group = document.createElement('div');
                group.className = 'nozzle-entry-row mb-2';
                const prev = Number(r.latest || r.opening || 0);

                group.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <strong>${r.name}</strong>
                            <span class="badge bg-light text-dark ms-1">${r.fuel} · ₹${r.rate}/${r.unit}</span>
                        </div>
                        <span class="small text-muted">Last recorded: <strong>${fmtQty(prev)}</strong></span>
                    </div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">Current meter</span>
                        <input type="number" step="0.001" min="${prev}"
                            class="form-control meter-reading-input"
                            id="meter-input-${r.id}"
                            name="readings[${r.id}][meter]"
                            placeholder="Enter current reading (min ${prev})">
                    </div>
                    <div class="meter-live-note text-muted" id="meter-note-${r.id}">Unchanged (at ${fmtQty(prev)})</div>
                `;
                entryReadings.append(group);
            });

            document.querySelectorAll('.denom-input').forEach(i => i.value = '');
            document.querySelectorAll('.denom-subtotal').forEach(s => s.textContent = '₹ 0.00');
            if (entryUpi) entryUpi.value = 0;
            if (entryBank) entryBank.value = 0;
            if (entryCard) entryCard.value = 0;
            if (entryCredit) entryCredit.value = 0;
            if (entryExpense) entryExpense.value = 0;
            const remarksInput = document.getElementById('entry-expense-remarks');
            if (remarksInput) remarksInput.value = '';

            entryMeta.textContent = entryTrigger.dataset.shiftLabel || '';
            entryForm.action = entryForm.dataset.urlTemplate.replace('__SHIFT__', encodeURIComponent(entryTrigger.dataset.shiftId));

            recalculateRealtime();

            entryBackdrop.hidden = false;
            entryDrawer.hidden = false;
            document.body.classList.add('shift-drawer-open');
            return;
        }

        // Trigger 2: EDIT AN EXISTING ENTRY
        const editTrigger = event.target.closest('[data-edit-entry]');
        if (editTrigger) {
            let entry = null;
            let allNozzles = [];
            try {
                entry = JSON.parse(editTrigger.dataset.entry || '{}');
                allNozzles = JSON.parse(editTrigger.dataset.allNozzles || '[]');
            } catch {}

            if (!entry) return;
            editEntryData = entry;

            editMeta.textContent = `${editTrigger.dataset.shiftLabel || ''} · Entry at ${entry.time_str} (${entry.date_str})`;
            editForm.action = editForm.dataset.urlTemplate.replace('__SHIFT__', encodeURIComponent(editTrigger.dataset.shiftId));

            editCheckpointId.value = entry.checkpoint_id || '';
            editCollectionId.value = entry.collection_id || '';
            editExpenseId.value = entry.expense_id || '';

            // Render Nozzles for edit
            editReadings.replaceChildren();
            const readingMap = {};
            (entry.readings || []).forEach(r => readingMap[r.id] = r);

            allNozzles.forEach(noz => {
                const recorded = readingMap[noz.id];
                const meterVal = recorded ? recorded.meter : '';
                const group = document.createElement('div');
                group.className = 'nozzle-entry-row mb-2';

                group.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <strong>${noz.name}</strong>
                            <span class="badge bg-light text-dark ms-1">${noz.fuel} · ₹${noz.rate}/${noz.unit}</span>
                        </div>
                        <span class="small text-muted">Opening: ${fmtQty(noz.opening)}</span>
                    </div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">Meter reading</span>
                        <input type="number" step="0.001" min="${noz.opening}"
                            class="form-control edit-meter-input"
                            id="edit-meter-input-${noz.id}"
                            data-reading-id="${noz.id}"
                            data-rate="${noz.rate}"
                            data-prev="${noz.opening}"
                            name="readings[${noz.id}][meter]"
                            value="${meterVal}"
                            placeholder="Meter reading">
                    </div>
                    <div class="meter-live-note text-muted" id="edit-meter-note-${noz.id}"></div>
                `;
                editReadings.append(group);
            });

            // Populate Denominations
            document.querySelectorAll('.edit-denom-input').forEach(i => {
                const denom = i.dataset.value;
                const count = entry.collection?.denominations ? (entry.collection.denominations[denom] || '') : '';
                i.value = count;
            });

            // Populate Digital Payments
            if (editUpi) editUpi.value = entry.collection ? (entry.collection.upi || 0) : 0;
            if (editBank) editBank.value = entry.collection ? (entry.collection.bank || 0) : 0;
            if (editCard) editCard.value = entry.collection ? (entry.collection.card || 0) : 0;
            if (editCredit) editCredit.value = entry.collection ? (entry.collection.credit || 0) : 0;

            // Populate Expense
            if (entry.expenses && entry.expenses.length > 0) {
                if (editExpense) editExpense.value = entry.expenses[0].amount || 0;
                if (editExpenseMode) editExpenseMode.value = entry.expenses[0].mode || 'Cash';
                if (editExpenseRemarks) editExpenseRemarks.value = entry.expenses[0].remarks || '';
            } else {
                if (editExpense) editExpense.value = 0;
                if (editExpenseRemarks) editExpenseRemarks.value = entry.remarks || '';
            }

            recalculateEditRealtime();

            editBackdrop.hidden = false;
            editDrawer.hidden = false;
            document.body.classList.add('shift-drawer-open');
            return;
        }

        // Trigger 3: DELETE AN EXISTING ENTRY
        const deleteTrigger = event.target.closest('[data-delete-entry]');
        if (deleteTrigger) {
            const timeStr = deleteTrigger.dataset.time || '';
            if (confirm(`Are you sure you want to delete this shift entry (${timeStr})?\n\nReadings, collections and expenses recorded in this entry will be removed and shift totals will be recalculated.`)) {
                delForm.action = delForm.dataset.urlTemplate.replace('__SHIFT__', encodeURIComponent(deleteTrigger.dataset.shiftId));
                delCheckpointId.value = deleteTrigger.dataset.checkpointId || '';
                delCollectionId.value = deleteTrigger.dataset.collectionId || '';
                delExpenseId.value = deleteTrigger.dataset.expenseId || '';
                delForm.submit();
            }
            return;
        }

        // Trigger 4: Open Dedicated Close Shift Modal
        const closeTrigger = event.target.closest('[data-close-shift]');
        if (closeTrigger) {
            let readings = [];
            let summary = {};
            try {
                readings = JSON.parse(closeTrigger.dataset.readings || '[]');
                summary = JSON.parse(closeTrigger.dataset.summary || '{}');
            } catch {}

            if (closeSumSales) closeSumSales.textContent = fmtMoney(summary.sales);
            if (closeSumCollected) closeSumCollected.textContent = fmtMoney(summary.collected);
            if (closeSumExpenses) closeSumExpenses.textContent = fmtMoney(summary.expenses);
            if (closeSumDiff) {
                const d = Number(summary.diff || 0);
                closeSumDiff.textContent = fmtMoney(d) + (d < 0 ? ' (Short)' : (d > 0 ? ' (Excess)' : ' (Balanced)'));
                closeSumDiff.className = d < 0 ? 'text-danger' : 'text-success';
            }

            closeReadings.replaceChildren();
            readings.forEach(r => {
                const group = document.createElement('div');
                group.className = 'mb-2';
                const latest = Number(r.latest || r.opening || 0);
                group.innerHTML = `
                    <label class="form-label d-flex justify-content-between mb-1">
                        <span><strong>${r.name}</strong> (${r.fuel})</span>
                        <span class="text-muted small">Opening: ${fmtQty(r.opening)}</span>
                    </label>
                    <input type="number" step="0.001" min="${latest}"
                        class="form-control"
                        name="readings[${r.id}][closing]"
                        value="${latest}"
                        required>
                `;
                closeReadings.append(group);
            });

            closeMeta.textContent = closeTrigger.dataset.shiftLabel || '';
            closeForm.action = closeForm.dataset.urlTemplate.replace('__SHIFT__', encodeURIComponent(closeTrigger.dataset.shiftId));

            closeBackdrop.hidden = false;
            closeDrawer.hidden = false;
            document.body.classList.add('shift-drawer-open');
            return;
        }

        if (event.target === entryBackdrop || event.target.closest('[data-close-entry-drawer]')) closeEntry();
        if (event.target === editBackdrop || event.target.closest('[data-close-edit-drawer]')) closeEdit();
        if (event.target === closeBackdrop || event.target.closest('[data-close-close-drawer]')) closeClose();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            if (!entryDrawer.hidden) closeEntry();
            if (!editDrawer.hidden) closeEdit();
            if (!closeDrawer.hidden) closeClose();
        }
    });
})();
</script>

<style>
/* Page & Card Base */
.section-title{font-size:17px;font-weight:600;color:#19392e;margin:0}
.nozzle-table{margin-top:5px}
.shift-card{padding:0 20px 22px}
.shift-head{display:flex;justify-content:space-between;align-items:center;gap:15px;padding:17px 0;border-bottom:1px solid #edf1ee}
.shift-head h3{font-size:16px;margin:0;font-weight:600;color:#17392d}
.btn-icon{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.approval-row{display:flex;gap:8px;align-items:center;padding-top:16px}
.approval-row .form-control{max-width:370px}
.void-form{padding:12px 0;border-bottom:1px solid #eef1ef}
.danger-icon{color:#a53d36}

/* Status Indicators */
.live-dot-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;background:#eefbf3;color:#1a7f4c}
.live-pulse{width:7px;height:7px;border-radius:50%;background:#1db954;box-shadow:0 0 0 0 rgba(29,185,84,0.7);animation:pulse-live 1.8s infinite}
@keyframes pulse-live{0%{transform:scale(.95);box-shadow:0 0 0 0 rgba(29,185,84,0.7)}70%{transform:scale(1);box-shadow:0 0 0 6px rgba(29,185,84,0)}100%{transform:scale(.95);box-shadow:0 0 0 0 rgba(29,185,84,0)}}
.badge-meter{font-weight:600;color:#194d37;background:#eef6f1;padding:2px 7px;border-radius:4px}

/* Collection Breakdown Panel */
.payment-list{margin-top:10px}
.payment-list>div{display:flex;justify-content:space-between;align-items:center;padding:9px 4px;border-bottom:1px solid #eff3f0;font-size:13px}
.payment-list>div span{color:#576b60;display:inline-flex;align-items:center;gap:7px}
.payment-list>div strong{color:#1a392e;font-weight:600}
.pay-icon-dot{width:7px;height:7px;border-radius:50%;display:inline-block}
.payment-list .payment-expense-row{background:#fdfcf7;border-radius:6px;padding:9px 8px;margin:3px 0}
.payment-list .payment-total{font-weight:700;color:#17392d;border-top:2px solid #e1e8e3;border-bottom:0;padding-top:14px;margin-top:2px;font-size:14px}
.payment-list .payment-total strong{font-size:15px;color:#12412e}

/* Fuel Shift Totals Bar */
.fuel-shift-totals{display:flex;gap:12px 20px;align-items:center;flex-wrap:wrap;margin-top:12px;padding:10px 14px;border-radius:8px;background:#f4f8f5;border:1px solid #e5eee8;font-size:12px;color:#355344}
.fuel-icon{width:16px;height:16px;stroke:#215a41;fill:none;stroke-width:2}
.fuel-total-pill{background:#fff;border:1px solid #dae7df;padding:3px 10px;border-radius:20px;font-size:11px}

/* Shift Summary Cards Grid */
.shift-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:9px;margin-top:14px;padding-top:14px;border-top:1px solid #edf2ee}
.summary-item{background:#fcfdfc;border:1px solid #edf3ef;border-radius:8px;padding:8px 12px;display:flex;flex-direction:column;gap:3px}
.summary-item .item-label{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#708478;font-weight:600}
.summary-item .item-value{font-size:13px;font-weight:700;color:#19382c}
.diff-pill{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:700}
.diff-excess{background:#eefbf3;color:#15803d}
.diff-short{background:#fef2f2;color:#b91c1c}
.diff-balanced{background:#f3f4f6;color:#4b5563}

/* Activity Accordion & Timeline */
.shift-activity-accordion{margin-top:16px;border:1px solid #e5eee8;border-radius:9px;background:#fbfdfc;overflow:hidden}
.activity-summary-head{display:flex;justify-content:space-between;align-items:center;padding:11px 16px;font-size:13px;color:#204a37;cursor:pointer;user-select:none;background:#f3f8f5;transition:background .15s}
.activity-summary-head:hover{background:#edf4ef}
.activity-icon{width:15px;height:15px;stroke:#20523d;fill:none;stroke-width:2}
.activity-chevron{width:14px;height:14px;stroke:#6c8275;fill:none;stroke-width:2;transition:transform .2s}
details[open] .activity-chevron{transform:rotate(180deg)}
.activity-timeline-body{padding:14px 16px;display:grid;gap:12px;background:#fafcfb}

/* Entry Card with Action Buttons */
.entry-card{background:#fff;border:1px solid #e2ebe5;border-radius:9px;box-shadow:0 1px 3px rgba(18,48,34,.04);overflow:hidden}
.entry-card-header{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;background:#f6f9f7;border-bottom:1px solid #e7efe9;font-size:12px;gap:8px}
.entry-num-badge{background:#184c37;color:#fff;font-weight:700;font-size:10px;padding:2px 7px;border-radius:12px}
.entry-timestamp{color:#2a4f3e;font-size:12px;display:inline-flex;align-items:center;gap:5px}
.clock-mini,.user-mini{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2}
.entry-user-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#5a7265;background:#fff;padding:2px 8px;border-radius:12px;border:1px solid #dce8e0}
.entry-action-btn{padding:2px 8px;font-size:11px;font-weight:600;border-radius:5px;display:inline-flex;align-items:center;gap:4px;line-height:1.4}
.entry-btn-icon{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2}

.entry-card-body{padding:12px 14px}
.entry-module{padding-bottom:10px;border-bottom:1px dashed #e8efe9}
.entry-module:last-child{padding-bottom:0;border-bottom:0;margin-bottom:0!important}
.entry-module-title{display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:7px}
.entry-module-title svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2}

/* Module: Meter Readings Chips */
.nozzles-chips-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}
.nozzle-chip{background:#f8faf9;border:1px solid #e4ede7;border-radius:6px;padding:7px 10px;display:grid;gap:2px}
.nozzle-chip-name{font-size:11px;font-weight:600;color:#284f3c}
.nozzle-chip-meter{font-size:13px;color:#17372a}
.nozzle-chip-sales{font-size:11px;color:#1a7f4c;font-weight:600}

/* Module: Money Collected */
.coll-total-pill{font-size:11px;color:#15803d;background:#eefbf3;border:1px solid #c9ebd4;padding:2px 8px;border-radius:12px;font-weight:500}
.payments-strip{display:flex;flex-wrap:wrap;gap:6px}
.pay-tag{font-size:11px;padding:3px 9px;border-radius:6px;border:1px solid transparent}
.bg-cash{background:#eefbf3;color:#166534;border-color:#ccefd8}
.bg-upi{background:#eff6ff;color:#1e40af;border-color:#dbeafe}
.bg-bank{background:#f0fdf4;color:#15803d;border-color:#dcfce7}
.bg-card{background:#faf5ff;color:#6b21a8;border-color:#f3e8ff}
.bg-credit{background:#f8fafc;color:#475569;border-color:#e2e8f0}
.denoms-strip{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:11px;color:#687e72}
.denom-chip{background:#fff;border:1px solid #dbe6df;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:600;color:#244c3a}
.expense-reason-text,.entry-note-text{font-size:11px;color:#6d8276;margin-top:4px}

/* Drawers & Modals */
.shift-drawer-backdrop{position:fixed;inset:0;z-index:1059;background:rgba(8,28,21,.52);backdrop-filter:blur(2px)}
.shift-drawer{position:fixed;z-index:1060;inset:0 0 0 auto;width:min(580px,100vw);overflow-y:auto;padding:24px 22px;background:#fff;box-shadow:-16px 0 45px rgba(16,43,32,.25)}
.shift-drawer-compact{width:min(480px,100vw)}
.shift-drawer-backdrop[hidden],.shift-drawer[hidden]{display:none}
.shift-drawer-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid #edf1ee}
.shift-drawer-head h2{font-size:19px;font-weight:600;margin:0 0 3px;color:#18372d}
.shift-drawer-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:16px;margin-top:20px;border-top:1px solid #edf1ee;position:sticky;bottom:0;background:#fff;padding-bottom:8px}
body.shift-drawer-open{overflow:hidden}

/* Live Clock Banner in Drawer */
.live-clock-card{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#f3f9f5;border:1px solid #daebe0;border-radius:8px}
.live-clock-label{display:flex;align-items:center;gap:7px;font-size:11px;font-weight:600;color:#22543d}
.live-clock-time{display:flex;align-items:center;gap:6px;font-size:13px;color:#173d2d}
.live-clock-divider{color:#a3bfaf}

/* Sections inside Drawer */
.drawer-section{background:#fff;border:1px solid #edf2ef;border-radius:9px;padding:14px;margin-bottom:14px}
.drawer-section-head{display:flex;align-items:flex-start;gap:10px;margin-bottom:12px}
.section-step{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#236147;color:#fff;font-size:11px;font-weight:700;flex-shrink:0}
.drawer-section-title{font-size:14px;font-weight:600;color:#16382c;margin:0 0 2px}
.drawer-section-sub{font-size:11px;color:#74877c;margin:0}

/* Nozzle entry */
.nozzle-entry-row{background:#f8faf9;border:1px solid #e7eee9;border-radius:6px;padding:10px}
.meter-live-note{font-size:11px;margin-top:4px}

/* Denominations */
.denomination-card{background:#f9fbf9;border:1px solid #e5eee8;border-radius:7px;padding:10px 12px}
.denomination-table-header{display:grid;grid-template-columns:90px 20px 1fr 20px 90px;font-size:11px;font-weight:600;color:#557063;padding-bottom:6px;border-bottom:1px solid #e3ece6;margin-bottom:6px}
.denom-row{display:grid;grid-template-columns:90px 20px 1fr 20px 90px;align-items:center;gap:4px;padding:3px 0}
.denom-badge{font-weight:600;font-size:12px;color:#1e4334}
.denom-times,.denom-eq{text-align:center;color:#8ba093;font-size:12px}
.denom-input-wrap .form-control{text-align:center;padding:2px 6px;height:30px}
.denom-subtotal{text-align:right;font-size:12px;font-weight:600;color:#204737}
.denomination-total-bar,.collection-total-bar{display:flex;justify-content:space-between;align-items:center;padding:9px 11px;border-radius:6px;background:#eaf4ed;font-size:12px;color:#1e4b37;margin-top:8px}
.denomination-total-bar strong,.collection-total-bar strong{font-size:14px;color:#133d2b}

/* Real-Time Summary Box */
.realtime-summary-box{background:#f4f8f5;border:2px solid #cce4d4;border-radius:10px;padding:14px}
.realtime-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #dcece1}
.realtime-header strong{font-size:13px;color:#194432}
.realtime-metrics-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;margin-bottom:11px}
.rt-metric{background:#fff;border:1px solid #dfede3;border-radius:7px;padding:8px 10px;display:grid;gap:2px}
.rt-label{font-size:10px;color:#6b8073;text-transform:uppercase;font-weight:600}
.rt-val{font-size:14px;color:#183c2d}
.rt-note{font-size:10px;color:#859a8d}
.rt-metric-diff{grid-column:span 2;border-width:2px}
.shift-cumulative-bar{border-top:1px dashed #cadfd1;padding-top:8px;margin-top:4px}

/* Close Modal Card */
.close-summary-card{background:#f8faf9;border:1px solid #e1ebe4;border-radius:8px;padding:12px 14px;font-size:12px}

@media(max-width:768px){
    .shift-head{flex-direction:column;align-items:flex-start}
    .shift-summary-grid{grid-template-columns:repeat(2,1fr)}
    .nozzles-chips-grid{grid-template-columns:1fr}
}
</style>
@endsection
