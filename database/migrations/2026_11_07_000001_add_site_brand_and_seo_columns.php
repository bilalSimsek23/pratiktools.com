<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Page-level Open Graph text and noindex for website content (SEO text
 * columns widened: length guidance is a panel hint, not a save limit), and a media
 * kind so brand icons (square PNG favicon / Apple Touch Icon) are kept apart
 * from content images. Brand and SEO settings themselves live in
 * site_settings (no schema change). Platform-level tables, no tenant data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE site_contents ALTER COLUMN seo_title TYPE varchar(200), ALTER COLUMN seo_description TYPE varchar(400)');
        Schema::table('site_contents', function (Blueprint $table) {
            $table->string('og_title', 200)->nullable();
            $table->string('og_description', 400)->nullable();
            $table->boolean('robots_noindex')->default(false);
        });

        Schema::table('site_media', function (Blueprint $table) {
            // image: WebP + JPEG variants; icon: square PNG variants.
            $table->string('kind', 8)->default('image');
        });
        DB::statement("ALTER TABLE site_media ADD CONSTRAINT site_media_kind_check CHECK (kind IN ('image', 'icon'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_media DROP CONSTRAINT IF EXISTS site_media_kind_check');
        Schema::table('site_media', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('site_contents', fn (Blueprint $table) => $table->dropColumn(['og_title', 'og_description', 'robots_noindex']));
        DB::statement('ALTER TABLE site_contents ALTER COLUMN seo_title TYPE varchar(70) USING left(seo_title, 70), ALTER COLUMN seo_description TYPE varchar(170) USING left(seo_description, 170)');
    }
};
