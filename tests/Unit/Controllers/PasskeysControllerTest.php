<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * HTTP tests for the front-end PasskeysController. The focus is the
 * authorization boundary Warp owns — guests are rejected, the recent-auth gate
 * fails closed with a `reauthRequired` envelope, and only a logged-in,
 * recently-authenticated user reaches the WebAuthn machinery. The ceremonies
 * themselves are core's, wrapped by Auth Kit.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use craftpulse\authkit\services\Passkeys;

function passkeyUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "pkc-{$unique}@warp-test.example";
    $user->email = "pkc-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save passkey controller test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function stampRecentAuth(): void
{
    Craft::$app->getSession()->set(Passkeys::SESSION_RECENT_AUTH_KEY, time());
}

beforeEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passkeys::SESSION_RECENT_AUTH_KEY);
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passkeys::SESSION_RECENT_AUTH_KEY);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

// =============================================================================
// authorization boundary
// =============================================================================

it('rejects a guest requesting creation options', function() {
    $this->postJson('/warp/passkeys/creation-options');
})->throws(yii\web\ForbiddenHttpException::class);

it('fails closed with reauthRequired when a logged-in user has not authenticated recently', function() {
    $user = passkeyUser();

    $response = $this->actingAs($user)->postJson('/warp/passkeys/creation-options');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getJsonContent()['reauthRequired'])->toBeTrue();
});

it('fails closed with reauthRequired on verify-creation when auth is stale', function() {
    $user = passkeyUser();

    $response = $this->actingAs($user)->postJson('/warp/passkeys/verify-creation', [
        'credentials' => '{}',
    ]);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getJsonContent()['reauthRequired'])->toBeTrue();
});

it('fails closed with reauthRequired on delete when auth is stale', function() {
    $user = passkeyUser();

    $response = $this->actingAs($user)->postJson('/warp/passkeys/delete', ['uid' => StringHelper::UUID()]);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getJsonContent()['reauthRequired'])->toBeTrue();
});

// =============================================================================
// method + param requirements
// =============================================================================

it('rejects a creation-options request that is not a POST', function() {
    $user = passkeyUser();
    $this->actingAs($user);
    stampRecentAuth();

    $this->get('/warp/passkeys/creation-options');
})->throws(yii\web\MethodNotAllowedHttpException::class);

it('requires a uid to delete a passkey', function() {
    $user = passkeyUser();
    $this->actingAs($user);
    stampRecentAuth();

    $this->postJson('/warp/passkeys/delete');
})->throws(yii\web\BadRequestHttpException::class);

// =============================================================================
// happy paths — recent auth fresh, past the gate into the WebAuthn machinery
// =============================================================================

it('returns creation options for a recently-authenticated user', function() {
    $user = passkeyUser();
    $this->actingAs($user);
    stampRecentAuth();

    $response = $this->postJson('/warp/passkeys/creation-options');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getJsonContent()['options'])->toBeString();
});

it('deletes a passkey for a recently-authenticated user', function() {
    $user = passkeyUser();
    $this->actingAs($user);
    stampRecentAuth();

    $response = $this->postJson('/warp/passkeys/delete', ['uid' => StringHelper::UUID()]);

    expect($response->getStatusCode())->toBe(200);
});
