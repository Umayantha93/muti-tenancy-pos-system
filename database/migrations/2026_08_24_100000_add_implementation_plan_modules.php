<?php

use App\Models\Feature;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('tin', 32)->nullable()->after('address');
            $table->boolean('vat_registered')->default(false)->after('tin');
            $table->boolean('sscl_registered')->default(false)->after('vat_registered');
            $table->decimal('vat_rate', 8, 2)->default(18)->after('sscl_registered');
            $table->decimal('sscl_rate', 8, 2)->default(2.5)->after('vat_rate');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->decimal('vat_rate', 8, 2)->nullable()->after('subtotal');
            $table->decimal('sscl_rate', 8, 2)->nullable()->after('vat_rate');
            $table->decimal('vat_amount', 14, 2)->default(0)->after('sscl_rate');
            $table->decimal('sscl_amount', 14, 2)->default(0)->after('vat_amount');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('asset_kind', 20)->default('vehicle')->after('customer_id');
            $table->string('imei', 40)->nullable()->after('chassis_number');
            $table->string('tyre_size', 40)->nullable()->after('imei');
            $table->string('axle', 40)->nullable()->after('tyre_size');
            $table->string('fault_description', 255)->nullable()->after('axle');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('epf_enabled')->default(false)->after('active');
            $table->unsignedSmallInteger('paid_leave_days_per_year')->nullable()->after('epf_enabled');
            $table->json('allowances')->nullable()->after('paid_leave_days_per_year');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('allowances_total', 14, 2)->default(0)->after('overtime_pay');
            $table->decimal('target_incentive', 14, 2)->default(0)->after('allowances_total');
            $table->decimal('gross_pay', 14, 2)->default(0)->after('target_incentive');
            $table->decimal('epf_employee', 14, 2)->default(0)->after('gross_pay');
            $table->decimal('epf_employer', 14, 2)->default(0)->after('epf_employee');
            $table->decimal('etf_employer', 14, 2)->default(0)->after('epf_employer');
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0)->after('etf_employer');
        });

        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('paid_hours', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('default_shift_id')->nullable()->after('allowances')->constrained('work_shifts')->nullOnDelete();
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_shift_id')->constrained('work_shifts')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('type', 20);
            $table->unsignedSmallInteger('days');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('kind', 20)->default('sales');
            $table->decimal('amount', 14, 2);
            $table->decimal('progress_amount', 14, 2)->default(0);
            $table->decimal('incentive_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number', 40);
            $table->date('received_at');
            $table->string('payment_status', 20)->default('paid');
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_receipt_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $table->foreignId('stock_receipt_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
        });

        $feature = Feature::updateOrCreate(
            ['key' => 'suppliers'],
            [
                'name' => 'Suppliers',
                'description' => 'Supplier directory and goods received notes',
                'group' => 'Inventory',
                'sort_order' => 43,
            ]
        );

        Tenant::query()->each(function (Tenant $tenant) use ($feature) {
            $enabled = $tenant->features()->wherePivot('is_enabled', true)->pluck('features.key');
            if (($enabled->contains('parts_inventory') || $enabled->contains('product_catalog')) && ! $enabled->contains('suppliers')) {
                $tenant->features()->syncWithoutDetaching([$feature->id => ['is_enabled' => true]]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_receipt_id');
            $table->dropConstrainedForeignId('supplier_id');
        });
        Schema::dropIfExists('stock_receipt_items');
        Schema::dropIfExists('stock_receipts');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('employee_targets');
        Schema::dropIfExists('employee_leaves');
        Schema::dropIfExists('shift_assignments');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_shift_id');
        });
        Schema::dropIfExists('work_shifts');
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn([
                'allowances_total', 'target_incentive', 'gross_pay',
                'epf_employee', 'epf_employer', 'etf_employer', 'unpaid_leave_days',
            ]);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['epf_enabled', 'paid_leave_days_per_year', 'allowances']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['asset_kind', 'imei', 'tyre_size', 'axle', 'fault_description']);
        });
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['vat_rate', 'sscl_rate', 'vat_amount', 'sscl_amount']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['tin', 'vat_registered', 'sscl_registered', 'vat_rate', 'sscl_rate']);
        });

        $feature = Feature::where('key', 'suppliers')->first();
        if ($feature) {
            DB::table('user_permissions')->where('feature_id', $feature->id)->delete();
            DB::table('tenant_features')->where('feature_id', $feature->id)->delete();
            $feature->delete();
        }
    }
};
