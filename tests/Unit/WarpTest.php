<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Smoke tests for the plugin shell: the plugin installs, resolves, and exposes
 * its settings model.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;

it('registers the plugin and resolves the instance', function() {
    $plugin = Warp::getInstance();

    expect($plugin)->toBeInstanceOf(Warp::class)
        ->and($plugin->handle)->toBe('warp')
        ->and($plugin->hasCpSection)->toBeTrue();
});

it('exposes its own control-panel settings surface', function() {
    $plugin = Warp::getInstance();

    expect($plugin->hasCpSettings)->toBeTrue()
        ->and($plugin->getSettings())->toBeInstanceOf(Settings::class);
});

it('hides the CP nav item from a user with no reachable Warp screen', function() {
    $user = activeAuthUser();
    $this->actingAs($user);

    expect(Warp::getInstance()->getCpNavItem())->toBeNull();
});

it('shows the CP nav item with both subnav entries to an admin', function() {
    $admin = User::find()->admin()->one();
    $this->actingAs($admin);

    $item = Warp::getInstance()->getCpNavItem();

    expect($item)->not->toBeNull()
        ->and($item['subnav'])->toHaveKeys(['overview', 'settings']);
});
