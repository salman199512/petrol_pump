<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }} · {{ $companySettings['company_name']??'Pump Manager' }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:30px;color:#172b23;background:#fff;font:13px/1.45 Arial,sans-serif}.report{max-width:1200px;margin:auto}.brand{display:flex;align-items:center;gap:16px;padding-bottom:18px;border-bottom:2px solid #14704f}.brand img{max-width:190px;height:64px;object-fit:contain}.brand-name{font-size:18px;font-weight:700}.heading{margin:20px 0 12px}.heading h1{margin:0;font-size:24px}.heading p{margin:5px 0;color:#53665b}.filters{display:flex;flex-wrap:wrap;gap:8px 25px;margin-bottom:18px;padding:12px 14px;background:#f3f7f4;border:1px solid #dce7df;border-radius:6px}.filters strong{color:#263d31}.table-wrap{overflow:visible}table{width:100%;border-collapse:collapse;table-layout:auto}thead{display:table-header-group}th,td{padding:8px 9px;text-align:left;vertical-align:top;border:1px solid #d8e0db;overflow-wrap:anywhere}th{background:#eaf2ec;color:#173d2d;font-weight:700}tbody tr:nth-child(even){background:#f8faf8}.empty{text-align:center;padding:24px;color:#63756a}.foot{margin-top:14px;color:#61736a;font-size:11px}.print-action{margin:0 0 16px;padding:9px 15px;border:0;border-radius:5px;background:#087451;color:white;font-weight:600;cursor:pointer}
        @page{size:landscape;margin:12mm}@media print{body{padding:0;font-size:10px}.report{max-width:none}.print-action{display:none}.brand{padding-bottom:10px}.brand img{max-width:150px;height:48px}.heading{margin:12px 0 8px}th,td{padding:5px 6px;font-size:9px;break-inside:avoid}tr{break-inside:avoid}.filters{padding:7px 10px;margin-bottom:10px}a{color:inherit;text-decoration:none}}
    </style>
</head>
<body>
<main class="report">
    <button class="print-action" type="button" onclick="window.print()">Print / Save PDF</button>
    <header class="brand">
        @if(!empty($companySettings['logo_path']))<img src="{{ route('company.logo') }}" alt="{{ $companySettings['company_name']??'Pump Manager' }}">@endif
        <div class="brand-name">{{ $companySettings['company_name']??'Pump Manager' }}</div>
    </header>
    <section class="heading"><h1>{{ $title }}</h1><p>Generated {{ now()->format('d M Y, h:i A') }}</p></section>
    <section class="filters">
        <span><strong>Period:</strong> @if($report==='customer-outstanding')Current balances as of {{ now()->format('d M Y') }}@else{{ request('from',now()->startOfMonth()->toDateString()) }} to {{ request('to',now()->toDateString()) }}@endif</span>
        <span><strong>Station:</strong> {{ $stationLabel }}</span>
        @if($report==='nozzle-sales')<span><strong>Fuel:</strong> {{ $fuelLabel }}</span>@endif
    </section>
    <div class="table-wrap"><table><thead><tr>@foreach($columns as $column)<th>{{ match($column){'date'=>'Date','station'=>'Station','shifts'=>'Shifts','sales'=>'Sales (INR)','collected'=>'Collected (INR)','credit'=>'Credit Sales (INR)','short_excess'=>'Short / Excess (INR)','cash_collected'=>'Cash (INR)','upi_collected'=>'UPI (INR)','bank_collected'=>'Bank (INR)','card_collected'=>'Card (INR)','credit_sales'=>'Credit Sales (INR)','quantity'=>'Quantity','rate'=>'Rate (INR)','amount'=>'Amount (INR)','total'=>'Total (INR)','paid'=>'Paid (INR)','net'=>'Net (INR)','expenses'=>'Expenses (INR)','credit_limit'=>'Credit Limit (INR)','outstanding'=>'Outstanding (INR)','payment_mode'=>'Payment Method','mode'=>'Payment Method','updated_by'=>'Updated By','invoice_number'=>'Invoice Number','entity_type'=>'Record Type','entity_id'=>'Record ID','user'=>'User',default=>str($column)->replace('_',' ')->title()} }}</th>@endforeach</tr></thead><tbody>
    @forelse($rows as $row)<tr>@foreach($columns as $column)@php($value=data_get($row,$column))<td>@if($column==='date'&&$value){{ \Illuminate\Support\Carbon::parse($value)->format('d M Y') }}@elseif(is_numeric($value)&&in_array($column,['sales','collected','credit','short_excess','cash_collected','upi_collected','bank_collected','card_collected','credit_sales','rate','amount','total','paid','net','expenses','credit_limit','outstanding'],true) && $value!==null){{ number_format((float)$value,2) }}@else{{ $value??'—' }}@endif</td>@endforeach</tr>@empty<tr><td class="empty" colspan="{{ count($columns) }}">No records match the selected filters.</td></tr>@endforelse
    </tbody></table></div>
    <div class="foot">{{ $rows->count() }} records · {{ $companySettings['footer_note']??'Generated from Pump Manager' }}</div>
</main>
<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),250));</script>
</body>
</html>
