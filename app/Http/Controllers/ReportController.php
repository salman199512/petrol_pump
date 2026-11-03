<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private array $reports = [
        'daily-sales'=>'Daily Sales','shift-report'=>'Shift Report','nozzle-sales'=>'Nozzle-wise Sales','operator-performance'=>'Operator Performance',
        'payment-collection'=>'Payment Collection','expense-report'=>'Expense Report','profit-loss'=>'Profit and Loss','rate-history'=>'Rate History',
        'invoice-register'=>'Invoice Register','customer-outstanding'=>'Customer Outstanding','cash-short-excess'=>'Cash Short/Excess','audit-log'=>'Audit Log',
    ];

    private array $columns = [
        'daily-sales'=>['date','station','shifts','sales','collected','credit','short_excess'],
        'shift-report'=>['date','station','shift','operator','status','sales','cash_collected','upi_collected','bank_collected','card_collected','credit_sales','short_excess'],
        'nozzle-sales'=>['date','station','nozzle','fuel','unit','quantity','rate','sales'],
        'operator-performance'=>['operator','station','shifts','sales','short_excess'],
        'payment-collection'=>['date','station','mode','reference','amount'],
        'expense-report'=>['date','station','category','payment_mode','amount','remarks'],
        'profit-loss'=>['date','station','sales','expenses','net'],
        'rate-history'=>['date','station','fuel','unit','rate','updated_by'],
        'invoice-register'=>['date','station','invoice_number','customer','total','paid','payment_mode'],
        'customer-outstanding'=>['station','customer','mobile','credit_limit','outstanding'],
        'cash-short-excess'=>['date','station','operator','sales','cash_collected','upi_collected','bank_collected','card_collected','credit_sales','short_excess','status'],
        'audit-log'=>['date','station','user','action','entity_type','entity_id','details'],
    ];

    private function columnLabel(string $column): string
    {
        return match($column){'date'=>'Date','station'=>'Station','shifts'=>'Shifts','sales'=>'Sales (INR)','collected'=>'Collected (INR)','credit'=>'Credit Sales (INR)','short_excess'=>'Short / Excess (INR)','cash_collected'=>'Cash (INR)','upi_collected'=>'UPI (INR)','bank_collected'=>'Bank (INR)','card_collected'=>'Card (INR)','credit_sales'=>'Credit Sales (INR)','quantity'=>'Quantity','rate'=>'Rate (INR)','amount'=>'Amount (INR)','total'=>'Total (INR)','paid'=>'Paid (INR)','net'=>'Net (INR)','expenses'=>'Expenses (INR)','credit_limit'=>'Credit Limit (INR)','outstanding'=>'Outstanding (INR)','payment_mode'=>'Payment Method','mode'=>'Payment Method','updated_by'=>'Updated By','invoice_number'=>'Invoice Number','entity_type'=>'Record Type','entity_id'=>'Record ID','user'=>'User',default=>\Illuminate\Support\Str::headline($column)};
    }

    private function scope(Request $request)
    {
        abort_unless($request->user()->hasAnyPermission(array_map(fn($key)=>$key==='audit-log'?'reports.audit-log':"reports.{$key}",array_keys($this->reports))),403);
        return $request->user()->role==='Super Admin'
            ? DB::table('stations')->where('active',true)->pluck('id')
            : $request->user()->stations()->where('stations.active',true)->pluck('stations.id');
    }

    private function query(string $report, Request $request, $stationIds)
    {
        abort_unless(isset($this->reports[$report]),404);
        abort_unless($request->user()->hasPermission($report==='audit-log'?'reports.audit-log':"reports.{$report}"),403);
        $from=$request->input('from',now()->startOfMonth()->toDateString());
        $to=$request->input('to',now()->toDateString());
        $station=$request->integer('station_id');
        if($station) abort_unless($stationIds->contains($station),403);
        $ids=$station?$stationIds->filter(fn($id)=>(int)$id===$station):$stationIds;
        $salesRows=DB::table('shifts')->where('status','<>','Voided')->whereIn('station_id',$ids)->selectRaw('station_id, shift_date as report_date, SUM(sales_total) as sales, 0 as expenses')->groupBy('station_id','shift_date');
        $expenseRows=DB::table('expenses')->whereNull('deleted_at')->whereIn('station_id',$ids)->selectRaw('station_id, expense_date as report_date, 0 as sales, SUM(amount) as expenses')->groupBy('station_id','expense_date');
        $profitRows=$salesRows->unionAll($expenseRows);
        $q=match($report){
            'daily-sales'=>DB::table('shifts')->join('stations','stations.id','=','shifts.station_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->selectRaw("shifts.shift_date as date, stations.name as station, COUNT(*) as shifts, SUM(shifts.sales_total) as sales, SUM(shifts.cash_collected + shifts.upi_collected + shifts.bank_collected + shifts.card_collected) as collected, SUM(shifts.credit_sales) as credit, SUM(shifts.short_excess) as short_excess")->groupBy('shifts.shift_date','stations.name')->orderByDesc('shifts.shift_date'),
            'shift-report'=>DB::table('shifts')->join('stations','stations.id','=','shifts.station_id')->join('shift_types','shift_types.id','=','shifts.shift_type_id')->join('users','users.id','=','shifts.operator_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->select('shifts.shift_date as date','stations.name as station','shift_types.name as shift','users.name as operator','shifts.status','shifts.sales_total as sales','shifts.cash_collected','shifts.upi_collected','shifts.bank_collected','shifts.card_collected','shifts.credit_sales','shifts.short_excess')->orderByDesc('shifts.shift_date'),
            'nozzle-sales'=>DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->join('stations','stations.id','=','shifts.station_id')->join('nozzles','nozzles.id','=','shift_readings.nozzle_id')->join('fuel_types','fuel_types.id','=','shift_readings.fuel_type_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->select('shifts.shift_date as date','stations.name as station','nozzles.name as nozzle','fuel_types.name as fuel','fuel_types.unit','shift_readings.quantity','shift_readings.rate','shift_readings.amount as sales')->orderByDesc('shifts.shift_date'),
            'operator-performance'=>DB::table('shifts')->join('stations','stations.id','=','shifts.station_id')->join('users','users.id','=','shifts.operator_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->selectRaw("users.name as operator, stations.name as station, COUNT(*) as shifts, SUM(shifts.sales_total) as sales, SUM(shifts.short_excess) as short_excess")->groupBy('users.name','stations.name')->orderByRaw('SUM(shifts.sales_total) DESC'),
            'payment-collection'=>DB::table('payments')->join('stations','stations.id','=','payments.station_id')->whereNull('payments.voided_at')->whereIn('payments.station_id',$ids)->select('payments.payment_date as date','stations.name as station','payments.mode','payments.reference','payments.amount')->orderByDesc('payments.payment_date'),
            'expense-report'=>DB::table('expenses')->join('stations','stations.id','=','expenses.station_id')->join('expense_categories','expense_categories.id','=','expenses.category_id')->whereNull('expenses.deleted_at')->whereIn('expenses.station_id',$ids)->select('expenses.expense_date as date','stations.name as station','expense_categories.name as category','expenses.payment_mode','expenses.amount','expenses.remarks')->orderByDesc('expenses.expense_date'),
            'profit-loss'=>DB::query()->fromSub($profitRows,'daily_rows')->join('stations','stations.id','=','daily_rows.station_id')->selectRaw('daily_rows.report_date as date, stations.name as station, SUM(daily_rows.sales) as sales, SUM(daily_rows.expenses) as expenses, SUM(daily_rows.sales-daily_rows.expenses) as net')->groupBy('daily_rows.report_date','stations.name')->orderByDesc('daily_rows.report_date'),
            'rate-history'=>DB::table('daily_rates')->join('stations','stations.id','=','daily_rates.station_id')->join('fuel_types','fuel_types.id','=','daily_rates.fuel_type_id')->join('users','users.id','=','daily_rates.updated_by')->whereIn('daily_rates.station_id',$ids)->select('daily_rates.rate_date as date','stations.name as station','fuel_types.name as fuel','fuel_types.unit','daily_rates.rate','users.name as updated_by')->orderByDesc('daily_rates.rate_date'),
            'invoice-register'=>DB::table('invoices')->join('stations','stations.id','=','invoices.station_id')->leftJoin('customers','customers.id','=','invoices.customer_id')->whereIn('invoices.station_id',$ids)->select('invoices.invoice_date as date','stations.name as station','invoices.invoice_number','customers.name as customer','invoices.grand_total as total','invoices.paid_amount as paid','invoices.payment_mode')->orderByDesc('invoices.invoice_date'),
            'customer-outstanding'=>DB::table('customers')->leftJoinSub(DB::table('customer_ledgers')->select('customer_id',DB::raw('SUM(debit-credit) as balance'))->groupBy('customer_id'),'ledger_balances',fn($join)=>$join->on('customers.id','=','ledger_balances.customer_id'))->join('stations','stations.id','=','customers.station_id')->whereIn('customers.station_id',$ids)->where('customers.active',true)->select('stations.name as station','customers.name as customer','customers.mobile','customers.credit_limit',DB::raw('COALESCE(ledger_balances.balance,customers.opening_balance) as outstanding'))->orderByDesc('outstanding'),
            'cash-short-excess'=>DB::table('shifts')->join('stations','stations.id','=','shifts.station_id')->join('users','users.id','=','shifts.operator_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->select('shifts.shift_date as date','stations.name as station','users.name as operator','shifts.sales_total as sales','shifts.cash_collected','shifts.upi_collected','shifts.bank_collected','shifts.card_collected','shifts.credit_sales','shifts.short_excess','shifts.status')->orderByDesc('shifts.shift_date'),
            'audit-log'=>DB::table('audit_logs')->leftJoin('stations','stations.id','=','audit_logs.station_id')->leftJoin('users','users.id','=','audit_logs.user_id')->where(function($w)use($ids,$request){$w->whereIn('audit_logs.station_id',$ids);if($request->user()->role==='Super Admin')$w->orWhereNull('audit_logs.station_id');})->select('audit_logs.created_at as date','stations.name as station','users.name as user','audit_logs.action','audit_logs.entity_type','audit_logs.entity_id','audit_logs.details')->orderByDesc('audit_logs.created_at'),
        };
        if($report!=='customer-outstanding'){
            $dateColumn=match($report){'payment-collection'=>'payments.payment_date','expense-report'=>'expenses.expense_date','rate-history'=>'daily_rates.rate_date','invoice-register'=>'invoices.invoice_date','audit-log'=>'audit_logs.created_at','profit-loss'=>'daily_rows.report_date',default=>'shifts.shift_date'};
            if($from) $q->whereDate($dateColumn,'>=',$from);
            if($to) $q->whereDate($dateColumn,'<=',$to);
        }
        if($report==='nozzle-sales' && $request->integer('fuel_id')) $q->where('fuel_types.id',$request->integer('fuel_id'));
        return $q;
    }

    public function index(Request $request)
    {
        $request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','station_id'=>'nullable|integer','fuel_id'=>'nullable|integer']);
        $ids=$this->scope($request);$stations=DB::table('stations')->whereIn('id',$ids)->orderBy('name')->get();$report=$request->input('report');$rows=collect();$title=null;$columns=[];
        $availableReports=array_filter($this->reports,fn($label,$key)=>$request->user()->hasPermission($key==='audit-log'?'reports.audit-log':"reports.{$key}"),ARRAY_FILTER_USE_BOTH);
        if($report){$title=$this->reports[$report]??abort(404);$columns=$this->columns[$report]??[];$query=$this->query($report,$request,$ids);if($request->ajax())return DataTables::query($query)->toJson();if($request->boolean('print')){$rows=$query->get();$stationLabel=$request->integer('station_id')?DB::table('stations')->where('id',$request->integer('station_id'))->value('name'):'All permitted stations';$fuelLabel=$request->integer('fuel_id')?DB::table('fuel_types')->where('id',$request->integer('fuel_id'))->value('name'):'All fuels';return view('reports.print',compact('report','rows','title','stationLabel','fuelLabel','columns'));}$rows=$query->paginate(25)->withQueryString();}
        $fuels=DB::table('fuel_types')->where('active',true)->orderBy('name')->get();
        return view('reports.index',compact('stations','fuels','report','rows','title','columns','availableReports'));
    }

    public function export(Request $request,string $report): StreamedResponse
    {
        $request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','station_id'=>'nullable|integer','fuel_id'=>'nullable|integer']);
        $ids=$this->scope($request);$rows=$this->query($report,$request,$ids)->get();$title=$this->reports[$report]??abort(404);
        $from=$request->input('from',now()->startOfMonth()->toDateString());$to=$request->input('to',now()->toDateString());
        $filename=$report.'-'.$from.'-to-'.$to.'.csv';$columns=$this->columns[$report]??[];
        $labels=array_map(fn($column)=>$this->columnLabel($column),$columns);
        $stationLabel=$request->integer('station_id')?DB::table('stations')->where('id',$request->integer('station_id'))->value('name'):'All permitted stations';
        $fuelLabel=$request->integer('fuel_id')?DB::table('fuel_types')->where('id',$request->integer('fuel_id'))->value('name'):'All fuels';
        $period=$report==='customer-outstanding'?'Current balances as of '.now()->format('d M Y'):$from.' to '.$to;
        return response()->streamDownload(function()use($rows,$columns,$labels,$title,$period,$stationLabel,$fuelLabel){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,[$title]);fputcsv($out,['Period',$period,'Station',$stationLabel,'Fuel',$fuelLabel]);fputcsv($out,[]);fputcsv($out,$labels);foreach($rows as $row){$values=(array)$row;$line=[];foreach($columns as $column){$value=$values[$column]??'';if($column==='date'&&$value){try{$value=\Illuminate\Support\Carbon::parse($value)->format('d-M-y');}catch(\Throwable){}}elseif(is_numeric($value)&&in_array($column,['sales','collected','credit','short_excess','cash_collected','upi_collected','bank_collected','card_collected','credit_sales','rate','amount','total','paid','net','expenses','credit_limit','outstanding'],true)){$value=number_format((float)$value,2,'.','');}if(is_string($value)&&preg_match('/^[=+\-@]/',$value))$value="'".$value;$line[]=$value;}fputcsv($out,$line);}fclose($out);},$filename,['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    public function exportExcel(Request $request,string $report)
    {
        $request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','station_id'=>'nullable|integer','fuel_id'=>'nullable|integer']);
        $ids=$this->scope($request);$rows=$this->query($report,$request,$ids)->get();$title=$this->reports[$report]??abort(404);$columns=$this->columns[$report]??[];
        $from=$request->input('from',now()->startOfMonth()->toDateString());$to=$request->input('to',now()->toDateString());$period=$report==='customer-outstanding'?'Current balances as of '.now()->format('d M Y'):$from.' to '.$to;
        $station=$request->integer('station_id')?DB::table('stations')->where('id',$request->integer('station_id'))->value('name'):'All permitted stations';$fuel=$request->integer('fuel_id')?DB::table('fuel_types')->where('id',$request->integer('fuel_id'))->value('name'):'All fuels';
        $money=['sales','collected','credit','short_excess','cash_collected','upi_collected','bank_collected','card_collected','credit_sales','rate','amount','total','paid','net','expenses','credit_limit','outstanding'];$xml=fn($value)=>htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES,'UTF-8');$letters=function(int $number){$result='';while($number){$number--; $result=chr(65+$number%26).$result;$number=intdiv($number,26);}return $result;};
        $cell=function(string $ref,mixed $value,int $style=0)use($xml,$money){$styleAttr=$style?' s="'.$style.'"':'';if($value===null)return '<c r="'.$ref.'"'.$styleAttr.'/>';if(is_numeric($value)&&!is_string($value))return '<c r="'.$ref.'"'.$styleAttr.'><v>'.(float)$value.'</v></c>';return '<c r="'.$ref.'" t="inlineStr"'.$styleAttr.'><is><t xml:space="preserve">'.$xml($value).'</t></is></c>';};
        $lastColumn=$letters(max(count($columns),1));$sheetRows=[];$sheetRows[]='<row r="1">'.$cell('A1',$title,1).'</row>';$sheetRows[]='<row r="2">'.$cell('A2','Period: '.$period.'   |   Station: '.$station.($report==='nozzle-sales'?'   |   Fuel: '.$fuel:''),2).'</row>';$sheetRows[]='<row r="3">'.$cell('A3','Generated: '.now()->format('d M Y, h:i A').'   |   '.$rows->count().' records',2).'</row>';$headerRow=5;$headerCells='';foreach($columns as $i=>$column)$headerCells.=$cell($letters($i+1).$headerRow,$this->columnLabel($column),3);$sheetRows[]='<row r="'.$headerRow.'" ht="26" customHeight="1">'.$headerCells.'</row>';
        foreach($rows as $ri=>$row){$values=(array)$row;$rowNum=$headerRow+$ri+1;$cells='';foreach($columns as $ci=>$column){$value=$values[$column]??null;$ref=$letters($ci+1).$rowNum;if($column==='date'&&$value){try{$value=\Illuminate\Support\Carbon::parse($value)->format('d M Y');}catch(\Throwable){}}if(is_numeric($value)&&$value!==null){$style=in_array($column,$money,true)?4:($column==='quantity'?5:0);$value=(float)$value;$styleAttr=$style?' s="'.$style.'"':'';$cells.='<c r="'.$ref.'"'.$styleAttr.'><v>'.$xml($value).'</v></c>';}else{$cells.=$cell($ref,$value===null?'—':$value,6);}}$sheetRows[]='<row r="'.$rowNum.'">'.$cells.'</row>';}
        if($rows->isEmpty())$sheetRows[]='<row r="6">'.$cell('A6','No records match the selected filters.',6).'</row>';
        $widths=[];foreach($columns as $i=>$column){$width=in_array($column,['date'],true)?14:(in_array($column,['station','customer','operator','remarks','details','reference'],true)?26:(in_array($column,$money,true)?18:16));$widths[]='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';}
        $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:'.$lastColumn.max(6,$headerRow+$rows->count()).'"/><cols>'.implode('',$widths).'</cols><sheetData>'.implode('',$sheetRows).'</sheetData><autoFilter ref="A5:'.$lastColumn.max(5,$headerRow+$rows->count()).'"/><mergeCells count="3"><mergeCell ref="A1:'.$lastColumn.'1"/><mergeCell ref="A2:'.$lastColumn.'2"/><mergeCell ref="A3:'.$lastColumn.'3"/></mergeCells><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
        $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.000"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FF18372D"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF087451"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF2EC"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD8E0DB"/></left><right style="thin"><color rgb="FFD8E0DB"/></right><top style="thin"><color rgb="FFD8E0DB"/></top><bottom style="thin"><color rgb="FFD8E0DB"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0"/><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0"/><xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        $tmp=tempnam(sys_get_temp_dir(),'pump-report-');$path=$tmp.'.xlsx';@unlink($tmp);$zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CREATE)!==true)abort(500,'Unable to create the Excel report.');$zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');$zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');$zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$xml($title).'" sheetId="1" r:id="rId1"/></sheets></workbook>');$zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');$zip->addFromString('xl/styles.xml',$styles);$zip->addFromString('xl/worksheets/sheet1.xml',$sheet);$zip->close();return response()->download($path,$report.'-'.$from.'-to-'.$to.'.xlsx',['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }
}
