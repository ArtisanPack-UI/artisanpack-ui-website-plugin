<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Ai;

use ArtisanPackUI\Ai\Agents\ArtisanPackAgent;
use ArtisanPackUI\Ai\Contracts\AgentPrompter;
use ArtisanPackUI\Ai\Credentials\Credentials;
use ArtisanPackUI\Site\Services\Recap\RecapPrompts;
use ArtisanPackUI\Site\Services\Recap\RecapWriter;

/**
 * Writes the weekly recap post from the approved outline: the body, excerpt,
 * focus keyword, meta description, tags and packages. The prompt, schema
 * and input live in {@see RecapPrompts}.
 *
 * Runs under the same {@see RecapWriter::FEATURE_KEY} feature as
 * {@see RecapOutlineAgent} (the registry maps a key to one agent class, so
 * only the outline agent is registered), which means switching the feature
 * off stops both and their usage is counted together. Never cached.
 *
 * @since 1.6.0
 */
final class RecapDraftAgent extends ArtisanPackAgent
{
    public string $featureKey = RecapWriter::FEATURE_KEY;

    public string $package = 'artisanpack-ui/website-plugin';

    public string $defaultModel = 'claude-opus-5-5';

    public bool $cacheable = false;

    public function instructions(): string
    {
        return RecapPrompts::draftInstructions();
    }

    public function outputSchema(): array
    {
        return RecapPrompts::draftSchema();
    }

    protected function execute(Credentials $credentials, string $model, string $instructions): array
    {
        $input = is_array($this->input) ? $this->input : [];

        $result = app(AgentPrompter::class)->prompt(
            credentials: $credentials,
            model: $model,
            instructions: $instructions,
            message: RecapPrompts::message($input, RecapPrompts::draftTask()),
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
