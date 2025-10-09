<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tokenable_id'); // user id
            $table->string('tokenable_type'); // or your user model FQCN if different
            $table->string('name')->nullable();          // friendly device name
            $table->string('device_id')->nullable();     // client-generated stable ID
            $table->string('token')->unique();           // sha256 hash of plaintext token
            $table->json('abilities')->nullable();       // e.g. ["device.issue"]
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // optional expiry (null = no expiry)
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tokenable_id']);
            $table->index(['device_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('device_tokens');
    }
};

