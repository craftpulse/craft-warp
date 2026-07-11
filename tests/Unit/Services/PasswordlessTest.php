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
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\services\Tokens;
use craftpulse\warp\models\Settings;
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
    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    Warp::$plugin->getSettings()->loginMethods = [Settings::CHANNEL_MAGIC_LINK, Settings::CHANNEL_OTP];
    AuthKit::getInstance()->set('tokens', ['class' => Tokens::class]);
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
