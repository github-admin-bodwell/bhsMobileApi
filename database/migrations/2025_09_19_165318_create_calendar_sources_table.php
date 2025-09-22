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
        Schema::create('calendar_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->text('url');
            $table->string('etag')->nullable();
            $table->timestamp('last_modified')->nullable();
            $table->string('tz')->default('America/Vancouver');
            $table->unsignedInteger('default_span_days')->default(400);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_sources');
    }
};
