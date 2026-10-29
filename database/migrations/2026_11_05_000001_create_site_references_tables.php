<?php

use App\Site\InitialReferences;
use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website references ("we worked with"), separate from projects / case
 * studies ("what we did"). Platform-level website content like site_*:
 * no tenant data. A real name is public only with a recorded client-name
 * approval, a logo only with a separate logo approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 140)->unique();
            $table->string('name', 160);
            $table->uuid('logo_media_id')->nullable();
            // Optional variant for dark backgrounds.
            $table->uuid('logo_dark_media_id')->nullable();
            $table->string('website_url', 300)->nullable();
            $table->string('industry', 120)->nullable();
            $table->string('short_description', 300)->default('');
            // Markdown; rendered without raw HTML.
            $table->text('body')->default('');
            $table->jsonb('capabilities')->default('[]');
            $table->boolean('homepage_featured')->default(false);
            $table->integer('featured_order')->default(0);
            $table->boolean('active')->default(true);
            $table->string('status', 16)->default('draft');
            $table->boolean('client_name_publication_approved')->default(false);
            $table->string('name_approved_by', 64)->nullable();
            $table->timestamp('name_approved_at')->nullable();
            $table->boolean('logo_use_approved')->default(false);
            $table->string('logo_approved_by', 64)->nullable();
            $table->timestamp('logo_approved_at')->nullable();
            $table->string('approval_note', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('created_by', 64);
            $table->string('updated_by', 64);
            $table->timestamps();

            $table->foreign('logo_media_id')->references('id')->on('site_media')->nullOnDelete();
            $table->foreign('logo_dark_media_id')->references('id')->on('site_media')->nullOnDelete();
            $table->index(['status', 'active', 'homepage_featured', 'featured_order']);
        });
        DB::statement("ALTER TABLE site_references ADD CONSTRAINT site_references_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement("ALTER TABLE site_references ADD CONSTRAINT site_references_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement("ALTER TABLE site_references ADD CONSTRAINT site_references_url_check CHECK (website_url IS NULL OR website_url ~ '^https?://')");

        // Projects (case studies) and services a reference is linked to.
        Schema::create('site_reference_contents', function (Blueprint $table) {
            $table->uuid('reference_id');
            $table->uuid('content_id');
            $table->integer('position')->default(0);

            $table->primary(['reference_id', 'content_id']);
            $table->foreign('reference_id')->references('id')->on('site_references')->cascadeOnDelete();
            $table->foreign('content_id')->references('id')->on('site_contents')->cascadeOnDelete();
        });

        Rls::grantApp('site_references', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        Rls::grantApp('site_reference_contents', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        // Starting references: drafts, no approvals (operator records them).
        InitialReferences::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('site_reference_contents');
        Schema::dropIfExists('site_references');
    }
};
