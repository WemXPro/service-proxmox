<?php

use Illuminate\Support\Facades\Route;
use App\Services\Proxmox\Service;
use App\Http\Middleware\ThrottleRequests;

Route::prefix('/service/{order}/server')->group(function () {
    Route::get('/send-password', [Service::class, 'resendPassword'])->name('proxmox.password.resend');
    
    // Apply the custom rate limiter to all power actions
    Route::middleware('throttle:proxmox-power-actions')->group(function () {
        Route::get('/start', [Service::class, 'startServer'])->name('proxmox.server.start');
        Route::get('/stop', [Service::class, 'stopServer'])->name('proxmox.server.stop');
        Route::get('/shutdown', [Service::class, 'shutdownServer'])->name('proxmox.server.shutdown');
        Route::get('/reboot', [Service::class, 'rebootServer'])->name('proxmox.server.reboot');
    });
    
    // Additional API endpoints for enhanced functionality
    Route::get('/console', [Service::class, 'getConsoleAccess'])->name('proxmox.server.console');
    Route::get('/console/serial', [Service::class, 'getSerialConsoleAccess'])->name('proxmox.server.console.serial');
    Route::get('/stats', [Service::class, 'getServerStats'])->name('proxmox.server.stats');
    Route::get('/network', [Service::class, 'getNetworkInfo'])->name('proxmox.server.network');
    
    // Reinstall functionality
    Route::post('/reinstall', [Service::class, 'reinstallServer'])->name('proxmox.server.reinstall');
    Route::get('/templates', [Service::class, 'getTemplates'])->name('proxmox.server.templates');
    Route::get('/isos', [Service::class, 'getISOs'])->name('proxmox.server.isos');
    
    // Admin function for resolving lock issues
    Route::get('/force-unlock', [Service::class, 'forceUnlockVM'])->name('proxmox.server.force-unlock');
});