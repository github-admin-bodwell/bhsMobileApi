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
        Schema::create('community_reactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->nullableMorphs('user'); // student/parent/staff
            $table->enum('type', ['like','heart','clap','wow'])->default('like')->index();
            $table->timestamps();

            $table->unique(['post_id', 'user_type', 'user_id'], 'post_user_unique'); // one reaction per user per post
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_reactions');
    }
};
