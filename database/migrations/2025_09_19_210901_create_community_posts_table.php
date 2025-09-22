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
        Schema::create('community_posts', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Where the post came from
            $table->enum('source', ['internal', 'instagram'])->index();
            $table->string('source_post_id')->nullable()->index(); // IG media id, etc.

            // Optional author polymorph (works with your separate Parents/Students tables or users)
            $table->nullableMorphs('author'); // author_type, author_id (nullable for IG)

            // Core content
            $table->text('caption')->nullable();
            $table->string('permalink')->nullable();      // external link (IG)
            $table->timestamp('posted_at')->index();      // when it was posted (IG timestamp or internal created)
            $table->enum('visibility', ['public', 'school', 'private'])->default('public')->index();
            $table->boolean('is_pinned')->default(false)->index();

            // Cached metrics (for fast list rendering)
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);

            $table->json('extra')->nullable(); // any provider-specific fields (e.g., media_type)
            $table->timestamps();
            $table->index(['source', 'posted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_posts');
    }
};
