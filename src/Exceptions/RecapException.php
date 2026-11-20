<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Exceptions;

use RuntimeException;

/**
 * A step of the weekly recap failed. The message is written for the admin
 * and is shown in the recap modal as is.
 *
 * @since 1.6.0
 */
final class RecapException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self(__('AI recap drafting isn\'t available. Check that AI is set up and consented in AI Studio, that "Weekly recap posts" is on under AI Studio → Features, and that the GitHub App is configured in ArtisanPack UI settings.'));
    }

    public static function malformedOutput(string $detail): self
    {
        return new self(__('The AI sent back something the plugin couldn\'t use (:detail). Try again.', ['detail' => $detail]));
    }

    public static function invalidUrl(string $url): self
    {
        return new self(__('":url" can\'t be read.', ['url' => mb_substr($url, 0, 200)]));
    }
}
