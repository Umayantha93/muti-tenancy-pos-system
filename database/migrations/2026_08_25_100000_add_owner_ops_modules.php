<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('amount_paid', 14, 2)->default(0)->after('amount');
        });
        DB::table('expenses')->where('payment_status', 'paid')->update([
            'amount_paid' => DB::raw('amount'),
        ]);

        Schema::create('expense_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('settled_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'settled_on']);
        });

        Schema::table('employee_targets', function (Blueprint $table) {
            $table->string('scope', 20)->default('employee')->after('tenant_id');
        });

        $driver = Schema::getConnection()->getDriverName();
        try {
            Schema::table('employee_targets', function (Blueprint $table) {
                $table->dropForeign(['employee_id']);
            });
        } catch (\Throwable) {
            // SQLite test schema may not have a named foreign key.
        }
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE employee_targets MODIFY employee_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('employee_targets', function (Blueprint $table) {
                $table->unsignedBigInteger('employee_id')->nullable()->change();
            });
        }
        Schema::table('employee_targets', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });

        Schema::create('employee_target_progresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_target_id')->constrained('employee_targets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->decimal('amount', 14, 2);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_target_id', 'employee_id', 'work_date'], 'target_progress_day_unique');
        });

        Schema::table('employee_leaves', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('days');
            $table->foreignId('requested_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_notes')->nullable()->after('reviewed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dateTime('demo_ends_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('demo_ends_at');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });
        Schema::table('employee_leaves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'reviewed_at', 'review_notes']);
        });
        Schema::dropIfExists('employee_target_progresses');
        Schema::table('employee_targets', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('scope');
        });
        DB::statement('ALTER TABLE employee_targets MODIFY employee_id BIGINT UNSIGNED NOT NULL');
        Schema::table('employee_targets', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
        Schema::dropIfExists('expense_settlements');
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
        });
    }
};
