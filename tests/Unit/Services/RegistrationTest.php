<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Service tests for the registration account matrix (decision D2): an unknown
 * address is created active, password-less, and grouped; a pending account is
 * activated; an active account passes through; a suspended account fails closed.
 * Plus the double-gate on isEnabled() (decision D3) — both Warp's own switch and
 * Craft's allowPublicRegistration must be on.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use craft\models\UserGroup;
use craftpulse\authkit\models\Token;
use craftpulse\warp\Warp;

function registrationToken(string $email): Token
{
    return new Token([
        'type' => Token::TYPE_REGISTER,
        'tokenHash' => hash('sha256', StringHelper::UUID()),
        'payload' => ['email' => $email],
    ]);
}

function makeRegistrationGroup(): UserGroup
{
    $unique = strtolower(str_replace('-', '', StringHelper::UUID()));
    $group = new UserGroup(['name' => "WR {$unique}", 'handle' => "wr{$unique}"]);

    if (!Craft::$app->getUserGroups()->saveGroup($group)) {
        throw new RuntimeException('Could not save registration test user group.');
    }

    return $group;
}

function registrationEmail(): string
{
    return 'reg-' . str_replace('-', '', StringHelper::UUID()) . '@warp-test.example';
}

function pendingRegistrationUser(string $email): User
{
    $user = new User();
    $user->username = $email;
    $user->email = $email;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save pending registration test user.');
    }

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function activeRegistrationUser(string $email): User
{
    $user = new User();
    $user->username = $email;
    $user->email = $email;

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save active registration test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

afterEach(function() {
    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    $settings = Warp::$plugin->getSettings();
    $settings->enableRegistration = true;
    $settings->registrationGroupUid = null;

    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', false);
});

// =============================================================================
// fulfill — account matrix (D2)
// =============================================================================

it('creates an active, password-less user in the configured group for an unknown address', function() {
    $group = makeRegistrationGroup();
    Warp::$plugin->getSettings()->registrationGroupUid = $group->uid;

    $email = registrationEmail();
    $user = Warp::$plugin->getRegistration()->fulfill(registrationToken($email));

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->getStatus())->toBe(User::STATUS_ACTIVE)
        ->and($user->username)->toBe($email)
        ->and($user->email)->toBe($email)
        ->and($user->getHasPassword())->toBeFalse()
        ->and($user->isInGroup($group))->toBeTrue();

    Craft::$app->getUserGroups()->deleteGroupById((int)$group->id);
});

it('falls back to Craft\'s default group when no registration group is configured', function() {
    Warp::$plugin->getSettings()->registrationGroupUid = null;

    $email = registrationEmail();
    $user = Warp::$plugin->getRegistration()->fulfill(registrationToken($email));

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->getStatus())->toBe(User::STATUS_ACTIVE);
});

it('falls back to the default group when the configured group UID is dangling', function() {
    Warp::$plugin->getSettings()->registrationGroupUid = StringHelper::UUID();

    $email = registrationEmail();
    $user = Warp::$plugin->getRegistration()->fulfill(registrationToken($email));

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->getStatus())->toBe(User::STATUS_ACTIVE);
});

it('activates a pending account for its address', function() {
    $email = registrationEmail();
    $pending = pendingRegistrationUser($email);

    expect($pending->getStatus())->toBe(User::STATUS_PENDING);

    $user = Warp::$plugin->getRegistration()->fulfill(registrationToken($email));

    expect($user)->toBeInstanceOf(User::class)
        ->and((int)$user->id)->toBe((int)$pending->id)
        ->and($user->getStatus())->toBe(User::STATUS_ACTIVE);
});

it('passes an already-active account straight through', function() {
    $email = registrationEmail();
    $existing = activeRegistrationUser($email);

    $user = Warp::$plugin->getRegistration()->fulfill(registrationToken($email));

    expect($user)->toBeInstanceOf(User::class)
        ->and((int)$user->id)->toBe((int)$existing->id)
        ->and($user->getStatus())->toBe(User::STATUS_ACTIVE);
});

it('fails closed for a suspended account', function() {
    $email = registrationEmail();
    $user = activeRegistrationUser($email);
    Craft::$app->getUsers()->suspendUser($user);

    expect(Warp::$plugin->getRegistration()->fulfill(registrationToken($email)))->toBeNull();
});

it('fails closed for a token with no payload email', function() {
    $token = new Token(['type' => Token::TYPE_REGISTER, 'payload' => null]);

    expect(Warp::$plugin->getRegistration()->fulfill($token))->toBeNull();
});

// =============================================================================
// isEnabled — the double gate (D3)
// =============================================================================

it('is enabled only when both Warp and Craft allow registration', function() {
    Warp::$plugin->getSettings()->enableRegistration = true;
    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', true);

    expect(Warp::$plugin->getRegistration()->isEnabled())->toBeTrue();
});

it('is disabled when Warp\'s own setting is off', function() {
    Warp::$plugin->getSettings()->enableRegistration = false;
    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', true);

    expect(Warp::$plugin->getRegistration()->isEnabled())->toBeFalse();
});

it('is disabled when Craft\'s allowPublicRegistration is off', function() {
    Warp::$plugin->getSettings()->enableRegistration = true;
    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', false);

    expect(Warp::$plugin->getRegistration()->isEnabled())->toBeFalse();
});
