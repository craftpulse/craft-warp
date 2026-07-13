<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Tests for the example-templates install command: the bundle lands under the
 * chosen folder, a rename rewrites the internal members/ references, an
 * existing folder is refused without --overwrite, and a bad folder name is
 * rejected.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\FileHelper;
use craftpulse\warp\console\controllers\ExampleTemplatesController;
use yii\console\ExitCode;

function exampleTemplatesController(): ExampleTemplatesController
{
    $controller = new ExampleTemplatesController('example-templates', Craft::$app);
    $controller->interactive = false;

    return $controller;
}

function exampleTemplatesTarget(string $folder): string
{
    return Craft::$app->getPath()->getSiteTemplatesPath() . DIRECTORY_SEPARATOR . $folder;
}

afterEach(function() {
    foreach (['warp-cmd-test', 'warp-cmd-renamed'] as $folder) {
        $path = exampleTemplatesTarget($folder);

        if (is_dir($path)) {
            FileHelper::removeDirectory($path);
        }
    }
});

it('installs the bundle under the chosen folder name', function() {
    $controller = exampleTemplatesController();
    $controller->folderName = 'warp-cmd-test';

    expect($controller->runAction('install'))->toBe(ExitCode::OK)
        ->and(is_file(exampleTemplatesTarget('warp-cmd-test') . '/login.twig'))->toBeTrue()
        ->and(is_file(exampleTemplatesTarget('warp-cmd-test') . '/_private/layouts/index.twig'))->toBeTrue();
});

it('rewrites the internal members references for a renamed folder', function() {
    $controller = exampleTemplatesController();
    $controller->folderName = 'warp-cmd-renamed';

    expect($controller->runAction('install'))->toBe(ExitCode::OK);

    $login = (string)file_get_contents(exampleTemplatesTarget('warp-cmd-renamed') . '/login.twig');

    expect($login)->toContain("{% extends 'warp-cmd-renamed/_private/layouts' %}")
        ->and($login)->not->toContain("'members/");
});

it('refuses an existing folder without the overwrite flag', function() {
    FileHelper::createDirectory(exampleTemplatesTarget('warp-cmd-test'));

    $controller = exampleTemplatesController();
    $controller->folderName = 'warp-cmd-test';

    expect($controller->runAction('install'))->toBe(ExitCode::UNSPECIFIED_ERROR);
});

it('overwrites an existing folder when asked to', function() {
    FileHelper::createDirectory(exampleTemplatesTarget('warp-cmd-test'));

    $controller = exampleTemplatesController();
    $controller->folderName = 'warp-cmd-test';
    $controller->overwrite = true;

    expect($controller->runAction('install'))->toBe(ExitCode::OK)
        ->and(is_file(exampleTemplatesTarget('warp-cmd-test') . '/login.twig'))->toBeTrue();
});

it('rejects a folder name with unsafe characters', function() {
    $controller = exampleTemplatesController();
    $controller->folderName = '../outside';

    expect($controller->runAction('install'))->toBe(ExitCode::UNSPECIFIED_ERROR);
});
