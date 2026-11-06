<?php

declare(strict_types=1);

namespace Modules\ContentModel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for Keystone's `DynamicContentTerm`, which only exists inside
 * the host: the same table and fillable columns, without the host's tree
 * helpers. Autoloaded for the test suite only (`autoload-dev`), so the
 * real model always wins inside Keystone.
 */
class DynamicContentTerm extends Model
{
    protected $table = 'keystone_dynamic_content_terms';

    protected $fillable = ['taxonomy_slug', 'name', 'slug', 'description', 'parent_id'];
}
