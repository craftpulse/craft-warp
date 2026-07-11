<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * HTTP tests for the control-panel OverviewController: the screen is gated on
 * the `warp:viewOverview` permission — a user without it is rejected, an admin
 * and a permission-holding user both render the summary.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craftpulse\warp\controllers\OverviewController;

function overviewUser(array $permissions = []): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "ov-{$unique}@warp-test.example";
    $user->email = $user->username;
    $user->active = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save overview test user.');
    }

    if ($permissions !== []) {
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, $permissions);
    }

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function overviewAdmin(): User
{
    $admin = User::find()->admin()->one();

    if ($admin === null) {
        throw new RuntimeException('The playground install has no admin user.');
    }

    return $admin;
}

// The render tests exercise the full CP layout, which builds every installed
// plugin's nav item; skip them if a plugin known to crash the sidebar is on.
$cortexNavIsBroken = fn(): bool => Craft::$app->getPlugins()->isPluginEnabled('cortex');

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('renders the overview for an admin', function() {
    $this->actingAs(overviewAdmin())
        ->get(UrlHelper::cpUrl('warp'))
        ->assertOk()
        ->assertSee('Recent passwordless sign-ins');
})->skip($cortexNavIsBroken, 'a CP plugin crashes getCpNavItem() in the sidebar');

it('renders the overview for a user granted the permission', function() {
    $user = overviewUser(['accessCp', 'accessPlugin-warp', OverviewController::PERMISSION_VIEW_OVERVIEW]);

    $this->actingAs($user)
        ->get(UrlHelper::cpUrl('warp'))
        ->assertOk()
        ->assertSee('Recent passwordless sign-ins');
})->skip($cortexNavIsBroken, 'a CP plugin crashes getCpNavItem() in the sidebar');

it('rejects a user without the overview permission', function() {
    $user = overviewUser(['accessCp', 'accessPlugin-warp']);

    $this->actingAs($user)->get(UrlHelper::cpUrl('warp'));
})->throws(yii\web\ForbiddenHttpException::class);
