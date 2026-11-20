<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly recap post settings: the featured image every recap post
 * gets, the title pattern, and the style guide the AI writes to.
 *
 * @since 1.6.0
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artisanpack_ui_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('recap_featured_image_id')->nullable();
            $table->string('recap_title_pattern')->nullable();
            $table->text('recap_style_guide')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artisanpack_ui_settings', function (Blueprint $table): void {
            $table->dropColumn(['recap_featured_image_id', 'recap_title_pattern', 'recap_style_guide']);
        });
    }
};
