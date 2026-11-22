<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shift_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('checkpoint_at')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('shift_checkpoint_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkpoint_id')->constrained('shift_checkpoints')->cascadeOnDelete();
            $table->foreignId('shift_reading_id')->constrained()->cascadeOnDelete();
            $table->decimal('meter_reading', 14, 3);
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['checkpoint_id', 'shift_reading_id']);
        });

        Schema::create('shift_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('collected_at')->index();
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('upi_amount', 14, 2)->default(0);
            $table->decimal('bank_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('credit_amount', 14, 2)->default(0);
            $table->json('denominations')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_collections');
        Schema::dropIfExists('shift_checkpoint_readings');
        Schema::dropIfExists('shift_checkpoints');
    }
};
