<?php

declare(strict_types=1);

use ArtisanPackUI\Icons\Registries\IconSetRegistration;
use ArtisanPackUI\Site\Exceptions\InvalidIconException;
use ArtisanPackUI\Site\Support\PackageIconSet;
use ArtisanPackUI\VisualEditor\Services\Icon\IconSvgResolver;

/**
 * The `apui` set (roadmap 1.3): registered through
 * `ap.icons.registerIconSets`, resolved by the visual editor's
 * IconSvgResolver, and only ever holding sanitized SVG.
 */

it('registers the apui set so the visual editor resolves its icons', function (): void {
    $iconSet = useTemporaryIconSet();
    $iconSet->write('puzzle', testSvg('M1 1h2'));

    $sets = applyFilters('ap.icons.registerIconSets', new IconSetRegistration)->getSets();

    expect($sets[PackageIconSet::PREFIX]['path'])->toBe($iconSet->directory())
        ->and(app(IconSvgResolver::class)->resolve('apui', 'puzzle'))->toContain('M1 1h2')
        ->and(app(IconSvgResolver::class)->resolve('apui', 'missing'))->toBeNull();
});

it('creates the set directory when it registers', function (): void {
    $iconSet = useTemporaryIconSet();

    applyFilters('ap.icons.registerIconSets', new IconSetRegistration);

    expect(is_dir($iconSet->directory()))->toBeTrue();
});

it('stores only the sanitized markup', function (): void {
    $iconSet = useTemporaryIconSet();

    $iconSet->write('alert', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><path d="M2 2h2"/></svg>');

    $stored = (string) file_get_contents($iconSet->directory() . '/alert.svg');

    expect($stored)->toContain('M2 2h2')
        ->not->toContain('script')
        ->not->toContain('onload');
});

it('rejects markup that does not survive sanitization', function (string $markup): void {
    $iconSet = useTemporaryIconSet();

    expect(fn () => $iconSet->write('bad', $markup))->toThrow(InvalidIconException::class)
        ->and(file_exists($iconSet->directory() . '/bad.svg'))->toBeFalse();
})->with([
    'not svg'     => ['<html><body>hi</body></html>'],
    'unparseable' => ['<svg><path'],
    'empty'       => [''],
]);

it('refuses DOCTYPE and entity declarations', function (string $payload): void {
    $iconSet = useTemporaryIconSet();

    expect(fn () => $iconSet->write('declared', $payload))->toThrow(InvalidIconException::class)
        ->and(file_exists($iconSet->directory() . '/declared.svg'))->toBeFalse();
})->with([
    'doctype' => ['<!DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>'],
    'entity'  => ['<!ENTITY x "y"><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>'],
]);

it('rejects icon names that could escape the set', function (string $name): void {
    $iconSet = useTemporaryIconSet();

    expect(fn () => $iconSet->write($name, testSvg()))->toThrow(InvalidIconException::class);
})->with(['../escape', 'a..b', 'sub/dir', '', 'too-long' => str_repeat('a', 101)]);
