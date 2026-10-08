<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Exceptions;

use RuntimeException;

/**
 * An icon couldn't be stored or referenced: an invalid name, or SVG markup
 * that didn't survive sanitization. The message is written for an admin.
 *
 * @since 1.0.0
 */
final class InvalidIconException extends RuntimeException {}
