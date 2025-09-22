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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('calendar_sources')->cascadeOnDelete();
            $table->string('uid')->index();
            $table->string('summary');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->boolean('all_day')->default(false);
            $table->dateTimeTz('start_at');
            $table->dateTimeTz('end_at')->nullable();
            $table->string('status')->default('CONFIRMED');
            $table->string('hash')->index();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['source_id','uid','start_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
