<?php

use App\Http\Controllers\Auth\DiscordController;
use Illuminate\Support\Facades\Route;

// ต้องอยู่ใน web group เพราะ OAuth ใช้ session เก็บ state
Route::prefix('auth/discord')->group(function () {
    Route::get('redirect', [DiscordController::class, 'redirect'])->name('discord.redirect');
    Route::get('callback', [DiscordController::class, 'callback'])->name('discord.callback');
});
