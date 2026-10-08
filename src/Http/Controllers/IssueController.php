<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Http\Controllers\Concerns\RespondsToGitHubErrors;
use ArtisanPackUI\Site\Http\Requests\UpdateIssueRequest;
use ArtisanPackUI\Site\Services\GitHub\GitHubIssues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The board's issue modal (roadmap 5.3): view an issue and its comments,
 * edit it, comment on it, and the labels, milestones and assignees to edit
 * it with. `{repo}` is a repo name in the configured org.
 *
 * Edits and comments are made by the GitHub App, so GitHub shows them as
 * the App's bot rather than the admin. The modal says so.
 *
 * @since 1.0.0
 */
final class IssueController
{
    use RespondsToGitHubErrors;

    public function __construct(private readonly GitHubIssues $issues) {}

    public function show(string $repo, int $number): JsonResponse
    {
        return self::attempt(fn (): JsonResponse => response()->json($this->issues->show($repo, $number)));
    }

    public function update(UpdateIssueRequest $request, string $repo, int $number): JsonResponse
    {
        $changes = $request->changes();

        if ([] === $changes) {
            return response()->json(['message' => __('Nothing to change.')], 422);
        }

        return self::attempt(fn (): JsonResponse => response()->json($this->issues->update($repo, $number, $changes)));
    }

    public function comment(Request $request, string $repo, int $number): JsonResponse
    {
        $body = $request->validate([
            'body' => ['required', 'string', 'max:' . UpdateIssueRequest::MAX_BODY],
        ])['body'];

        return self::attempt(fn (): JsonResponse => response()->json($this->issues->comment($repo, $number, $body), 201));
    }

    public function options(string $repo): JsonResponse
    {
        return self::attempt(fn (): JsonResponse => response()->json($this->issues->options($repo)));
    }
}
