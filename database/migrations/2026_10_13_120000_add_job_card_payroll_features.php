<?php

use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
            $table->string('position')->nullable()->change();
            $table->decimal('base_salary', 14, 2)->nullable()->default(0)->change();
            $table->string('fingerprint_id')->nullable()->change();
            $table->string('pay_type', 20)->default('monthly')->after('base_salary');
            $table->decimal('daily_rate', 14, 2)->nullable()->after('pay_type');
            $table->decimal('commission_percent', 5, 2)->nullable()->after('daily_rate');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->string('section', 20)->nullable()->after('type');
        });

        Schema::table('bill_photos', function (Blueprint $table) {
            $table->string('kind', 20)->default('job')->after('bill_id');
        });

        Schema::table('bill_employees', function (Blueprint $table) {
            $table->decimal('commission_amount', 14, 2)->nullable()->after('employee_id');
            $table->timestamp('commission_deducted_at')->nullable()->after('commission_amount');
            $table->unsignedBigInteger('employee_payment_id')->nullable()->after('commission_deducted_at');
        });

        Schema::create('employee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'kind', 'paid_on']);
        });

        foreach ($this->features() as $feature) {
            Feature::query()->updateOrCreate(['key' => $feature['key']], $feature);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payments');

        Schema::table('bill_employees', function (Blueprint $table) {
            $table->dropColumn(['commission_amount', 'commission_deducted_at', 'employee_payment_id']);
        });

        Schema::table('bill_photos', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn('section');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['pay_type', 'daily_rate', 'commission_percent']);
        });

        $ids = DB::table('features')->whereIn('key', ['admit_combined', 'admit_photos', 'job_commissions'])->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('user_permissions')->whereIn('feature_id', $ids)->delete();
            DB::table('tenant_features')->whereIn('feature_id', $ids)->delete();
            DB::table('features')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * @return list<array{key: string, name: string, description: string, group: string, sort_order: int}>
     */
    private function features(): array
    {
        return [
            ['key' => 'admit_combined', 'name' => 'Repair and service', 'description' => 'Admit one job card that has both a service section and a repair section. Nested under Admit vehicle. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 12],
            ['key' => 'admit_photos', 'name' => 'Admit photos', 'description' => 'Up to 5 photos when a vehicle is admitted. Nested under Admit vehicle. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 12],
            ['key' => 'job_commissions', 'name' => 'Job commission', 'description' => 'Commission for employees assigned to a job card, deducted into finance. Nested under Job cards. Off until super-admin enables it.', 'group' => 'Service Intake', 'sort_order' => 33],
        ];
    }
};
