<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            if (! Schema::hasColumn('bills', 'floor_status')) {
                $table->string('floor_status', 32)->default('waiting')->after('job_kind');
            }
            if (! Schema::hasColumn('bills', 'next_service_due_on')) {
                $table->date('next_service_due_on')->nullable()->after('next_service_mileage');
            }
            if (! Schema::hasColumn('bills', 'service_reminder_sent_at')) {
                $table->timestamp('service_reminder_sent_at')->nullable()->after('next_service_due_on');
            }
        });

        Schema::create('bays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('job_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bay_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number_plate', 30)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('booked');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);
            $table->index(['bay_id', 'starts_at']);
            $table->index(['employee_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_bookings');
        Schema::dropIfExists('bays');
        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'service_reminder_sent_at')) {
                $table->dropColumn('service_reminder_sent_at');
            }
            if (Schema::hasColumn('bills', 'next_service_due_on')) {
                $table->dropColumn('next_service_due_on');
            }
            if (Schema::hasColumn('bills', 'floor_status')) {
                $table->dropColumn('floor_status');
            }
        });
    }
};
