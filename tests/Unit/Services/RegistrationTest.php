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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\UserGroup;
use craftpulse\authkit\models\Token;
use craftpulse\warp\Warp;

/**
 * Locks a user directly in the database (core has no public lockUser), then
 * reloads it so `$user->locked` is populated. getStatus() folds the lock into
 * "active", so this is exactly the account state the explicit lock check guards.
 */
function lockRegistrationUser(User $user): User
{
    Craft::$app->getDb()->createCommand()
        ->update('{{%users}}', ['locked' => true, 'lockoutDate' => Db::prepareDateForDb(new DateTime())], ['id' => $user->id])
        ->execute();

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

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

beforeEach(function() {
    // The playground is a shared install, so the registration switch is
    // restored to whatever it was, not to Craft's default. Tests that depend
    // on a specific switch state set it explicitly themselves.
    $this->originalAllowPublicRegistration = (bool)Craft::$app->getProjectConfig()->get('users.allowPublicRegistration');
});

afterEach(function() {
    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    $settings = Warp::$plugin->getSettings();
    $settings->enableRegistration = true;
    $settings->registrationGroupUid = null;

    setAllowPublicRegistration($this->originalAllowPublicRegistration);
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

it('fails closed for an account matched by username only', function() {
    // The token proved possession of the payload mailbox, nothing else. An
    // account whose USERNAME is that address but whose email is a different
    // mailbox (a squatted username) must never be unlocked by it — honouring
    // the match would log the mailbox holder into someone else's account.
    $tokenEmail = registrationEmail();
    $squatter = new User();
    $squatter->username = $tokenEmail;
    $squatter->email = registrationEmail();

    if (!Craft::$app->getElements()->saveElement($squatter)) {
        throw new RuntimeException('Could not save squatter test user.');
    }

    Craft::$app->getUsers()->activateUser($squatter);

    expect(Warp::$plugin->getRegistration()->fulfill(registrationToken($tokenEmail)))->toBeNull();
});

it('fails closed for a suspended account', function() {
    $email = registrationEmail();
    $user = activeRegistrationUser($email);
    Craft::$app->getUsers()->suspendUser($user);

    expect(Warp::$plugin->getRegistration()->fulfill(registrationToken($email)))->toBeNull();
});

it('fails closed for a locked active account', function() {
    // getStatus() folds a lock into "active", so a stale token would otherwise
    // slip past straight into a session behind the lockout.
    $email = registrationEmail();
    lockRegistrationUser(activeRegistrationUser($email));

    expect(Warp::$plugin->getRegistration()->fulfill(registrationToken($email)))->toBeNull();
});

it('fails closed for a locked pending account instead of activating it', function() {
    // The pending check runs before the fold, so a locked-pending account still
    // reports pending — the lock must be honoured before activation.
    $email = registrationEmail();
    $pending = lockRegistrationUser(pendingRegistrationUser($email));
    expect($pending->getStatus())->toBe(User::STATUS_PENDING);

    expect(Warp::$plugin->getRegistration()->fulfill(registrationToken($email)))->toBeNull();

    // The account must remain pending, never activated behind the lock.
    expect(Craft::$app->getUsers()->getUserById((int)$pending->id)->getStatus())->toBe(User::STATUS_PENDING);
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
    setAllowPublicRegistration(true);

    expect(Warp::$plugin->getRegistration()->isEnabled())->toBeTrue();
});

it('is disabled when Warp\'s own setting is off', function() {
    Warp::$plugin->getSettings()->enableRegistration = false;
    setAllowPublicRegistration(true);

    expect(Warp::$plugin->getRegistration()->isEnabled())->toBeFalse();
});

it('is disabled when Craft\'s allowPublicRegistration is off', function() {
    Warp::$plugin->getSettings()->enableRegistration = true;
    setAllowPublicRegistration(false);

    expect(Warp::$plugin->getRegistration()->isEnabled())->toBeFalse();
});
