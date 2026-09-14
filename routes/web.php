<?php

use App\Http\Controllers\DailyFamilyReportController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // 日次の連絡帳（F-16）。送迎時にお渡しする1枚。
    Route::get('records/{serviceRecord}/family-report', [DailyFamilyReportController::class, 'show'])
        ->name('records.family-report');
});

require __DIR__.'/settings.php';
