<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\CMSFramework\Modules\Blog\Managers\BlogManager;
use ArtisanPackUI\CMSFramework\Modules\Blog\Models\Post;
use ArtisanPackUI\CMSFramework\Modules\Blog\Models\PostCategory;
use ArtisanPackUI\CMSFramework\Modules\Blog\Models\PostTag;
use ArtisanPackUI\Site\Exceptions\RecapException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\ContentModel\Support\PostTaxonomies;
use Modules\Seo\Support\SeoMetaSupport;
use Throwable;

/**
 * The {@see RecapPostWriter} backed by the host's blog: creates the draft
 * through cms-framework's {@see BlogManager} (slug allocation, lifecycle
 * hooks), the same path Keystone's own New Post form takes, then attaches
 * the category, tags, Packages terms and SEO meta. Everything happens in
 * one transaction, so a failure leaves no partial post.
 *
 * The post stays an unscheduled draft with no publish date: a draft with a
 * `published_at` reads as already published in Keystone's editor (its
 * button says "Update" rather than offering to schedule), so the
 * maintainer sets the date when scheduling it.
 *
 * Bound only where cms-framework's blog is installed. The Packages terms go
 * through Keystone's `PostTaxonomies` and the SEO meta through its
 * `SeoMetaSupport` where those modules are present.
 *
 * @since 1.6.0
 */
final class KeystoneRecapPostWriter implements RecapPostWriter
{
    public function __construct(private readonly BlogManager $blog) {}

    public function create(RecapPostData $data): array
    {
        try {
            $post = DB::transaction(function () use ($data): Post {
                $post = $this->blog->create([
                    'title'             => $data->title,
                    'slug'              => '',
                    'status'            => 'draft',
                    'excerpt'           => '' === $data->excerpt ? null : $data->excerpt,
                    'featured_image_id' => $data->featuredImageId,
                ], null, $data->authorId);

                $post->setBlockContent($data->blocks);
                $post->save();

                $category = PostCategory::query()->firstOrCreate(['slug' => $data->categorySlug], ['name' => $data->categoryName]);
                $this->blog->syncCategories($post, [(int) $category->getKey()]);
                $this->blog->syncTags($post, [...$data->tagIds, ...$this->createTags($data->newTagNames)]);

                $this->syncPackageTerms((int) $post->getKey(), $data->termIds);
                $this->saveSeo($post, $data);

                return $post;
            });
        } catch (Throwable $exception) {
            Log::warning('ArtisanPack UI recap: the draft post could not be created.', ['exception' => $exception->getMessage()]);

            throw new RecapException(__('The draft post couldn\'t be created: :message', ['message' => mb_substr($exception->getMessage(), 0, 300)]), 0, $exception);
        }

        // Relative, so the modal's Inertia visit stays on the page's own
        // origin even when APP_URL's scheme or host differs from it.
        return ['id' => (int) $post->getKey(), 'editUrl' => route('admin.posts.edit', $post, false)];
    }

    /**
     * Create the new tags, reusing any that now exist by slug.
     *
     * @param  list<string>  $names
     *
     * @return list<int>
     */
    private function createTags(array $names): array
    {
        return array_map(
            static fn (string $name): int => (int) PostTag::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->getKey(),
            $names,
        );
    }

    /**
     * @param  list<int>  $termIds
     */
    private function syncPackageTerms(int $postId, array $termIds): void
    {
        if ([] === $termIds || ! class_exists(PostTaxonomies::class)) {
            return;
        }

        app(PostTaxonomies::class)->sync($postId, $termIds);
    }

    private function saveSeo(Post $post, RecapPostData $data): void
    {
        if (! class_exists(SeoMetaSupport::class)) {
            return;
        }

        SeoMetaSupport::save($post, [
            'focus_keyword'    => $data->focusKeyword,
            'meta_description' => $data->metaDescription,
        ]);
    }
}
