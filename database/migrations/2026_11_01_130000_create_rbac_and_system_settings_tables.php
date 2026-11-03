<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name')->unique(); $table->string('slug')->unique(); $table->text('description')->nullable(); $table->boolean('system')->default(false); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->unique(); $table->string('module'); $table->text('description')->nullable(); $table->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete(); $table->foreignId('permission_id')->constrained()->cascadeOnDelete(); $table->primary(['role_id','permission_id']);
        });
        Schema::table('users', function (Blueprint $table) { $table->foreignId('role_id')->nullable()->after('role')->constrained()->nullOnDelete(); });
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id(); $table->string('key')->unique(); $table->longText('value')->nullable(); $table->timestamps();
        });
        foreach ([
            ['dashboard.view','View dashboard','Dashboard'],['shifts.view','View shifts','Shifts'],['shifts.manage','Open, assign and approve shifts','Shifts'],['shifts.close','Enter shift closing readings and collections','Shifts'],
            ['rates.view','View fuel rates','Rates'],['rates.manage','Set daily fuel rates','Rates'],['invoices.view','View invoices','Invoices'],['invoices.manage','Create invoices','Invoices'],
            ['customers.view','View customers','Customers'],['customers.manage','Manage customers and receipts','Customers'],['expenses.view','View expenses','Expenses'],['expenses.manage','Manage expenses','Expenses'],
            ['users.view','View users','Users'],['users.manage','Create and manage users','Users'],['roles.manage','Manage roles and permissions','Roles'],['setup.manage','Manage station masters','Setup'],['settings.manage','Manage company settings','Settings'],
            ['reports.daily-sales','Daily sales report','Reports'],['reports.shift-report','Shift report','Reports'],['reports.nozzle-sales','Nozzle sales report','Reports'],['reports.operator-performance','Operator performance report','Reports'],['reports.payment-collection','Payment collection report','Reports'],['reports.expense-report','Expense report','Reports'],['reports.profit-loss','Profit and loss report','Reports'],['reports.rate-history','Rate history report','Reports'],['reports.invoice-register','Invoice register report','Reports'],['reports.customer-outstanding','Customer outstanding report','Reports'],['reports.cash-short-excess','Cash short / excess report','Reports'],['reports.audit-log','Audit log report','Reports'],
        ] as [$slug,$name,$module]) DB::table('permissions')->insert(['slug'=>$slug,'name'=>$name,'module'=>$module,'created_at'=>now(),'updated_at'=>now()]);
        foreach ([['Super Admin','super-admin'],['Owner','owner'],['Manager','manager'],['Operator','operator']] as [$name,$slug]) {
            $id=DB::table('roles')->insertGetId(['name'=>$name,'slug'=>$slug,'system'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('users')->where('role',$name)->update(['role_id'=>$id]);
            $allowed=match($slug){
                'super-admin'=>DB::table('permissions')->pluck('id'),
                'owner'=>DB::table('permissions')->whereIn('module',['Dashboard','Shifts','Rates','Invoices','Customers','Expenses','Reports'])->pluck('id'),
                'manager'=>DB::table('permissions')->whereIn('module',['Dashboard','Shifts','Rates','Invoices','Customers','Expenses'])->pluck('id')->merge(DB::table('permissions')->where('module','Reports')->whereIn('slug',['reports.daily-sales','reports.shift-report','reports.nozzle-sales','reports.payment-collection','reports.expense-report','reports.invoice-register','reports.customer-outstanding','reports.cash-short-excess'])->pluck('id')),
                default=>DB::table('permissions')->whereIn('slug',['dashboard.view','shifts.view','shifts.close'])->pluck('id'),
            };
            foreach($allowed as $permissionId) DB::table('permission_role')->insert(['role_id'=>$id,'permission_id'=>$permissionId]);
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('system_settings'); Schema::table('users',fn(Blueprint $table)=>$table->dropConstrainedForeignId('role_id')); Schema::dropIfExists('permission_role'); Schema::dropIfExists('permissions'); Schema::dropIfExists('roles');
    }
};
