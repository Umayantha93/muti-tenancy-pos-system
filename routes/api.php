<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BalanceSheetController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BillItemController;
use App\Http\Controllers\BillPaymentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\SuperAdminTenantController;
use App\Http\Controllers\SuperAdminUserController;
use App\Http\Controllers\TenantStaffController;
use App\Http\Controllers\VehicleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/auth/branding', [AuthController::class, 'branding'])->middleware('throttle:30,1');
Route::post('/attendance/ingest', [AttendanceController::class, 'ingest'])->middleware('throttle:120,1');

Route::middleware(['auth:sanctum', 'user.active', 'tenant.active'])->group(function () {
    Route::get('/user', fn (Request $request) => [
        'user' => $request->user()->load('tenant'),
        'features' => $request->user()->accessibleFeatureKeys(),
    ]);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::prefix('super-admin')->middleware('role:super_admin')->group(function () {
        Route::get('/dashboard', [SuperAdminTenantController::class, 'dashboard']);
        Route::apiResource('tenants', SuperAdminTenantController::class);
        Route::post('/tenants/{tenant}', [SuperAdminTenantController::class, 'update']);
        Route::post('/tenants/{tenant}/activate', [SuperAdminTenantController::class, 'activate']);
        Route::post('/tenants/{tenant}/deactivate', [SuperAdminTenantController::class, 'deactivate']);
        Route::get('/tenants/{tenant}/features', [SuperAdminTenantController::class, 'features']);
        Route::put('/tenants/{tenant}/features', [SuperAdminTenantController::class, 'updateFeatures']);
        Route::get('/tenants/{tenant}/users', [SuperAdminTenantController::class, 'users']);
        Route::post('/tenants/{tenant}/users', [SuperAdminTenantController::class, 'storeUser']);
        Route::post('/users/{user}/activate', [SuperAdminUserController::class, 'activate']);
        Route::post('/users/{user}/deactivate', [SuperAdminUserController::class, 'deactivate']);
        Route::delete('/users/{user}', [SuperAdminUserController::class, 'destroy']);
    });

    Route::middleware('role:business_owner,staff')->group(function () {
        Route::get('/dashboard', DashboardController::class);

        Route::middleware('feature:admit_vehicle')->group(function () {
            Route::apiResource('vehicles', VehicleController::class)->except('destroy');
        });

        Route::middleware('feature:customers,admit_vehicle')->group(function () {
            Route::apiResource('customers', CustomerController::class)->except('destroy');
        });

        Route::middleware('feature:billing')->group(function () {
            Route::apiResource('bills', BillController::class)->only(['index', 'store', 'show', 'update']);
            Route::post('/bills/from-vehicle', [BillController::class, 'storeFromVehicle']);
            Route::post('/bills/{bill}/items', [BillItemController::class, 'store']);
            Route::delete('/bills/{bill}/items/{item}', [BillItemController::class, 'destroy']);
            Route::post('/bills/{bill}/payments', [BillPaymentController::class, 'store']);
        });

        Route::middleware('feature:parts_inventory')->group(function () {
            Route::get('/parts', [PartController::class, 'index']);
            Route::get('/parts/{part}', [PartController::class, 'show']);
        });

        Route::prefix('tenant')->middleware('role:business_owner')->group(function () {
            Route::get('/staff', [TenantStaffController::class, 'index']);
            Route::post('/staff', [TenantStaffController::class, 'store']);
            Route::get('/staff/{user}/permissions', [TenantStaffController::class, 'permissions']);
            Route::put('/staff/{user}/permissions', [TenantStaffController::class, 'updatePermissions']);
            Route::post('/staff/{user}/deactivate', [TenantStaffController::class, 'deactivate']);
        });

        Route::middleware('role:business_owner')->group(function () {
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('feature:customers,admit_vehicle');
            Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->middleware('feature:admit_vehicle');
            Route::post('/parts', [PartController::class, 'store'])->middleware('feature:parts_inventory');
            Route::put('/parts/{part}', [PartController::class, 'update'])->middleware('feature:parts_inventory');
            Route::post('/parts/{part}', [PartController::class, 'update'])->middleware('feature:parts_inventory');
            Route::delete('/parts/{part}', [PartController::class, 'destroy'])->middleware('feature:parts_inventory');
            Route::post('/parts/{part}/image', [PartController::class, 'image'])->middleware('feature:parts_inventory');
            Route::post('/parts/{part}/restock', [PartController::class, 'restock'])->middleware('feature:parts_inventory');
        });

        Route::middleware('feature:employees_management,attendance')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
        });
        Route::middleware('feature:employees_management')->group(function () {
            Route::post('/employees', [EmployeeController::class, 'store']);
            Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
            Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
        });
        Route::middleware('feature:attendance')->group(function () {
            Route::get('/attendance', [AttendanceController::class, 'index']);
            Route::post('/attendance', [AttendanceController::class, 'store']);
            Route::post('/attendance/punch', [AttendanceController::class, 'punch']);
        });
        Route::middleware('feature:payroll')->group(function () {
            Route::get('/payroll', [PayrollController::class, 'index']);
            Route::post('/payroll/generate', [PayrollController::class, 'generate']);
        });
        Route::middleware('feature:balance_sheet')->group(function () {
            Route::apiResource('expenses', ExpenseController::class)->except('show');
            Route::get('/balance-sheet', BalanceSheetController::class);
        });
    });
});
