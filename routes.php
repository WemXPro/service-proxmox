<?php

use Illuminate\Support\Facades\Route;
use App\Services\Proxmox\Service;
use App\Http\Middleware\ThrottleRequests;

Route::prefix('/service/{order}/server')->group(function () {
    Route::get('/send-password', [Service::class, 'resendPassword'])->name('proxmox.password.resend');
    
    // Apply the custom rate limiter to all routes within the group.
    Route::middleware('throttle:proxmox-power-actions')->group(function () {
        Route::get('/start', [Service::class, 'startServer'])->name('proxmox.server.start');
        Route::get('/stop', [Service::class, 'stopServer'])->name('proxmox.server.stop');
        Route::get('/shutdown', [Service::class, 'shutdownServer'])->name('proxmox.server.shutdown');
        Route::get('/reboot', [Service::class, 'rebootServer'])->name('proxmox.server.reboot');
    });
});
