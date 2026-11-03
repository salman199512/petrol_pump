<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        Schema::table('invoices',function(Blueprint $table){$table->timestamp('voided_at')->nullable();$table->text('void_reason')->nullable();});
        Schema::table('payments',function(Blueprint $table){$table->timestamp('voided_at')->nullable();$table->text('void_reason')->nullable();});
    }
    public function down():void
    {
        Schema::table('payments',fn(Blueprint $table)=>$table->dropColumn(['voided_at','void_reason']));
        Schema::table('invoices',fn(Blueprint $table)=>$table->dropColumn(['voided_at','void_reason']));
    }
};
