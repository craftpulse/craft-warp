<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * HTTP tests for the front-end SessionsController. The focus is the
 * authorization boundary Warp owns — guests are rejected, the recent-auth gate
 * fails closed with a `reauthRequired` envelope, a user can only revoke their
 * own sessions, and the happy paths delete the authoritative core session row.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craftpulse\authkit\services\Passkeys;
use craftpulse\warp\records\Session as SessionRecord;

function revokeUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "rev-{$unique}@warp-test.example";
    $user->email = "rev-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save sessions controller test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function coreSessionFor(int $userId): string
{
    $token = 'tok-' . bin2hex(random_bytes(32));
    Db::insert(CraftTable::SESSIONS, ['userId' => $userId, 'token' => $token]);

    return $token;
}

function registryRowFor(int $userId, string $token): string
{
    $record = new SessionRecord();
    $record->userId = $userId;
    $record->tokenHash = hash('sha256', $token);
    $record->save(false);

    return $record->uid;
}

function coreSessionAlive(string $token): bool
{
    return (new Query())->from(CraftTable::SESSIONS)->where(['token' => $token])->exists();
}

function stampSessionAuth(): void
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

it('rejects a guest revoking a session', function() {
    $this->postJson('/warp/sessions/revoke', ['uid' => StringHelper::UUID()]);
})->throws(yii\web\ForbiddenHttpException::class);

it('rejects a guest signing out other sessions', function() {
    $this->postJson('/warp/sessions/revoke-others');
})->throws(yii\web\ForbiddenHttpException::class);

it('fails closed with reauthRequired when auth is stale on revoke', function() {
    $user = revokeUser();

    $response = $this->actingAs($user)->postJson('/warp/sessions/revoke', ['uid' => StringHelper::UUID()]);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getJsonContent()['reauthRequired'])->toBeTrue();
});

it('fails closed with reauthRequired when auth is stale on revoke-others', function() {
    $user = revokeUser();

    $response = $this->actingAs($user)->postJson('/warp/sessions/revoke-others');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getJsonContent()['reauthRequired'])->toBeTrue();
});

it('requires a uid to revoke a session', function() {
    $user = revokeUser();
    $this->actingAs($user);
    stampSessionAuth();

    $this->postJson('/warp/sessions/revoke');
})->throws(yii\web\BadRequestHttpException::class);

// =============================================================================
// form mode — failures redirect back with a flash, never JSON
// =============================================================================

it('redirects back with a flash on a form-mode revoke failure', function() {
    $user = revokeUser();
    $this->actingAs($user);
    stampSessionAuth();

    // A plain form POST (not JSON) for an unknown uid must redirect back with the
    // flash, not answer with JSON asFailure() cannot produce for a form.
    $response = $this->post('/warp/sessions/revoke', ['uid' => StringHelper::UUID()]);

    expect($response->getStatusCode())->toBe(302)
        ->and(Craft::$app->getSession()->hasFlash('error'))->toBeTrue();
});

it('redirects back with a flash when auth is stale on a form-mode revoke', function() {
    $user = revokeUser();
    $this->actingAs($user);

    // No recent-auth stamp: the gate fails. In form mode it degrades from the
    // reauthRequired JSON envelope to a redirect back with the flash.
    $response = $this->post('/warp/sessions/revoke', ['uid' => StringHelper::UUID()]);

    expect($response->getStatusCode())->toBe(302)
        ->and(Craft::$app->getSession()->hasFlash('error'))->toBeTrue();
});

// =============================================================================
// ownership — a user can only reach their own sessions
// =============================================================================

it('cannot revoke another user\'s session', function() {
    $alice = revokeUser();
    $bob = revokeUser();
    $bobToken = coreSessionFor((int)$bob->id);
    $bobUid = registryRowFor((int)$bob->id, $bobToken);

    $this->actingAs($alice);
    stampSessionAuth();

    $response = $this->postJson('/warp/sessions/revoke', ['uid' => $bobUid]);

    // Ownership is scoped in the service query, so Alice's attempt resolves to no
    // owned row: a soft failure (asFailure's 400), and Bob's session is untouched.
    expect($response->getStatusCode())->toBe(400)
        ->and(coreSessionAlive($bobToken))->toBeTrue();
});

// =============================================================================
// happy paths
// =============================================================================

it('revokes an owned session, deleting the core row', function() {
    $user = revokeUser();
    $token = coreSessionFor((int)$user->id);
    $uid = registryRowFor((int)$user->id, $token);

    $this->actingAs($user);
    stampSessionAuth();

    $response = $this->postJson('/warp/sessions/revoke', ['uid' => $uid]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getJsonContent()['message'])->toBeString()
        ->and(coreSessionAlive($token))->toBeFalse();
});

it('signs out other sessions, sparing the acting one', function() {
    $user = revokeUser();
    $other = coreSessionFor((int)$user->id);
    registryRowFor((int)$user->id, $other);

    $this->actingAs($user);
    stampSessionAuth();

    $response = $this->postJson('/warp/sessions/revoke-others');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getJsonContent()['count'])->toBe(1)
        ->and(coreSessionAlive($other))->toBeFalse();
});
