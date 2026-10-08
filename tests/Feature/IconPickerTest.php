<?php

declare(strict_types=1);

use ArtisanPackUI\Icons\Registries\IconSetRegistration;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Support\IconPickerField;
use ArtisanPackUI\Site\Support\IconRef;
use ArtisanPackUI\Site\Support\PackageFields;
use ArtisanPackUI\Site\Support\Permissions;
use ArtisanPackUI\VisualEditor\Blocks\Icon\IconBlock;
use ArtisanPackUI\VisualEditor\Registries\BlockBindingSourceRegistry;
use ArtisanPackUI\VisualEditor\Services\Bindings\BindingContext;
use ArtisanPackUI\VisualEditor\Services\Bindings\BindingResolver;
use ArtisanPackUI\VisualEditor\Services\Bindings\Sources\CustomFieldSource;
use ArtisanPackUI\VisualEditor\Services\Icon\IconSvgResolver;
use ArtisanPackUI\VisualEditor\Services\Icon\SvgSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * The icon picker field (roadmap 1.2): its catalog lists every registered
 * icon set, and the `{set, name}` it stores renders through the
 * `artisanpack/icon` block when bound with the `custom_field` source.
 */

beforeEach(function (): void {
    $this->iconSet = useTemporaryIconSet();
    $this->iconSet->write('puzzle', testSvg('M5 5h5'));

    // Stand-in for another `ap.icons.registerIconSets` set, e.g. an
    // artisanpack-ui/icons default or Font Awesome.
    $this->otherSet = sys_get_temp_dir() . '/artisanpack-ui-other-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->otherSet);
    File::put($this->otherSet . '/cube.svg', testSvg('M7 7h7'));
    File::put($this->otherSet . '/puzzle-piece.svg', testSvg());
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->otherSet));

    addFilter('ap.icons.registerIconSets', fn (IconSetRegistration $registry): IconSetRegistration => $registry->addSet($this->otherSet, 'fas'));
});

it('lists every registered icon set with its icons', function (): void {
    actingAsUserWith([Permissions::SYNC]);

    $this->getJson('/admin/artisanpack-ui/icons')
        ->assertOk()
        ->assertJsonPath('sets', [
            ['prefix' => 'apui', 'label' => 'ArtisanPack UI', 'count' => 1],
            ['prefix' => 'fas', 'label' => 'Font Awesome Solid', 'count' => 2],
        ])
        ->assertJsonPath('total', 3)
        ->assertJsonPath('icons.0.set', 'apui')
        ->assertJsonPath('icons.0.svg', fn (string $svg): bool => str_contains($svg, 'M5 5h5'));
});

it('searches icon names, optionally within one set', function (): void {
    actingAsUserWith([Permissions::STATS_VIEW]);

    $this->getJson('/admin/artisanpack-ui/icons?q=puzzle')
        ->assertOk()
        ->assertJsonPath('total', 2);

    $this->getJson('/admin/artisanpack-ui/icons?q=puzzle&set=fas')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('icons.0.name', 'puzzle-piece');
});

it('ranks an exact name match first', function (): void {
    actingAsUserWith([Permissions::SYNC]);
    File::put($this->otherSet . '/a-puzzle.svg', testSvg());

    $this->getJson('/admin/artisanpack-ui/icons?q=puzzle&set=fas')
        ->assertOk()
        ->assertJsonPath('icons.*.name', ['puzzle-piece', 'a-puzzle']);

    $this->getJson('/admin/artisanpack-ui/icons?q=puzzle')
        ->assertJsonPath('icons.0.name', 'puzzle');
});

it('refuses the icon catalog without a plugin permission', function (): void {
    actingAsUserWith();

    $this->getJson('/admin/artisanpack-ui/icons')->assertForbidden();
});

it('makes the package icon field an icon picker', function (): void {
    $icon = collect(PackageFields::definitions())->firstWhere('key', 'icon');

    expect($icon['type'])->toBe(IconPickerField::TYPE)
        ->and($icon['column_type'])->toBe('text')
        ->and(IconPickerField::definition()['editor_component'])->toBe('artisanpack-ui.IconPickerField')
        ->and(PackageFields::retyped(['icon' => 'text', 'version' => 'text']))->toBe(['icon' => IconPickerField::TYPE])
        ->and(PackageFields::retyped(['icon' => IconPickerField::TYPE]))->toBe([]);
});

it('reads an iconRef from the stored JSON', function (mixed $value, ?array $expected): void {
    expect(IconRef::normalize($value))->toBe($expected);
})->with([
    'json'        => ['{"set":"apui","name":"puzzle"}', ['set' => 'apui', 'name' => 'puzzle']],
    'array'       => [['set' => ' fas ', 'name' => 'cube'], ['set' => 'fas', 'name' => 'cube']],
    'bad json'    => ['{set', null],
    'missing set' => [['name' => 'cube'], null],
    'traversal'   => [['set' => 'fas', 'name' => '../x'], null],
    'empty'       => [null, null],
]);

it('renders the package icon through an icon block bound to the icon field', function (string $set, string $name, string $path): void {
    // Saved the way Keystone's edit screen saves it: JSON text in the column.
    $id      = DB::table('packages')->insertGetId(['title' => 'Accessibility', 'icon' => json_encode(['set' => $set, 'name' => $name])]);
    $package = Package::query()->findOrFail($id);

    $sources = new BlockBindingSourceRegistry;
    $sources->register(new CustomFieldSource);

    // The single-package template's icon block, with `iconRef` bound to the field.
    [$block] = (new BindingResolver($sources))->resolve([[
        'name'       => 'artisanpack/icon',
        'attributes' => [
            'iconRef'  => ['set' => 'fas', 'name' => 'placeholder'],
            'size'     => 32,
            'bindings' => ['iconRef' => ['source' => 'custom_field', 'args' => ['key' => 'icon']]],
        ],
    ]], new BindingContext($package));

    $html = (new IconBlock(new SvgSanitizer, app(IconSvgResolver::class)))->render($block['attributes']);

    expect($block['attributes']['iconRef'])->toBe(['set' => $set, 'name' => $name])
        ->and($html)->toContain('wp-block-artisanpack-icon__ref')
        ->toContain('data-icon-set="' . $set . '"')
        ->toContain($path);
})->with([
    'custom apui icon' => ['apui', 'puzzle', 'M5 5h5'],
    'shared set icon'  => ['fas', 'cube', 'M7 7h7'],
]);
