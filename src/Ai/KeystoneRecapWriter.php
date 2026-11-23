<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Ai;

use ArtisanPackUI\Ai\Contracts\FeatureRegistry;
use ArtisanPackUI\Ai\Exceptions\BudgetExceededException;
use ArtisanPackUI\Ai\Exceptions\FeatureDisabledException;
use ArtisanPackUI\Ai\Exceptions\MissingCredentialsException;
use ArtisanPackUI\Site\Ai\Tools\FetchRecapSourceTool;
use ArtisanPackUI\Site\Ai\Tools\SearchMergedPullRequestsTool;
use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Services\Recap\MergedPullRequestSearch;
use ArtisanPackUI\Site\Services\Recap\RecapPrompts;
use ArtisanPackUI\Site\Services\Recap\RecapSourceLog;
use ArtisanPackUI\Site\Services\Recap\RecapSourceReader;
use ArtisanPackUI\Site\Services\Recap\RecapWindow;
use ArtisanPackUI\Site\Services\Recap\RecapWriter;
use Illuminate\Support\Facades\Log;
use Modules\Ai\Support\AiRuntime;
use Throwable;

/**
 * The {@see RecapWriter} backed by Keystone's AI module: runs
 * {@see RecapOutlineAgent} and {@see RecapDraftAgent} with the recap tools,
 * and turns the AI package's failures into messages the recap modal can
 * show.
 *
 * Bound only where the AI package is installed (see the plugin's service
 * provider), since the agents extend its base class.
 *
 * @since 1.6.0
 */
final class KeystoneRecapWriter implements RecapWriter
{
    public function __construct(
        private readonly RecapSourceReader $reader,
        private readonly MergedPullRequestSearch $search,
    ) {}

    /**
     * Available when Keystone's AI runtime is ready and consented
     * ({@see AiRuntime::featuresEnabled()}) and the recap feature is on in
     * AI Studio.
     */
    public function isAvailable(): bool
    {
        try {
            if (! class_exists(AiRuntime::class) || ! AiRuntime::featuresEnabled()) {
                return false;
            }

            return app(FeatureRegistry::class)->isToggleOn(self::FEATURE_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    public function outline(array $input): array
    {
        $log    = new RecapSourceLog;
        $window = is_array($input['window'] ?? null) ? $input['window'] : null;
        $tools  = [new FetchRecapSourceTool($this->reader, $log)];

        if (null !== $window && is_string($window['from'] ?? null) && is_string($window['to'] ?? null)) {
            $tools[] = new SearchMergedPullRequestsTool($this->search, RecapWindow::fromArray(['start' => $window['from'], 'end' => $window['to']]), $log);
        }

        $output = $this->run(fn (): array => RecapOutlineAgent::for($input)->withTools($tools)->run());

        return RecapPrompts::normalizeOutline($output, $log->all());
    }

    public function draft(array $input): array
    {
        $log    = new RecapSourceLog;
        $output = $this->run(fn (): array => RecapDraftAgent::for($input)->withTools([new FetchRecapSourceTool($this->reader, $log)])->run());

        return RecapPrompts::normalizeDraft($output, $log->all());
    }

    /**
     * Run an agent, mapping the AI package's errors to a {@see RecapException}.
     *
     * @param  callable(): array<string, mixed>  $run
     *
     * @return array<string, mixed>
     */
    private function run(callable $run): array
    {
        try {
            return $run();
        } catch (FeatureDisabledException) {
            throw new RecapException(__('"Weekly recap posts" is switched off under AI Studio → Features.'));
        } catch (MissingCredentialsException) {
            throw new RecapException(__('No AI provider is set up. Add one under AI Studio → Settings.'));
        } catch (BudgetExceededException) {
            throw new RecapException(__('This month\'s AI budget is spent. Raise it under AI Studio → Settings, or wait for next month.'));
        } catch (RecapException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('ArtisanPack UI recap: the AI run failed.', ['exception' => $exception->getMessage()]);

            throw new RecapException(__('The AI request failed: :message', ['message' => mb_substr($exception->getMessage(), 0, 300)]), 0, $exception);
        }
    }
}
