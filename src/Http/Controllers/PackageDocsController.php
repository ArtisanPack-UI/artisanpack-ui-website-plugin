<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Exceptions\DocsSiteException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Docs\DocsDocumentation;
use ArtisanPackUI\Site\Services\Docs\DocsImportStatus;
use ArtisanPackUI\Site\Services\Docs\DocsSiteClient;
use ArtisanPackUI\Site\Services\Docs\QueuedImport;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Edit Package Docs tab (roadmap 3.1–3.2), which manages the
 * package's docs on the docs site without logging in there:
 *
 *   - "Import documentation" / "Import changelog" queue the docs site's
 *     imports, and the tab polls {@see self::status()} for the outcome.
 *   - The reorder tree reads {@see self::tree()} and saves a new sibling
 *     order through {@see self::reorder()}. Only order changes: the docs
 *     site derives parents from the repo's folder layout.
 *
 * Every call goes to the docs package the marketing package is linked to
 * (`docs_package_id`). An unlinked package, a docs site that isn't
 * configured, or a docs site error answers 422 with an admin-friendly
 * message; {@see self::status()} reports an unlinked package as
 * `linked: false` instead, so the tab can say what to do.
 *
 * @since 1.0.0
 */
final class PackageDocsController
{
    public function __construct(private readonly DocsSiteClient $docs) {}

    public function status(Package $package): JsonResponse
    {
        if (null === $package->docs_package_id) {
            return response()->json(['linked' => false, 'imports' => null]);
        }

        return self::attempt(function () use ($package): JsonResponse {
            $docsPackage = $this->docs->package($package->docs_package_id);

            return response()->json([
                'linked'  => true,
                'imports' => [
                    'docs'      => self::presentImport($docsPackage->imports['docs']),
                    'changelog' => self::presentImport($docsPackage->imports['changelog']),
                ],
            ]);
        });
    }

    public function tree(Package $package): JsonResponse
    {
        return self::attempt(fn (): JsonResponse => response()->json([
            'tree' => array_map(self::presentNode(...), $this->docs->documentation(self::docsId($package))),
        ]));
    }

    public function importDocs(Package $package): JsonResponse
    {
        return self::attempt(fn (): JsonResponse => self::queued(
            $this->docs->importDocumentation(self::docsId($package)),
            __('Documentation import queued on the docs site.'),
        ));
    }

    public function importChangelog(Package $package): JsonResponse
    {
        return self::attempt(fn (): JsonResponse => self::queued(
            $this->docs->importChangelog(self::docsId($package)),
            __('Changelog import queued on the docs site.'),
        ));
    }

    /**
     * The most pages one reorder may name; far more siblings than any doc
     * section has, but a bound on the work one request can ask for.
     */
    public const MAX_REORDER_IDS = 500;

    /**
     * Save a new order for the children of one parent (0 for the roots).
     * `ids` must be exactly that parent's current children, so a tree that
     * changed on the docs site since the tab loaded is refused rather than
     * half-applied.
     */
    public function reorder(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'parent' => ['required', 'integer', 'min:0'],
            'ids'    => ['required', 'array', 'min:1', 'max:' . self::MAX_REORDER_IDS],
            'ids.*'  => ['required', 'integer', 'distinct'],
        ]);

        $parent = (int) $validated['parent'];
        $ids    = array_map(intval(...), $validated['ids']);

        return self::attempt(function () use ($package, $parent, $ids): JsonResponse {
            $docsId   = self::docsId($package);
            $siblings = self::childrenOf($this->docs->documentation($docsId), $parent);

            if (null === $siblings || self::sorted($siblings) !== self::sorted($ids)) {
                return response()->json([
                    'message' => __('The documentation changed on the docs site. Reload the tab and try again.'),
                ], 409);
            }

            return response()->json([
                'message' => $this->docs->reorderDocumentation($docsId, array_flip($ids)),
            ]);
        });
    }

    /**
     * The ids of `$parent`'s children, or null when no such parent exists.
     *
     * @param  list<DocsDocumentation>  $nodes
     *
     * @return list<int>|null
     */
    private static function childrenOf(array $nodes, int $parent): ?array
    {
        if (0 === $parent) {
            return array_map(static fn (DocsDocumentation $node): int => $node->id, $nodes);
        }

        foreach ($nodes as $node) {
            if ($node->id === $parent) {
                return array_map(static fn (DocsDocumentation $child): int => $child->id, $node->children);
            }

            $found = self::childrenOf($node->children, $parent);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     *
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @throws IntegrationNotConfiguredException When the package isn't linked to the docs site.
     */
    private static function docsId(Package $package): int
    {
        if (null === $package->docs_package_id) {
            throw new IntegrationNotConfiguredException(__('This package isn\'t linked to the docs site yet. Run "Sync now" to link it.'));
        }

        return $package->docs_package_id;
    }

    private static function queued(QueuedImport $import, string $fallback): JsonResponse
    {
        return response()->json([
            'message' => '' !== $import->message ? $import->message : $fallback,
            'queued'  => $import->queued,
        ], 202);
    }

    /**
     * @return array{status: string|null, error: string|null, importedAt: string|null}
     */
    private static function presentImport(DocsImportStatus $import): array
    {
        return [
            'status'     => $import->status,
            'error'      => $import->error,
            'importedAt' => $import->importedAt?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, title: string, slug: string, parent: int, menuOrder: int, children: list<array<string, mixed>>}
     */
    private static function presentNode(DocsDocumentation $node): array
    {
        return [
            'id'        => $node->id,
            'title'     => $node->title,
            'slug'      => $node->slug,
            'parent'    => $node->parent,
            'menuOrder' => $node->menuOrder,
            'children'  => array_map(self::presentNode(...), $node->children),
        ];
    }

    /**
     * @param  Closure(): JsonResponse  $call
     */
    private static function attempt(Closure $call): JsonResponse
    {
        try {
            return $call();
        } catch (IntegrationNotConfiguredException|DocsSiteException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
