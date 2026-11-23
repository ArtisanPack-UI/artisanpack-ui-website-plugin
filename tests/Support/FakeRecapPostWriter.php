<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Tests\Support;

use ArtisanPackUI\Site\Services\Recap\RecapPostData;
use ArtisanPackUI\Site\Services\Recap\RecapPostWriter;

/**
 * A {@see RecapPostWriter} for the suite: records the posts it was asked to
 * create and hands back sequential ids.
 */
final class FakeRecapPostWriter implements RecapPostWriter
{
    /** @var list<RecapPostData> */
    public array $created = [];

    public function create(RecapPostData $data): array
    {
        $this->created[] = $data;
        $id              = count($this->created);

        return ['id' => $id, 'editUrl' => 'https://example.test/admin/posts/' . $id . '/edit'];
    }
}
