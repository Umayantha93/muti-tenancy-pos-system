<?php

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('branch_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'part_id']);
            $table->unique(['branch_id', 'product_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->addBranchId('bills');
        $this->addBranchId('part_sales');
        $this->addBranchId('retail_sales');
        $this->addBranchId('expenses');
        $this->addBranchId('cottage_rooms');
        $this->addBranchId('cottage_stays');
        $this->addBranchId('photo_bookings');
        $this->addBranchId('attendance');
        $this->addBranchId('payrolls');
        $this->addBranchId('stock_receipts');

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('home_branch_id')->nullable()->after('employee_id')->constrained('branches')->nullOnDelete();
            $table->foreignId('last_branch_id')->nullable()->after('home_branch_id')->constrained('branches')->nullOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('home_branch_id')->nullable()->after('tenant_id')->constrained('branches')->nullOnDelete();
        });

        Tenant::query()->withTrashed()->each(function (Tenant $tenant) {
            $branch = Branch::ensureDefault($tenant);
            $id = $tenant->id;
            $branchId = $branch->id;

            foreach (['bills', 'part_sales', 'retail_sales', 'expenses', 'cottage_rooms', 'cottage_stays', 'photo_bookings', 'attendance', 'payrolls', 'stock_receipts'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                    DB::table($table)->where('tenant_id', $id)->whereNull('branch_id')->update(['branch_id' => $branchId]);
                }
            }

            DB::table('users')->where('tenant_id', $id)->whereNull('home_branch_id')->update([
                'home_branch_id' => $branchId,
                'last_branch_id' => $branchId,
            ]);
            if (Schema::hasTable('employees')) {
                DB::table('employees')->where('tenant_id', $id)->whereNull('home_branch_id')->update(['home_branch_id' => $branchId]);
            }

            if (Schema::hasTable('parts')) {
                foreach (DB::table('parts')->where('tenant_id', $id)->get(['id', 'stock_qty']) as $part) {
                    DB::table('branch_stocks')->insert([
                        'tenant_id' => $id,
                        'branch_id' => $branchId,
                        'part_id' => $part->id,
                        'qty' => (int) $part->stock_qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            if (Schema::hasTable('products')) {
                foreach (DB::table('products')->where('tenant_id', $id)->get(['id', 'stock_qty']) as $product) {
                    DB::table('branch_stocks')->insert([
                        'tenant_id' => $id,
                        'branch_id' => $branchId,
                        'product_id' => $product->id,
                        'qty' => (int) $product->stock_qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_branch_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('home_branch_id');
            $table->dropConstrainedForeignId('last_branch_id');
        });

        foreach (['bills', 'part_sales', 'retail_sales', 'expenses', 'cottage_rooms', 'cottage_stays', 'photo_bookings', 'attendance', 'payrolls', 'stock_receipts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('branch_id');
                });
            }
        }

        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('branch_stocks');
        Schema::dropIfExists('branches');
    }

    private function addBranchId(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'branch_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
