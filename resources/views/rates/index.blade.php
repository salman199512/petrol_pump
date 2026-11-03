@extends('layouts.app')
@section('title','Daily rates')
@section('crumb','Rates')
@section('content')
<div class="page-head"><div><h1>Daily rates</h1><div class="subtle">Enter one price for each fuel at the selected station and date.</div></div></div>
<section class="panel rate-entry-panel mb-4">
    <form method="post" action="{{ route('rates.store') }}">@csrf
        <div class="rate-fields" style="--fuel-count:{{ max($fuels->count(),1) }}">
            <div><label class="form-label" for="station_id">Station</label><select id="station_id" name="station_id" class="form-select" required>@foreach($stations as $station)<option value="{{ $station->id }}" @selected($station->id==$stationId)>{{ $station->name }}</option>@endforeach</select></div>
            <div><label class="form-label" for="rate_date">Date</label><input id="rate_date" name="rate_date" type="date" class="form-control" value="{{ $date }}" required></div>
            @foreach($fuels as $fuel)<div><label class="form-label" for="rate_{{ $fuel->id }}">{{ $fuel->name }} (₹ / {{ $fuel->unit }})</label><input id="rate_{{ $fuel->id }}" name="rates[{{ $fuel->id }}]" type="number" min="0" max="99999999.99" step="0.01" class="form-control rate-input" value="{{ $currentRates[$fuel->id]??'' }}" placeholder="0.00" required></div>@endforeach
            <div><button class="btn btn-primary rate-save">Save rates</button></div>
        </div>
    </form>
</section>
<section class="panel"><div class="d-flex justify-content-between align-items-center mb-2"><div><h2 class="panel-title">Rate history</h2><div class="panel-sub">Previously saved station prices, grouped by date.</div></div></div><div class="table-responsive"><table data-ajax-url="{{ route('tables.data','rates') }}" class="table align-middle"><thead><tr><th data-column="date">Date</th><th data-column="station">Station</th><th data-column="fuel">Fuel</th><th data-column="rate">Rate</th><th data-column="author">Set by</th></tr></thead><tbody>
@forelse($rateGroups as $dateKey=>$entries)<tr><td>{{ \Illuminate\Support\Carbon::parse($dateKey)->format('d M Y') }}</td>@foreach($fuels as $fuel)@php($entry=$entries->firstWhere('fuel_type_id',$fuel->id))<td>{{ $entry?'₹ '.number_format($entry->rate,2):'—' }}</td>@endforeach<td>{{ $entries->pluck('author')->filter()->unique()->join(', ')?:'—' }}</td></tr>@empty<tr><td colspan="{{ $fuels->count()+2 }}" class="empty-state"><strong>No rates saved yet</strong><span>Choose a date and add the station's fuel prices above.</span></td></tr>@endforelse
</tbody></table></div></section>
<style>.rate-entry-panel{padding:20px}.rate-fields{display:grid;grid-template-columns:minmax(165px,1.25fr) minmax(140px,.9fr) repeat(var(--fuel-count),minmax(125px,1fr)) auto;gap:13px;align-items:end}.rate-fields .form-label{white-space:nowrap}.rate-input{text-align:right;font-weight:500}.rate-save{height:39px;white-space:nowrap}@media(max-width:1050px){.rate-fields{grid-template-columns:repeat(3,minmax(140px,1fr))}.rate-save{width:100%}}@media(max-width:600px){.rate-fields{grid-template-columns:1fr 1fr;gap:11px}.rate-fields>div:first-child,.rate-fields>div:last-child{grid-column:1/-1}}</style>
@endsection
