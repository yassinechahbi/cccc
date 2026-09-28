<?php

use App\Http\Controllers\CircuitPdfController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// Public: a member confirms a step by scanning the QR code or typing the code printed under it.
Volt::route('track', 'track.index')->name('track');
Volt::route('r/{reference}', 'track.leg')->middleware('throttle:track')->name('track.leg');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');

    Volt::route('circuits', 'circuits.index')->name('circuits.index');
    Volt::route('circuits/create', 'circuits.create')->middleware('can:manage-circuits')->name('circuits.create');
    Volt::route('circuits/{circuit:number}', 'circuits.show')->name('circuits.show');
    Route::get('circuits/{circuit:number}/pdf', CircuitPdfController::class)->name('circuits.pdf');

    Volt::route('members', 'members.index')->middleware('can:manage-circuits')->name('members.index');

    // Every member edits their own record; an admin can edit anyone's.
    Volt::route('profile', 'members.profile')->name('profile');
    Volt::route('members/{member:member_number}/edit', 'members.profile')->middleware('can:admin')->name('members.edit');

    Volt::route('admin/users', 'admin.users')->middleware('can:admin')->name('admin.users');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
