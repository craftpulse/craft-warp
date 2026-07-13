<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * HTTP tests for the in-section SettingsController: it keeps settings inside the
 * Warp CP section, redirects the global entry there, gates on an admin, fails
 * closed when `allowAdminChanges` is off, and merges a partial post over the
 * current settings so untouched keys are preserved.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;

function settingsAdmin(): User
{
    $admin = User::find()->admin()->one();

    if ($admin === null) {
        throw new RuntimeException('The playground install has no admin user.');
    }

    return $admin;
}

// The render test exercises the full CP layout, which builds every installed
// plugin's nav item; skip it if a plugin known to crash the sidebar is on.
$cortexNavIsBroken = fn(): bool => Craft::$app->getPlugins()->isPluginEnabled('cortex');

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('renders the settings screen inside the Warp section', function() {
    $this->actingAs(settingsAdmin())
        ->get(UrlHelper::cpUrl('warp/settings'))
        ->assertOk()
        ->assertSee('Login methods');
})->skip($cortexNavIsBroken, 'a CP plugin crashes getCpNavItem() in the sidebar');

it('redirects the global plugin-settings entry into the Warp section', function() {
    $response = $this->actingAs(settingsAdmin())
        ->get(UrlHelper::cpUrl('settings/plugins/warp'));

    expect($response->getStatusCode())->toBe(302)
        ->and((string)$response->getHeaders()->get('location'))->toContain('warp/settings');
});

it('forbids a non-admin from the settings screen', function() {
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "notadmin-{$unique}@warp-test.example";
    $user->email = $user->username;
    $user->active = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save test user.');
    }

    $this->actingAs($user)->get(UrlHelper::cpUrl('warp/settings'));
})->throws(yii\web\ForbiddenHttpException::class);

it('renders read-only when allowAdminChanges is off', function() {
    Craft::$app->getConfig()->getGeneral()->allowAdminChanges = false;

    $this->actingAs(settingsAdmin())
        ->get(UrlHelper::cpUrl('warp/settings'))
        ->assertOk()
        ->assertSee('Login methods');
})->skip($cortexNavIsBroken, 'a CP plugin crashes getCpNavItem() in the sidebar');

it('fails closed on save when allowAdminChanges is off', function() {
    Craft::$app->getConfig()->getGeneral()->allowAdminChanges = false;

    $this->actingAs(settingsAdmin())
        ->post(UrlHelper::cpUrl('actions/warp/settings/save'), [
            'settings' => ['tokenTtl' => 1234],
        ]);
})->throws(yii\web\ForbiddenHttpException::class);

it('preserves untouched settings when a partial form is posted', function() {
    $plugin = Warp::getInstance();
    $originalTtl = $plugin->getSettings()->tokenTtl;
    $originalDigits = $plugin->getSettings()->otpDigits;
    $newTtl = $originalTtl === 1200 ? 1201 : 1200;

    try {
        $response = $this->actingAs(settingsAdmin())
            ->post(UrlHelper::cpUrl('actions/warp/settings/save'), [
                'settings' => ['tokenTtl' => $newTtl],
            ]);

        expect($response->getStatusCode())->toBe(302)
            ->and($plugin->getSettings()->tokenTtl)->toBe($newTtl)
            ->and($plugin->getSettings()->otpDigits)->toBe($originalDigits);
    } finally {
        Craft::$app->getPlugins()->savePluginSettings(
            $plugin,
            array_merge($plugin->getSettings()->getAttributes(), ['tokenTtl' => $originalTtl]),
        );
    }
});

it('folds the two login-method switches into the loginMethods array', function() {
    $plugin = Warp::getInstance();
    $original = $plugin->getSettings()->loginMethods;

    try {
        $response = $this->actingAs(settingsAdmin())
            ->post(UrlHelper::cpUrl('actions/warp/settings/save'), [
                'settings' => [
                    'loginMethodMagicLink' => '1',
                    'loginMethodOtp' => '',
                ],
            ]);

        expect($response->getStatusCode())->toBe(302)
            ->and($plugin->getSettings()->loginMethods)->toBe([Settings::CHANNEL_MAGIC_LINK]);
    } finally {
        Craft::$app->getPlugins()->savePluginSettings(
            $plugin,
            array_merge($plugin->getSettings()->getAttributes(), ['loginMethods' => $original]),
        );
    }
});

it('re-renders with an error when every login method is switched off', function() {
    $plugin = Warp::getInstance();
    $original = $plugin->getSettings()->loginMethods;

    try {
        // Full-page forms post to the page route with the action as a body param,
        // so a failed save (null return) re-renders the edit screen in place.
        $response = $this->actingAs(settingsAdmin())
            ->post(UrlHelper::cpUrl('warp/settings'), [
                'action' => 'warp/settings/save',
                'settings' => [
                    'loginMethodMagicLink' => '',
                    'loginMethodOtp' => '',
                ],
            ]);

        expect($response->getStatusCode())->toBe(200)
            ->and($plugin->getSettings()->getErrors('loginMethods'))->not->toBeEmpty();
    } finally {
        Craft::$app->getPlugins()->savePluginSettings(
            $plugin,
            array_merge($plugin->getSettings()->getAttributes(), ['loginMethods' => $original]),
        );
    }
});
