<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('social_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('provider');   // e.g. 'facebook'
            $table->string('type');       // e.g. 'user_long_lived'
            $table->text('access_token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_tokens');
    }
};
