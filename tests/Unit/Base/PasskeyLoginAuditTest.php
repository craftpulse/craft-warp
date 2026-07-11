<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Tests the passkey-login audit emission. A passkey login runs through core's
 * own `users/login-with-passkey` endpoint, so it never reaches
 * Passwordless::loginUser(); the EVENT_AFTER_LOGIN listener in PluginTrait
 * records it — both to the login log and, since 1.2.0, as a login.passkey audit
 * event — guarded on the request route so Warp's own email flows are not
 * double-recorded.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use craft\web\User as WebUser;
use craftpulse\authkit\audit\AuthEvent;
use craftpulse\authkit\AuthKit;
use craftpulse\warp\tests\Support\CollectingAuditSink;
use yii\web\UserEvent;

function passkeyLoginUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "pkl-{$unique}@warp-test.example";
    $user->email = "pkl-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save passkey-login test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

/**
 * Fires WebUser::EVENT_AFTER_LOGIN under a given request route, restoring the
 * original route afterwards, so the route-guarded listener can be exercised.
 */
function fireAfterLogin(User $user, string $route): void
{
    $original = Craft::$app->requestedRoute;
    Craft::$app->requestedRoute = $route;

    try {
        Craft::$app->getUser()->trigger(WebUser::EVENT_AFTER_LOGIN, new UserEvent(['identity' => $user]));
    } finally {
        Craft::$app->requestedRoute = $original;
    }
}

afterEach(function() {
    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    AuthKit::$plugin->getAudit()->setSinks([]);
});

it('records a login.passkey audit event on a passkey-route login', function() {
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);
    $user = passkeyLoginUser();

    fireAfterLogin($user, 'users/login-with-passkey');

    $event = $sink->firstOfName(AuthEvent::LOGIN_PASSKEY);

    expect($event)->not->toBeNull()
        ->and($event->emitter)->toBe('warp')
        ->and($event->outcome)->toBe(AuthEvent::OUTCOME_SUCCESS)
        ->and($event->userId)->toBe((int)$user->id);
});

it('records no login.passkey audit event on a non-passkey login route', function() {
    // Warp's own email flows fire the same event but resolve to a warp/auth/*
    // route; the listener's guard keeps them from double-recording as passkey.
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);

    fireAfterLogin(passkeyLoginUser(), 'warp/auth/verify-link');

    expect($sink->ofName(AuthEvent::LOGIN_PASSKEY))->toBe([]);
});
