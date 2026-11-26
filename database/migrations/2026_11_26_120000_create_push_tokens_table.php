<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push notification tokens, one row per signed-in phone.
 *
 * Kept apart from device_tokens on purpose: a device token is a login credential
 * (stored hashed, revoked on password change), a push token is an address that has
 * to be stored readable and is replaced whenever Expo, Apple or Google rolls it.
 *
 * Run it on its own, never with a bare `artisan migrate`:
 *   php artisan migrate --path=database/migrations/2026_11_26_120000_create_push_tokens_table.php
 */
return new class extends Migration {
    public function up(): void {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            // UserAuth (parent) and StudentAuth (student) share tblBHSUserAuth and the
            // same id can belong to both, so the owner is always id plus class.
            $table->unsignedBigInteger('tokenable_id');
            $table->string('tokenable_type');
            $table->string('token')->unique();           // ExponentPushToken[...]
            $table->string('platform', 10);              // ios | android
            $table->string('device_id')->nullable();     // same id the app sends for biometrics
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['tokenable_id', 'tokenable_type']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('push_tokens');
    }
};
