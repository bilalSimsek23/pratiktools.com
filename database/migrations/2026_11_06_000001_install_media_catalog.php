<?php

use App\Site\InitialContent;
use App\Site\MediaCatalog;
use Illuminate\Database\Migrations\Migration;

/**
 * Media technologies & production automation starting content, and the
 * broader home page message. Inserts only missing slugs; the home page is
 * changed only where it still holds its first values (panel edits win).
 */
return new class extends Migration
{
    public function up(): void
    {
        MediaCatalog::install();
        InitialContent::upgradeHome();
    }

    public function down(): void {}
};
