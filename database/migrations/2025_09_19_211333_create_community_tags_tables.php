<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::create('community_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('community_post_tag', function (Blueprint $table) {
            $table->foreignUlid('post_id')->constrained('community_posts')->cascadeOnDelete();
            $table->foreignUlid('tag_id')->constrained('community_tags')->cascadeOnDelete();
            $table->primary(['post_id','tag_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('community_post_tag');
        Schema::dropIfExists('community_tags');
    }
};
