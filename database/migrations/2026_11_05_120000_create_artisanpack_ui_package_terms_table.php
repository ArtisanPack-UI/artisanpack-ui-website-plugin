<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which blog post `package` term belongs to which marketing package (see
 * {@see \ArtisanPackUI\Site\Support\PackageTermSync}), so a renamed package
 * renames its term in place and its post assignments survive.
 *
 * A plugin table rather than a column on `packages`, for the reason the
 * sync state has one: the host's dynamic edit screen only knows about
 * custom fields. Neither id has a foreign key, because `packages` and
 * `keystone_dynamic_content_terms` belong to the host and may not exist
 * when this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisanpack_ui_package_terms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('package_id')->unique();
            $table->unsignedBigInteger('term_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisanpack_ui_package_terms');
    }
};
