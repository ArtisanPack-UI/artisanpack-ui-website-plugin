<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Http\Controllers\Concerns\RespondsToGitHubErrors;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Board\ProjectBoardReader;
use ArtisanPackUI\Site\Services\Board\ProjectBoardWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The kanban boards over the org GitHub Project (roadmap 5.2 and 5.4): the
 * global board of every package, one package's board on its Edit Package
 * Issues tab, and moving a card between Status columns.
 *
 * Reads reuse a board read in the last {@see ProjectBoardReader::BOARD_TTL}
 * seconds unless the request asks for `?fresh=1` (the Refresh button).
 *
 * @since 0.5.0
 */
final class BoardController
{
    use RespondsToGitHubErrors;

    /** A project item's GraphQL node id. */
    public const ITEM_ID_PATTERN = '[A-Za-z0-9_=-]{1,100}';

    public function __construct(private readonly ProjectBoardReader $reader) {}

    public function index(Request $request): JsonResponse
    {
        return self::attempt(fn (): JsonResponse => response()->json($this->reader->read($request->boolean('fresh'))->toArray()));
    }

    public function package(Request $request, Package $package): JsonResponse
    {
        $repo = trim((string) $package->github_repo);

        if ('' === $repo) {
            return response()->json([
                'message' => __('This package has no GitHub repo. Add one in its "GitHub repo" field to see its issues.'),
            ], 422);
        }

        return self::attempt(fn (): JsonResponse => response()->json($this->reader->read($request->boolean('fresh'))->forRepository($repo)->toArray()));
    }

    /**
     * Move a card: set its Status to `status`, or clear it when null (the
     * "No status" column).
     */
    public function move(Request $request, ProjectBoardWriter $writer, string $item): JsonResponse
    {
        $status = $request->validate([
            'status' => ['present', 'nullable', 'string', 'max:100'],
        ])['status'];

        return self::attempt(function () use ($writer, $item, $status): JsonResponse {
            $writer->moveItem($item, $status);

            return response()->json(['id' => $item, 'statusId' => $status]);
        });
    }
}
