<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * HTTP tests for the front-end NudgeController: the dismissal is POST-only,
 * closed to guests, clears the session flag for good, and answers either JSON or
 * a redirect back so the nudge can be dismissed with no JavaScript. Crucially it
 * carries no recent-auth gate — declining a suggestion must never demand a
 * re-authentication.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use craftpulse\warp\services\Passwordless;

function dismissUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "nud-{$unique}@warp-test.example";
    $user->email = "nud-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save nudge controller test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function flagNudge(): void
{
    Craft::$app->getSession()->set(Passwordless::SESSION_PASSKEY_NUDGE_KEY, true);
}

function nudgeFlagged(): bool
{
    return (bool)Craft::$app->getSession()->get(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
}

beforeEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

// =============================================================================
// authorization boundary
// =============================================================================

it('rejects a guest dismissing the nudge', function() {
    $this->postJson('/warp/nudge/dismiss');
})->throws(yii\web\ForbiddenHttpException::class);

it('rejects a dismissal that is not a POST', function() {
    $this->actingAs(dismissUser())->get('/warp/nudge/dismiss');
})->throws(yii\web\MethodNotAllowedHttpException::class);

// =============================================================================
// dismissal
// =============================================================================

it('clears the nudge flag for a member who answers not now', function() {
    $user = dismissUser();
    $this->actingAs($user);
    flagNudge();

    $response = $this->postJson('/warp/nudge/dismiss');

    expect($response->getStatusCode())->toBe(200)
        ->and(nudgeFlagged())->toBeFalse();
});

it('dismisses in form mode by redirecting back, with no flash to dismiss in turn', function() {
    $user = dismissUser();
    $this->actingAs($user);
    flagNudge();

    $response = $this->post('/warp/nudge/dismiss');

    expect($response->getStatusCode())->toBe(302)
        ->and(nudgeFlagged())->toBeFalse()
        ->and(Craft::$app->getSession()->hasFlash('notice'))->toBeFalse();
});

it('accepts a dismissal with no nudge flagged, without throwing', function() {
    $user = dismissUser();
    $this->actingAs($user);

    $response = $this->postJson('/warp/nudge/dismiss');

    expect($response->getStatusCode())->toBe(200)
        ->and(nudgeFlagged())->toBeFalse();
});

it('does not gate the dismissal on recent auth', function() {
    // PasskeysController's credential actions demand a fresh sign-in. Declining a
    // suggestion is not a credential change, so a member whose session is old
    // must still be able to dismiss it.
    $user = dismissUser();
    $this->actingAs($user);
    flagNudge();

    $response = $this->postJson('/warp/nudge/dismiss');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getJsonContent()['success'])->toBeTrue();
});
