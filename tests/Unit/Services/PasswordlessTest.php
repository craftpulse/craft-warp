<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Service tests for the passwordless login orchestrator: an enabled channel
 * delegates to Auth Kit and mails a known address, an unknown address gets
 * nothing, a disabled channel refuses without sending, and loginUser opens a
 * front-end session.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use craftpulse\authkit\audit\AuthEvent;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\services\Tokens;
use craftpulse\warp\models\Login;
use craftpulse\warp\models\Settings;
use craftpulse\warp\tests\Support\CollectingAuditSink;
use craftpulse\warp\tests\Support\CollectingMailer;
use craftpulse\warp\Warp;

function activePwlUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "pwl-{$unique}@warp-test.example";
    $user->email = "pwl-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save passwordless test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function collectingMailer(): CollectingMailer
{
    $mailer = new CollectingMailer();
    AuthKit::getInstance()->set('tokens', new Tokens(['mailer' => $mailer]));

    return $mailer;
}

beforeEach(function() {
    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP];
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP];
    AuthKit::getInstance()->set('tokens', ['class' => Tokens::class]);
    AuthKit::$plugin->getAudit()->setSinks([]);
});

it('mails a magic link to a known address', function() {
    $mailer = collectingMailer();
    $user = activePwlUser();

    Warp::$plugin->getPasswordless()->request($user->email, Settings::CHANNEL_MAGIC_LINK, '/members');

    expect($mailer->sent)->toHaveCount(1)
        ->and($mailer->lastRecipients())->toBe([$user->email])
        ->and($mailer->lastLink())->toContain(Tokens::TOKEN_PARAM);
});

it('mails an OTP code to a known address', function() {
    $mailer = collectingMailer();
    $user = activePwlUser();

    Warp::$plugin->getPasswordless()->request($user->email, Settings::CHANNEL_OTP);

    expect($mailer->sent)->toHaveCount(1)
        ->and($mailer->lastRecipients())->toBe([$user->email]);

    $code = end($mailer->sent)->variables['code'] ?? null;

    expect($code)->toBeString();
});

it('sends nothing for an unknown address', function() {
    $mailer = collectingMailer();

    Warp::$plugin->getPasswordless()->request('ghost-' . StringHelper::UUID() . '@warp-test.example', Settings::CHANNEL_MAGIC_LINK);

    expect($mailer->sent)->toHaveCount(0);
});

it('refuses a disabled channel without sending, even for a known address', function() {
    $mailer = collectingMailer();
    $user = activePwlUser();
    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_MAGIC_LINK];

    Warp::$plugin->getPasswordless()->request($user->email, Settings::CHANNEL_OTP);

    expect($mailer->sent)->toHaveCount(0);
});

// =============================================================================
// audit emission — loginUser records the sign-in through Auth Kit's contract
// =============================================================================

it('records a login.magic_link audit event on a magic-link login', function() {
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);
    $user = activePwlUser();

    Warp::$plugin->getPasswordless()->loginUser($user, Login::METHOD_MAGIC_LINK);

    $event = $sink->firstOfName(AuthEvent::LOGIN_MAGIC_LINK);

    expect($event)->not->toBeNull()
        ->and($event->emitter)->toBe('warp')
        ->and($event->outcome)->toBe(AuthEvent::OUTCOME_SUCCESS)
        ->and($event->userId)->toBe((int)$user->id);
});

it('records a login.otp audit event on an OTP login', function() {
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);
    $user = activePwlUser();

    Warp::$plugin->getPasswordless()->loginUser($user, Login::METHOD_OTP);

    $event = $sink->firstOfName(AuthEvent::LOGIN_OTP);

    expect($event)->not->toBeNull()
        ->and($event->userId)->toBe((int)$user->id);
});

it('records only registration.fulfilled on a registration login, never a login.* event', function() {
    // The signup implies the login, so the register method emits the single
    // registration.fulfilled event and no login.* alongside it — one user action,
    // one audit fact.
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);
    $user = activePwlUser();

    Warp::$plugin->getPasswordless()->loginUser($user, Login::METHOD_REGISTER);

    expect($sink->firstOfName(AuthEvent::REGISTRATION_FULFILLED))->not->toBeNull()
        ->and($sink->firstOfName(AuthEvent::REGISTRATION_FULFILLED)->userId)->toBe((int)$user->id)
        ->and($sink->ofName(AuthEvent::LOGIN_MAGIC_LINK))->toBe([])
        ->and($sink->ofName(AuthEvent::LOGIN_OTP))->toBe([]);
});
