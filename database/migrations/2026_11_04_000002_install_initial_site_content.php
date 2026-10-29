<?php

use App\Site\InitialContent;
use Illuminate\Database\Migrations\Migration;

/**
 * Starting website content (only what is missing; never overwrites panel
 * changes). Rolling back the site tables removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        InitialContent::install();
    }

    public function down(): void {}
};
