<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Shift;
use App\Models\Station;
use App\Models\ShiftType;
use App\Models\FuelType;
use App\Models\Nozzle;
use App\Models\ShiftReading;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftActivityAndCloseTest extends TestCase
{
    public function test_multiple_readings_collections_denominations_and_close_shift(): void
    {
        $admin = User::where('role', 'Super Admin')->first();
        if (!$admin) {
            $this->markTestSkipped('Super Admin user not found');
        }

        $station = Station::where('active', true)->first();
        $operator = User::where('active', true)->whereHas('roleRecord.permissions', fn($q) => $q->where('permissions.slug', 'shifts.close'))->first();
        $type = ShiftType::where('station_id', $station->id)->where('active', true)->first();
        $fuel = FuelType::first();

        // Ensure rate exists
        $date = '2026-11-21';
        DB::table('daily_rates')->updateOrInsert(
            ['station_id' => $station->id, 'fuel_type_id' => $fuel->id, 'rate_date' => $date],
            ['rate' => 97.00, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]
        );

        $nozzle = Nozzle::where('station_id', $station->id)->where('active', true)->first();

        // Create an open shift
        $shift = Shift::create([
            'station_id' => $station->id,
            'shift_type_id' => $type->id,
            'operator_id' => $operator->id,
            'shift_date' => $date,
            'status' => 'Open',
            'sales_total' => 0,
            'cash_collected' => 0,
            'upi_collected' => 0,
            'bank_collected' => 0,
            'card_collected' => 0,
            'credit_sales' => 0,
            'short_excess' => 0,
        ]);

        $reading = ShiftReading::create([
            'shift_id' => $shift->id,
            'nozzle_id' => $nozzle->id,
            'fuel_type_id' => $nozzle->fuel_type_id,
            'opening_reading' => 100.000,
            'rate' => 97.00,
            'quantity' => 0,
            'amount' => 0,
        ]);

        // Entry 1: Morning 10 AM checkpoint
        $resp1 = $this->actingAs($admin)->post(route('shifts.checkpoint', $shift), [
            'readings' => [
                $reading->id => ['meter' => '120.000']
            ],
            'denominations' => [
                '500' => 5,
                '100' => 10,
            ],
            'upi_amount' => 1000,
            'expense_amount' => 150,
            'expense_mode' => 'Cash',
            'remarks' => 'Morning collection and tea expense'
        ]);

        $resp1->assertRedirect();
        $resp1->assertSessionHas('success');

        $this->assertDatabaseHas('shift_checkpoints', [
            'shift_id' => $shift->id,
        ]);
        $this->assertDatabaseHas('shift_collections', [
            'shift_id' => $shift->id,
            'cash_amount' => 3500.00,
            'upi_amount' => 1000.00,
        ]);
        $this->assertDatabaseHas('expenses', [
            'shift_id' => $shift->id,
            'amount' => 150.00,
            'payment_mode' => 'Cash',
        ]);

        $shift->refresh();
        $this->assertEquals(1940.00, (float)$shift->sales_total);
        $this->assertEquals(3500.00, (float)$shift->cash_collected);
        $this->assertEquals(1000.00, (float)$shift->upi_collected);
        // Diff = 4500 collected - 150 expense - 1940 sales = +2410
        $this->assertEquals(2410.00, (float)$shift->short_excess);

        // Entry 2: Afternoon 2 PM checkpoint (additional cash collection & meter movement)
        $resp2 = $this->actingAs($admin)->post(route('shifts.checkpoint', $shift), [
            'readings' => [
                $reading->id => ['meter' => '150.000']
            ],
            'denominations' => [
                '2000' => 2,
                '50' => 5,
            ],
        ]);

        $resp2->assertRedirect();
        $shift->refresh();
        $this->assertEquals(4850.00, (float)$shift->sales_total);
        $this->assertEquals(7750.00, (float)$shift->cash_collected); // 3500 + 4250
        // Total collected = 7750 + 1000 = 8750
        // Diff = 8750 - 150 - 4850 = +3750
        $this->assertEquals(3750.00, (float)$shift->short_excess);

        // Test Day View displays the real-time timeline with timestamps and Edit buttons
        $dayView = $this->actingAs($admin)->get(route('shifts.day', ['date' => $date, 'station_id' => $station->id]));
        $dayView->assertOk();
        $dayView->assertSee('Shift Activity Log & Timestamps');
        $dayView->assertSee('Morning collection and tea expense');
        $dayView->assertSee('Record Reading & Collection');
        $dayView->assertSee('Close shift');
        $dayView->assertSee('data-edit-entry', false);
        $dayView->assertSee('data-delete-entry', false);

        // Test Editing an Entry
        $col1 = DB::table('shift_collections')->where('shift_id', $shift->id)->orderBy('id')->first();
        $cp1 = DB::table('shift_checkpoints')->where('shift_id', $shift->id)->orderBy('id')->first();
        $exp1 = DB::table('expenses')->where('shift_id', $shift->id)->whereNull('deleted_at')->first();

        // Change denominations: 4 notes of 500 = 2000, 10 notes of 100 = 1000 => Cash = 3000 (was 3500)
        // Cash total drops by 500 => Total shift cash should become 7250 instead of 7750
        $editResp = $this->actingAs($admin)->put(route('shifts.activity.update', $shift), [
            'checkpoint_id' => $cp1->id,
            'collection_id' => $col1->id,
            'expense_id' => $exp1->id,
            'readings' => [
                $reading->id => ['meter' => '120.000']
            ],
            'denominations' => [
                '500' => 4,
                '100' => 10,
            ],
            'upi_amount' => 1000,
            'expense_amount' => 150,
            'expense_mode' => 'Cash',
            'remarks' => 'Corrected morning collection'
        ]);

        $editResp->assertRedirect();
        $editResp->assertSessionHas('success');

        $shift->refresh();
        $this->assertEquals(7250.00, (float)$shift->cash_collected);
        // Diff = (7250 + 1000) - 150 - 4850 = +3250
        $this->assertEquals(3250.00, (float)$shift->short_excess);

        // Close Shift via separate close route
        // Does NOT require re-entering collections/denominations!
        $closeResp = $this->actingAs($admin)->post(route('shifts.close', $shift), [
            'readings' => [
                $reading->id => ['closing' => '150.000']
            ],
            'remarks' => 'Shift finished at 6 PM'
        ]);

        $closeResp->assertRedirect();
        $closeResp->assertSessionHas('success');

        $shift->refresh();
        $this->assertEquals('Submitted', $shift->status);
        $this->assertNotNull($shift->submitted_at);
        $this->assertEquals(4850.00, (float)$shift->sales_total);
        $this->assertEquals(7250.00, (float)$shift->cash_collected);
        $this->assertEquals(1000.00, (float)$shift->upi_collected);
        $this->assertEquals(3250.00, (float)$shift->short_excess);

        // Cleanup
        $shift->readings()->delete();
        DB::table('shift_checkpoint_readings')->whereIn('checkpoint_id', $shift->checkpoints()->pluck('id'))->delete();
        $shift->checkpoints()->delete();
        $shift->collections()->delete();
        $shift->expenses()->forceDelete();
        $shift->delete();
    }
}
