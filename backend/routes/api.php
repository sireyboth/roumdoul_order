<?php

use App\Http\Controllers\Api\PublicMenuController;
use App\Http\Controllers\Api\PublicOrderController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\StaffBoardController;
use App\Http\Controllers\Api\StaffCashierController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['ok' => true]);

// Customers: no login. Everything is found from the table's QR token.
Route::prefix('public/tables/{token}')
    ->where(['token' => '[A-Za-z0-9]{16,40}'])
    ->group(function () {
        Route::get('/', [PublicMenuController::class, 'show'])->middleware('throttle:public-read');
        Route::get('/session', [PublicOrderController::class, 'session'])->middleware('throttle:public-read');
        Route::post('/orders', [PublicOrderController::class, 'store'])->middleware('throttle:public-write');
        Route::post('/requests', [PublicOrderController::class, 'requestService'])->middleware('throttle:public-write');
    });

// Staff screens (kitchen, waiter, cashier): Sanctum bearer tokens.
Route::prefix('staff')->group(function () {
    Route::post('/login', [StaffAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [StaffAuthController::class, 'me']);
        Route::post('/logout', [StaffAuthController::class, 'logout']);
        Route::get('/branches/{branch}/board', [StaffBoardController::class, 'board']);
        Route::get('/branches/{branch}/menu', [StaffBoardController::class, 'menu']);
        Route::post('/branches/{branch}/menu/{menuItemId}/sold-out', [StaffBoardController::class, 'soldOut'])->whereNumber('menuItemId');
        Route::post('/orders/{order}/status', [StaffBoardController::class, 'updateStatus']);
        Route::post('/requests/{serviceRequest}/done', [StaffBoardController::class, 'resolveRequest']);

        // Cashier: tables, bills, payments (B2)
        Route::get('/branches/{branch}/tables', [StaffCashierController::class, 'tables']);
        Route::post('/sessions/{session}/bill', [StaffCashierController::class, 'openBill']);
        Route::get('/bills/{bill}', [StaffCashierController::class, 'showBill']);
        Route::post('/bills/{bill}/discounts', [StaffCashierController::class, 'addDiscount']);
        Route::post('/bills/{bill}/discounts/{adjustment}/remove', [StaffCashierController::class, 'removeDiscount']);
        Route::post('/bills/{bill}/payments', [StaffCashierController::class, 'pay']);
        Route::post('/bills/{bill}/void', [StaffCashierController::class, 'void']);
        Route::post('/payments/{payment}/refund', [StaffCashierController::class, 'refund']);

        // Waiter takes an order for a table (B2)
        Route::get('/branches/{branch}/order-menu', [StaffCashierController::class, 'orderMenu']);
        Route::post('/branches/{branch}/tables/{table}/orders', [StaffCashierController::class, 'placeOrder']);
    });
});
