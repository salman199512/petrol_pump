<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Adds clearly identified training records for the previous 20 calendar days.
 * Safe to rerun: each demo row is found by its stable DEMO key, and existing
 * station, user, role, fuel, nozzle, and rate records are preserved.
 */
class DemoDataSeeder extends Seeder
{
    private const PREFIX = 'DEMO DATA';

    public function run(): void
    {
        foreach (['stations','users','fuel_types','nozzles','shift_types','daily_rates','shifts','shift_readings','customers','invoices','invoice_items','payments','expense_categories','expenses','customer_ledgers','audit_logs'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("The {$table} table is missing. Run the regular migrations and base seeder first.");
            }
        }

        $station = DB::table('stations')->where('name', 'Banas CNG Pump')->first();
        if (!$station) throw new RuntimeException('Banas CNG Pump was not found. Run php artisan db:seed first.');
        $admin = DB::table('users')->where('email', 'admin@banaspump.local')->first();
        $operator = DB::table('users')->where('email', 'operator@banaspump.local')->first();
        if (!$admin || !$operator) throw new RuntimeException('Base admin/operator accounts were not found. Run php artisan db:seed first.');

        $dayCount = 0;
        DB::transaction(function () use ($station, $admin, $operator, &$dayCount): void {
            $fuels = [];
            foreach ([['CNG','kg'],['Petrol','L'],['Diesel','L']] as [$name,$unit]) {
                $id = $this->record('fuel_types', ['name' => $name], ['unit' => $unit, 'tax_rate' => 0, 'active' => true]);
                $fuels[$name] = (object) ['id' => $id, 'name' => $name];
            }

            $dispenserId = $this->record('dispensers', ['station_id' => $station->id, 'name' => 'DEMO Dispenser'], []);
            $nozzles = [];
            foreach ($fuels as $name => $fuel) {
                $nozzleId = $this->record('nozzles', ['station_id' => $station->id, 'name' => 'DEMO '.$name.' Nozzle'], [
                    'dispenser_id' => $dispenserId, 'fuel_type_id' => $fuel->id, 'active' => true,
                ]);
                $nozzles[$name] = $nozzleId;
            }

            $shiftTypes = DB::table('shift_types')->where('station_id', $station->id)->where('active', true)->orderBy('starts_at')->get();
            if ($shiftTypes->isEmpty()) {
                $id = $this->record('shift_types', ['station_id' => $station->id, 'name' => 'DEMO Day Shift'], ['starts_at' => '06:00:00', 'ends_at' => '14:00:00', 'active' => true]);
                $shiftTypes = DB::table('shift_types')->where('id', $id)->get();
            }

            $customers = [];
            foreach ([
                ['DEMO Fleet Customer A','9000000011','GJ01DEMO1',500000],
                ['DEMO Fleet Customer B','9000000012','GJ01DEMO2',500000],
            ] as [$name,$mobile,$vehicle,$limit]) {
                $id = $this->record('customers', ['station_id' => $station->id, 'name' => $name], [
                    'mobile' => $mobile, 'address' => 'Training record', 'vehicle_number' => $vehicle,
                    'gst_number' => null, 'credit_limit' => $limit, 'opening_balance' => 0, 'active' => true,
                ]);
                $customers[] = (object) ['id' => $id, 'name' => $name, 'vehicle' => $vehicle];
            }

            $categories = [];
            foreach (['DEMO Utilities','DEMO Maintenance'] as $name) {
                $categories[$name] = $this->record('expense_categories', ['station_id' => $station->id, 'name' => $name], []);
            }

            $opening = ['CNG' => 10000.000, 'Petrol' => 20000.000, 'Diesel' => 30000.000];
            for ($offset = 19; $offset >= 0; $offset--) {
                $day = Carbon::today()->subDays($offset);
                $date = $day->toDateString();
                $n = 19 - $offset;
                $rates = [
                    'CNG' => 90 + ($n % 4) * 0.50,
                    'Petrol' => 104 + ($n % 3) * 0.75,
                    'Diesel' => 92 + ($n % 5) * 0.40,
                ];
                foreach ($fuels as $name => $fuel) {
                    $this->record('daily_rates', ['station_id' => $station->id, 'fuel_type_id' => $fuel->id, 'rate_date' => $date], [
                        'rate' => number_format($rates[$name], 2, '.', ''), 'created_by' => $admin->id,
                        'updated_by' => $admin->id,
                    ]);
                    $stored = DB::table('daily_rates')->where(['station_id' => $station->id, 'fuel_type_id' => $fuel->id, 'rate_date' => $date])->value('rate');
                    $rates[$name] = (float) $stored;
                }

                $quantities = [
                    'CNG' => 380 + (($n * 17) % 100),
                    'Petrol' => 260 + (($n * 19) % 80),
                    'Diesel' => 320 + (($n * 23) % 90),
                ];
                $amounts = [];
                foreach ($fuels as $name => $fuel) $amounts[$name] = round($quantities[$name] * $rates[$name], 2);
                $sales = round(array_sum($amounts), 2);

                // Pick a shift slot that does not collide with a real shift for this operator/date.
                $shift = null;
                foreach ($shiftTypes as $type) {
                    $candidate = DB::table('shifts')->where([
                        'station_id' => $station->id, 'shift_type_id' => $type->id,
                        'operator_id' => $operator->id, 'shift_date' => $date,
                    ])->first();
                    if ($candidate && str_starts_with((string) $candidate->remarks, self::PREFIX)) { $shift = $candidate; break; }
                    if (!$candidate && !$shift) {
                        $shiftId = $this->record('shifts', [
                            'station_id' => $station->id, 'shift_type_id' => $type->id,
                            'operator_id' => $operator->id, 'shift_date' => $date,
                        ], [
                            'status' => 'Approved', 'cash_collected' => number_format(round($sales * 0.40, 2), 2, '.', ''),
                            'upi_collected' => number_format(round($sales * 0.30, 2), 2, '.', ''),
                            'bank_collected' => number_format(round($sales * 0.10, 2), 2, '.', ''),
                            'card_collected' => number_format(round($sales * 0.10, 2), 2, '.', ''),
                            'credit_sales' => number_format(round($sales - round($sales * 0.90, 2), 2), 2, '.', ''),
                            'sales_total' => number_format($sales, 2, '.', ''), 'short_excess' => '0.00',
                            'remarks' => self::PREFIX.' · training shift; not a real meter reconciliation',
                            'submitted_at' => $day->copy()->setTime(14, 5), 'approved_by' => $admin->id,
                        ]);
                        $shift = DB::table('shifts')->where('id', $shiftId)->first();
                    }
                }
                if (!$shift) throw new RuntimeException("No unused shift type is available for demo date {$date}.");

                foreach ($fuels as $name => $fuel) {
                    $closing = $opening[$name] + $quantities[$name];
                    $this->record('shift_readings', ['shift_id' => $shift->id, 'nozzle_id' => $nozzles[$name]], [
                        'fuel_type_id' => $fuel->id, 'opening_reading' => number_format($opening[$name], 3, '.', ''),
                        'closing_reading' => number_format($closing, 3, '.', ''),
                        'quantity' => number_format($quantities[$name], 3, '.', ''),
                        'rate' => number_format($rates[$name], 2, '.', ''),
                        'amount' => number_format($amounts[$name], 2, '.', ''),
                    ]);
                    $opening[$name] = $closing;
                }

                $customer = $customers[$n % count($customers)];
                $fuelName = array_keys($fuels)[$n % 3];
                $fuel = $fuels[$fuelName];
                $invoiceNumber = 'DEMO-'.$day->format('Ymd').'-001';
                $invoice = DB::table('invoices')->where('invoice_number', $invoiceNumber)->first();
                if (!$invoice) {
                    $quantity = 70 + (($n * 11) % 90);
                    $subtotal = round($quantity * $rates[$fuelName], 2);
                    $taxRate = (float) (DB::table('fuel_types')->where('id', $fuel->id)->value('tax_rate') ?? 0);
                    $tax = round($subtotal * $taxRate / 100, 2);
                    $grand = round($subtotal + $tax, 2);
                    $isCredit = $n % 4 === 0;
                    $mode = $isCredit ? 'Credit' : ['Cash','UPI','Card'][$n % 3];
                    $paid = $isCredit ? round($grand * 0.35, 2) : $grand;
                    $invoiceId = DB::table('invoices')->insertGetId([
                        'station_id' => $station->id, 'customer_id' => $customer->id, 'created_by' => $admin->id,
                        'invoice_number' => $invoiceNumber, 'invoice_date' => $date,
                        'vehicle_number' => $customer->vehicle, 'payment_mode' => $mode,
                        'subtotal' => number_format($subtotal, 2, '.', ''), 'tax_total' => number_format($tax, 2, '.', ''),
                        'grand_total' => number_format($grand, 2, '.', ''), 'paid_amount' => number_format($paid, 2, '.', ''),
                        'status' => 'Issued', 'created_at' => $day->copy()->setTime(12, 0), 'updated_at' => $day->copy()->setTime(12, 0),
                    ]);
                    DB::table('invoice_items')->insert([
                        'invoice_id' => $invoiceId, 'fuel_type_id' => $fuel->id,
                        'quantity' => number_format($quantity, 3, '.', ''), 'rate' => number_format($rates[$fuelName], 2, '.', ''),
                        'tax_rate' => number_format($taxRate, 2, '.', ''), 'tax_amount' => number_format($tax, 2, '.', ''),
                        'amount' => number_format($grand, 2, '.', ''),
                        'created_at' => $day->copy()->setTime(12, 0), 'updated_at' => $day->copy()->setTime(12, 0),
                    ]);
                    DB::table('customer_ledgers')->insert([
                        'customer_id' => $customer->id, 'entry_date' => $date, 'reference' => $invoiceNumber,
                        'debit' => number_format($grand, 2, '.', ''), 'credit' => '0.00', 'created_by' => $admin->id,
                        'created_at' => $day->copy()->setTime(12, 0), 'updated_at' => $day->copy()->setTime(12, 0),
                    ]);
                    if ($paid > 0) {
                        DB::table('customer_ledgers')->insert([
                            'customer_id' => $customer->id, 'entry_date' => $date, 'reference' => $invoiceNumber.' payment',
                            'debit' => '0.00', 'credit' => number_format($paid, 2, '.', ''), 'created_by' => $admin->id,
                            'created_at' => $day->copy()->setTime(12, 1), 'updated_at' => $day->copy()->setTime(12, 1),
                        ]);
                        DB::table('payments')->insert([
                            'station_id' => $station->id, 'customer_id' => $customer->id, 'shift_id' => $shift->id,
                            'created_by' => $admin->id, 'payment_date' => $date, 'mode' => $mode,
                            'amount' => number_format($paid, 2, '.', ''), 'reference' => $invoiceNumber,
                            'created_at' => $day->copy()->setTime(12, 1), 'updated_at' => $day->copy()->setTime(12, 1),
                        ]);
                    }
                }

                if ($n % 6 === 5) {
                    $receiptRef = 'DEMO-RECEIPT-'.$day->format('Ymd');
                    if (!DB::table('payments')->where('reference', $receiptRef)->exists()) {
                        $receipt = 2500 + ($n * 175);
                        DB::table('customer_ledgers')->insert([
                            'customer_id' => $customer->id, 'entry_date' => $date, 'reference' => $receiptRef,
                            'debit' => '0.00', 'credit' => number_format($receipt, 2, '.', ''), 'created_by' => $admin->id,
                            'created_at' => $day->copy()->setTime(16, 0), 'updated_at' => $day->copy()->setTime(16, 0),
                        ]);
                        DB::table('payments')->insert([
                            'station_id' => $station->id, 'customer_id' => $customer->id, 'shift_id' => $shift->id,
                            'created_by' => $admin->id, 'payment_date' => $date, 'mode' => 'UPI',
                            'amount' => number_format($receipt, 2, '.', ''), 'reference' => $receiptRef,
                            'created_at' => $day->copy()->setTime(16, 0), 'updated_at' => $day->copy()->setTime(16, 0),
                        ]);
                    }
                }

                $category = $n % 4 === 0 ? 'DEMO Maintenance' : 'DEMO Utilities';
                $expenseRef = self::PREFIX.' · '.$date;
                $existingExpenses = DB::table('expenses')->where('station_id', $station->id)
                    ->where('expense_date', $date)->where('remarks', 'like', $expenseRef.'%')
                    ->whereNull('deleted_at')->orderBy('id')->pluck('id');
                if ($existingExpenses->isEmpty()) {
                    $categoryId = $categories[$category];
                    DB::table('expenses')->insert([
                        'station_id' => $station->id, 'category_id' => $categoryId, 'shift_id' => $shift->id,
                        'operator_id' => $operator->id, 'created_by' => $admin->id, 'expense_date' => $date,
                        'amount' => number_format(650 + (($n * 173) % 900), 2, '.', ''), 'payment_mode' => ['Cash','UPI','Bank'][$n % 3],
                        'remarks' => $expenseRef.' · fictional training entry', 'created_at' => $day->copy()->setTime(18, 0),
                        'updated_at' => $day->copy()->setTime(18, 0),
                    ]);
                } elseif ($existingExpenses->count() > 1) {
                    // Retain the first row and soft-delete only extra copies made by this demo seeder.
                    DB::table('expenses')->whereIn('id', $existingExpenses->slice(1)->all())
                        ->update(['deleted_at' => now(), 'updated_at' => now()]);
                }

                $auditKey = 'demo-data-'.$date;
                if (!DB::table('audit_logs')->where('station_id', $station->id)->where('action', $auditKey)->exists()) {
                    DB::table('audit_logs')->insert([
                        'user_id' => $admin->id, 'station_id' => $station->id, 'action' => $auditKey,
                        'entity_type' => 'demo_training_day', 'entity_id' => $shift->id,
                        'details' => json_encode(['label' => self::PREFIX, 'date' => $date]),
                        'created_at' => $day->copy()->setTime(18, 5), 'updated_at' => $day->copy()->setTime(18, 5),
                    ]);
                }
                $dayCount++;
            }
        });

        $this->command?->info("Added or confirmed DEMO DATA for {$dayCount} days ending ".Carbon::today()->toDateString().'. Existing non-demo records were preserved.');
    }

    private function record(string $table, array $keys, array $values): int
    {
        $existing = DB::table($table)->where($keys)->first();
        if ($existing) return (int) $existing->id;
        $now = now();
        return (int) DB::table($table)->insertGetId($keys + $values + ['created_at' => $now, 'updated_at' => $now]);
    }
}

