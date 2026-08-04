<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Tests for the passkey-enrollment nudge: an email-flow login by a user with no
 * passkey flags the nudge, a user who already has one is never flagged, and the
 * flag is suppressed when the setting is off.
 *
 * The rest of the file pins the nudge's lifecycle, which is deliberately NOT
 * show-once: the flag survives every read, so the nudge holds across reloads and
 * posts for the rest of the session, and only a dismissal, an enrolled passkey,
 * or a new session ends it.
 *
 * The login is driven through the real magic-link verify endpoint so the flag is
 * written and read within one genuine web-session lifecycle, exactly as the
 * front end sees it.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\models\Token;
use craftpulse\authkit\records\Token as TokenRecord;
use craftpulse\authkit\services\Passkeys;
use craftpulse\warp\services\Passwordless;
use craftpulse\warp\variables\WarpVariable;
use craftpulse\warp\Warp;

/**
 * A Passkeys stub whose passkey-ownership answer is fixed, so the nudge branch
 * can be exercised without a real WebAuthn credential.
 */
class NudgeStubPasskeys extends Passkeys
{
    public bool $stubHasPasskeys = false;

    public function hasPasskeys(User $user): bool
    {
        return $this->stubHasPasskeys;
    }
}

function nudgeUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "nudge-{$unique}@warp-test.example";
    $user->email = "nudge-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save nudge test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function seedNudgeMagicLink(int $userId, string $rawToken): void
{
    $record = new TokenRecord();
    $record->userId = $userId;
    $record->type = Token::TYPE_MAGIC_LINK;
    $record->origin = Passwordless::TOKEN_ORIGIN;
    $record->tokenHash = hash('sha256', $rawToken);
    $record->expiryDate = (string)Db::prepareDateForDb((new DateTime())->modify('+15 minutes'));
    $record->save(false);
}

function stubPasskeys(bool $hasPasskeys): void
{
    AuthKit::getInstance()->set('passkeys', new NudgeStubPasskeys(['stubHasPasskeys' => $hasPasskeys]));
}

function loginViaMagicLink(User $user): void
{
    $rawToken = 'nudge-' . StringHelper::UUID();
    seedNudgeMagicLink((int)$user->id, $rawToken);
    test()->get('/warp/auth/verify-link?mlToken=' . $rawToken);
}

beforeEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
    Warp::$plugin->getSettings()->enablePasskeyNudge = true;
    stubPasskeys(false);
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
    Warp::$plugin->getSettings()->enablePasskeyNudge = true;
    AuthKit::getInstance()->set('passkeys', ['class' => Passkeys::class]);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('flags the nudge after an email-flow login by a user with no passkey', function() {
    $user = nudgeUser();

    loginViaMagicLink($user);

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeTrue();
});

it('does not flag the nudge when the user already has a passkey', function() {
    stubPasskeys(true);
    $user = nudgeUser();

    loginViaMagicLink($user);

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeFalse();
});

it('does not flag the nudge when the setting is disabled', function() {
    Warp::$plugin->getSettings()->enablePasskeyNudge = false;
    $user = nudgeUser();

    loginViaMagicLink($user);

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeFalse();
});

it('keeps showing the nudge across repeated reads, so a reload does not consume it', function() {
    $user = nudgeUser();
    loginViaMagicLink($user);

    $variable = new WarpVariable();

    // Michael's case: saving a name reloads the account page. Reading the flag
    // must not be what takes the nudge away.
    expect($variable->getShowPasskeyNudge())->toBeTrue()
        ->and($variable->getShowPasskeyNudge())->toBeTrue()
        ->and((new WarpVariable())->getShowPasskeyNudge())->toBeTrue();
});

it('stops showing the nudge once the member dismisses it', function() {
    $user = nudgeUser();
    loginViaMagicLink($user);

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeTrue();

    Warp::$plugin->getPasswordless()->dismissPasskeyNudge();

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeFalse();
});

it('stops showing the nudge the moment a passkey exists, without waiting for a new login', function() {
    $user = nudgeUser();
    loginViaMagicLink($user);

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeTrue();

    // The member enrolls one on the passkeys page and comes back.
    stubPasskeys(true);

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeFalse();
});

it('flags the nudge again on the next sign-in after a dismissal', function() {
    $user = nudgeUser();
    loginViaMagicLink($user);
    Warp::$plugin->getPasswordless()->dismissPasskeyNudge();

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeFalse();

    loginViaMagicLink($user);

    expect((new WarpVariable())->getShowPasskeyNudge())->toBeTrue();
});
