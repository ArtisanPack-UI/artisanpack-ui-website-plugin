<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\RecapException;

/**
 * Writes the weekly recap with AI: the outline, revised turn by turn, and
 * then the post itself.
 *
 * The plugin binds {@see NullRecapWriter} by default and the provider swaps
 * in {@see \ArtisanPackUI\Site\Ai\KeystoneRecapWriter} when Keystone's AI
 * module is installed, so nothing else depends on the AI package being
 * there. The inputs are built by {@see RecapPrompts}, and the outputs are
 * normalized by it before they're returned.
 *
 * @phpstan-type RecapSource array{url: string, kind: string, title: string, text: string}
 * @phpstan-type RecapOutline array{outline: string, reply: string, packages: list<string>, sources: list<RecapSource>}
 * @phpstan-type RecapDraft array{body: string, excerpt: string, focusKeyword: string, metaDescription: string, tags: list<string>, newTags: list<string>, packages: list<string>, sources: list<RecapSource>}
 *
 * @since 1.6.0
 */
interface RecapWriter
{
    /**
     * The AI feature the recap runs under, toggled in AI Studio → Features.
     */
    public const FEATURE_KEY = 'artisanpack-ui.weekly_recap';

    /**
     * Whether AI is set up, consented and the recap feature is on.
     */
    public function isAvailable(): bool;

    /**
     * Write or revise the outline. `sources` lists what the AI fetched
     * while writing it.
     *
     * @param  array<string, mixed>  $input  {@see RecapPrompts::outlineInput()}
     *
     * @return RecapOutline
     *
     * @throws RecapException
     */
    public function outline(array $input): array;

    /**
     * Write the post from the approved outline.
     *
     * @param  array<string, mixed>  $input  {@see RecapPrompts::draftInput()}
     *
     * @return RecapDraft
     *
     * @throws RecapException
     */
    public function draft(array $input): array;
}
