<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Exceptions;

use RuntimeException;

/**
 * A Packagist or npm lookup failed. The message is written for an admin.
 *
 * @since 1.0.0
 */
final class RegistryException extends RuntimeException {}
