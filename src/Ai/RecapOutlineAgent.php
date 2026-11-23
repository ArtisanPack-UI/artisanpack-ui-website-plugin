<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Ai;

use ArtisanPackUI\Ai\Agents\ArtisanPackAgent;
use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Site\Services\Recap\RecapPrompts;
use ArtisanPackUI\Site\Services\Recap\RecapWriter;

/**
 * Writes and revises the weekly recap's outline. The prompt, schema and
 * input live in {@see RecapPrompts}; this class only talks to Keystone's AI
 * package, so it is loaded only where that package is installed (see
 * {@see KeystoneRecapWriter}).
 *
 * Registered as the {@see RecapWriter::FEATURE_KEY} feature, so it shows in
 * AI Studio → Features, is budget-tracked, and stops when switched off.
 * Never cached: every turn revises the outline.
 *
 * The model defaults to Claude Opus 5.5, but the model saved with the AI
 * credentials (or a per-feature override in AI Studio) wins.
 *
 * @since 1.6.0
 */
final class RecapOutlineAgent extends ArtisanPackAgent
{
    public string $featureKey = RecapWriter::FEATURE_KEY;

    public string $package = 'artisanpack-ui/website-plugin';

    public string $defaultModel = 'claude-opus-5-5';

    public bool $cacheable = false;

    public function instructions(): string
    {
        return RecapPrompts::outlineInstructions();
    }

    public function outputSchema(): array
    {
        return RecapPrompts::outlineSchema();
    }

    protected function execute(Credentials $credentials, string $model, string $instructions): array
    {
        $input = is_array($this->input) ? $this->input : [];

        $result = app(AgentPrompter::class)->prompt(
            credentials: $credentials,
            model: $model,
            instructions: $instructions,
            message: RecapPrompts::message($input, RecapPrompts::outlineTask($input)),
            outputSchema: $this->outputSchema(),
            tools: $this->tools(),
        );

        return [
            'output'        => is_array($result['output'] ?? null) ? $result['output'] : [],
            'input_tokens'  => (int) ($result['input_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
        ];
    }
}
