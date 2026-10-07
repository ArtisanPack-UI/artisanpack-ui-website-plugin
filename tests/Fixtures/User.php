<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Minimal authenticatable for the plugin's Testbench suite.
 *
 * `$permissions` stands in for the host's RBAC grants: the suite's Gate
 * checks a plugin permission by looking it up here (see
 * `TestCase::defineEnvironment()`). It is a plain property, not a column.
 */
class User extends Authenticatable
{
    /** @var list<string> */
    public array $permissions = [];

    protected $table = 'users';

    protected $guarded = [];
}
