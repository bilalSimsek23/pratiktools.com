<?php

use App\Support\Database\RowLevelSecurity as Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F7.5: the PratikTools public website (company site, not tenant data).
 *
 * site_* tables hold public marketing content only: no tenant_id, no
 * personal data, no RLS (they are not tenant tables). They are edited in
 * the panel only by members of the configured site-owner tenant.
 *
 * lead_inquiries is tenant data: the inquiries a website visitor sends
 * become leads of the site-owner tenant through F6 lead capture, with the
 * extra inquiry fields here (FORCE RLS, never readable by the platform).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // service | article
            $table->string('kind', 16);
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->string('description', 1000)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['kind', 'slug']);
        });
        DB::statement("ALTER TABLE site_categories ADD CONSTRAINT site_categories_kind_check CHECK (kind IN ('service', 'article'))");

        Schema::create('site_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Content-addressed: sha256 of the re-encoded image.
            $table->char('sha256', 64)->unique();
            $table->string('disk', 32);
            $table->string('alt', 200)->default('');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            // {"webp": {"1200": "path", "600": "path"}, "jpg": {...}}
            $table->jsonb('variants');
            $table->string('created_by', 64);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('site_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // service | solution | project | article | page
            $table->string('type', 16);
            $table->string('slug', 140);
            $table->string('title', 200);
            $table->string('excerpt', 600)->default('');
            // Markdown; rendered with raw HTML stripped.
            $table->text('body')->default('');
            // Type-specific structured fields (hero, benefits, FAQ, ...).
            $table->jsonb('data')->default('{}');
            $table->uuid('category_id')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 170)->nullable();
            $table->uuid('social_image_id')->nullable();
            $table->boolean('featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->string('created_by', 64);
            $table->string('updated_by', 64);
            $table->string('published_by', 64)->nullable();
            $table->timestamps();

            $table->unique(['type', 'slug']);
            $table->index(['type', 'status', 'sort_order']);
            $table->foreign('category_id')->references('id')->on('site_categories')->nullOnDelete();
            $table->foreign('social_image_id')->references('id')->on('site_media')->nullOnDelete();
        });
        DB::statement("ALTER TABLE site_contents ADD CONSTRAINT site_contents_type_check CHECK (type IN ('service', 'solution', 'project', 'article', 'page'))");
        DB::statement("ALTER TABLE site_contents ADD CONSTRAINT site_contents_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement("ALTER TABLE site_contents ADD CONSTRAINT site_contents_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");

        // Related content (project -> services, article -> services, ...).
        Schema::create('site_content_relations', function (Blueprint $table) {
            $table->uuid('content_id');
            $table->uuid('related_id');

            $table->primary(['content_id', 'related_id']);
            $table->foreign('content_id')->references('id')->on('site_contents')->cascadeOnDelete();
            $table->foreign('related_id')->references('id')->on('site_contents')->cascadeOnDelete();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value');
            $table->string('updated_by', 64)->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        // First-party, privacy-friendly event counts (no cookie, no IP, no PII).
        Schema::create('site_events_daily', function (Blueprint $table) {
            $table->date('day');
            $table->string('event', 32);
            $table->string('path', 200);
            $table->unsignedInteger('count')->default(0);

            $table->primary(['day', 'event', 'path']);
        });

        Schema::create('lead_inquiries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('submission_id')->nullable();
            $table->uuid('recipient_id')->nullable();
            // teklif | gorusme | proje | demo
            $table->string('kind', 16);
            $table->string('name', 120);
            $table->string('phone', 32)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('interest', 140)->nullable();
            $table->text('message');
            // Sanitized first/last touch: source class, utm_*, landing path, referrer host.
            $table->jsonb('attribution')->default('{}');
            // new | contacted | closed
            $table->string('status', 16)->default('new');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'recipient_id'])->references(['tenant_id', 'id'])->on('consent_recipients')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        DB::statement("ALTER TABLE lead_inquiries ADD CONSTRAINT lead_inquiries_kind_check CHECK (kind IN ('teklif', 'gorusme', 'proje', 'demo'))");
        DB::statement("ALTER TABLE lead_inquiries ADD CONSTRAINT lead_inquiries_status_check CHECK (status IN ('new', 'contacted', 'closed'))");

        foreach (['site_categories', 'site_media', 'site_contents', 'site_content_relations', 'site_settings'] as $table) {
            Rls::grantApp($table, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        }
        Rls::grantApp('site_events_daily', ['SELECT', 'INSERT', 'UPDATE']);
        Rls::isolateTenantTable('lead_inquiries');
        Rls::grantApp('lead_inquiries', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        foreach (['lead_inquiries', 'site_events_daily', 'site_settings', 'site_content_relations', 'site_contents', 'site_media', 'site_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
