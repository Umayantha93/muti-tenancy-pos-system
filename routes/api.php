<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BalanceSheetController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BillItemController;
use App\Http\Controllers\BillPaymentController;
use App\Http\Controllers\CottageRoomController;
use App\Http\Controllers\CottageStayController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PhotoBookingController;
use App\Http\Controllers\PhotoPackageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RetailSaleController;
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
        Route::get('/feature-catalog', [SuperAdminTenantController::class, 'catalog']);
        Route::apiResource('tenants', SuperAdminTenantController::class);
        Route::post('/tenants/{tenant}', [SuperAdminTenantController::class, 'update']);
        Route::post('/tenants/{tenant}/activate', [SuperAdminTenantController::class, 'activate']);
        Route::post('/tenants/{tenant}/deactivate', [SuperAdminTenantController::class, 'deactivate']);
        Route::get('/tenants/{tenant}/features', [SuperAdminTenantController::class, 'features']);
        Route::put('/tenants/{tenant}/features', [SuperAdminTenantController::class, 'updateFeatures']);
        Route::get('/tenants/{tenant}/users', [SuperAdminTenantController::class, 'users']);
        Route::post('/tenants/{tenant}/users', [SuperAdminTenantController::class, 'storeUser']);
        Route::put('/tenants/{tenant}/dual-financial-view', [SuperAdminTenantController::class, 'updateDualFinancialView']);
        Route::get('/tenants/{tenant}/fee-payments', [SuperAdminTenantController::class, 'feePayments']);
        Route::put('/tenants/{tenant}/fee-payments/{year}/{month}', [SuperAdminTenantController::class, 'updateFeePayment']);
        Route::post('/users/{user}/activate', [SuperAdminUserController::class, 'activate']);
        Route::post('/users/{user}/deactivate', [SuperAdminUserController::class, 'deactivate']);
        Route::delete('/users/{user}', [SuperAdminUserController::class, 'destroy']);
    });

    Route::middleware('role:business_owner,staff')->middleware('block.secondary.writes')->group(function () {
        Route::get('/dashboard', DashboardController::class);

        Route::middleware('feature:admit_vehicle')->group(function () {
            Route::apiResource('vehicles', VehicleController::class)->except('destroy');
        });

        Route::middleware('feature:customers,admit_vehicle,photo_bookings,retail_pos,cottage_stays')->group(function () {
            Route::apiResource('customers', CustomerController::class)->except('destroy');
        });

        Route::middleware('feature:billing')->group(function () {
            Route::apiResource('bills', BillController::class)->only(['index', 'store', 'show', 'update']);
            Route::post('/bills/from-vehicle', [BillController::class, 'storeFromVehicle']);
            Route::post('/bills/{bill}/items', [BillItemController::class, 'store']);
            Route::delete('/bills/{bill}/items/{item}', [BillItemController::class, 'destroy']);
            Route::post('/bills/{bill}/payments', [BillPaymentController::class, 'store']);
            Route::delete('/bills/{bill}/payments/{payment}', [BillPaymentController::class, 'destroy']);
        });

        Route::middleware('feature:parts_inventory')->group(function () {
            Route::get('/parts', [PartController::class, 'index']);
            Route::get('/parts/{part}', [PartController::class, 'show']);
        });

        Route::middleware('feature:photo_packages')->group(function () {
            Route::get('/photo-packages', [PhotoPackageController::class, 'index']);
            Route::post('/photo-packages', [PhotoPackageController::class, 'store']);
            Route::put('/photo-packages/{package}', [PhotoPackageController::class, 'update']);
            Route::delete('/photo-packages/{package}', [PhotoPackageController::class, 'destroy']);
        });

        Route::middleware('feature:photo_bookings')->group(function () {
            Route::get('/photo-bookings', [PhotoBookingController::class, 'index']);
            Route::post('/photo-bookings', [PhotoBookingController::class, 'store']);
            Route::get('/photo-bookings/{booking}', [PhotoBookingController::class, 'show']);
            Route::put('/photo-bookings/{booking}', [PhotoBookingController::class, 'update']);
        });

        Route::middleware('feature:product_catalog')->group(function () {
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/{product}', [ProductController::class, 'show']);
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{product}', [ProductController::class, 'update']);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);
            Route::post('/products/{product}/restock', [ProductController::class, 'restock']);
        });

        Route::middleware('feature:retail_pos')->group(function () {
            Route::get('/retail-sales', [RetailSaleController::class, 'index']);
            Route::post('/retail-sales', [RetailSaleController::class, 'store']);
            Route::get('/retail-sales/{sale}', [RetailSaleController::class, 'show']);
        });

        Route::middleware('feature:cottage_rooms')->group(function () {
            Route::get('/cottage-rooms', [CottageRoomController::class, 'index']);
            Route::post('/cottage-rooms', [CottageRoomController::class, 'store']);
            Route::put('/cottage-rooms/{room}', [CottageRoomController::class, 'update']);
            Route::delete('/cottage-rooms/{room}', [CottageRoomController::class, 'destroy']);
        });

        Route::middleware('feature:cottage_stays')->group(function () {
            Route::get('/cottage-stays', [CottageStayController::class, 'index']);
            Route::post('/cottage-stays', [CottageStayController::class, 'store']);
            Route::get('/cottage-stays/{stay}', [CottageStayController::class, 'show']);
            Route::put('/cottage-stays/{stay}', [CottageStayController::class, 'update']);
        });

        Route::prefix('tenant')->middleware('role:business_owner')->group(function () {
            Route::get('/staff', [TenantStaffController::class, 'index']);
            Route::post('/staff', [TenantStaffController::class, 'store']);
            Route::get('/staff/{user}/permissions', [TenantStaffController::class, 'permissions']);
            Route::put('/staff/{user}/permissions', [TenantStaffController::class, 'updatePermissions']);
            Route::post('/staff/{user}/deactivate', [TenantStaffController::class, 'deactivate']);
        });

        Route::middleware('role:business_owner')->group(function () {
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('feature:customers,admit_vehicle,photo_bookings,retail_pos,cottage_stays');
            Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->middleware('feature:admit_vehicle');
            Route::post('/parts', [PartController::class, 'store'])->middleware('feature:parts_inventory');
            Route::get('/parts/import/template', [PartController::class, 'importTemplate'])->middleware('feature:parts_inventory');
            Route::post('/parts/import', [PartController::class, 'import'])->middleware('feature:parts_inventory');
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
