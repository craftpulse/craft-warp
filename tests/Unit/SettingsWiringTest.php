<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Verifies that Warp's settings reach Auth Kit per issuance — as of Auth Kit
 * 1.4.0 Warp writes NOTHING onto the shared services (another consumer could
 * clobber it); the route, lifetime, throttle, digits, and origin ride each
 * issuance instead.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\records\Token as TokenRecord;
use craftpulse\authkit\services\Tokens;
use craftpulse\warp\models\Settings;
use craftpulse\warp\services\Passwordless;
use craftpulse\warp\tests\Support\CollectingMailer;
use craftpulse\warp\Warp;

afterEach(function() {
    $settings = Warp::$plugin->getSettings();
    $settings->tokenTtl = 900;
    $settings->otpDigits = 6;

    AuthKit::getInstance()->set('tokens', ['class' => Tokens::class]);

    foreach (User::find()->email('wpwire-*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('threads settings and origin into each issuance instead of shared Auth Kit state', function() {
    $mailer = new CollectingMailer();
    AuthKit::getInstance()->set('tokens', new Tokens(['mailer' => $mailer]));

    $settings = Warp::$plugin->getSettings();
    $settings->tokenTtl = 120;
    $settings->otpDigits = 8;

    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "wpwire-{$unique}@warp-test.example";
    $user->email = "wpwire-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save settings wiring test user.');
    }

    Craft::$app->getUsers()->activateUser($user);
    $user = Craft::$app->getUsers()->getUserById((int)$user->id);

    Warp::$plugin->getPasswordless()->request($user->email, Settings::CHANNEL_MAGIC_LINK);

    // The emailed URL points at Warp's verify route without the shared
    // magicLinkRoute ever having been touched...
    expect((string)$mailer->lastLink())->toContain('warp/auth/verify-link')
        ->and(AuthKit::$plugin->getTokens()->magicLinkRoute)->toBe('auth-kit/magic-link/verify');

    // ...and the token row carries Warp's origin and the settings-driven ttl.
    $record = TokenRecord::findOne(['userId' => $user->id]);
    $delta = (new DateTime((string)$record->expiryDate, new DateTimeZone('UTC')))->getTimestamp() - time();

    expect($record->origin)->toBe(Passwordless::TOKEN_ORIGIN)
        ->and($delta)->toBeGreaterThan(60)
        ->and($delta)->toBeLessThanOrEqual(121);

    // The OTP channel threads its digits the same way.
    Warp::$plugin->getPasswordless()->request($user->email, Settings::CHANNEL_OTP);

    expect(strlen((string)$mailer->lastCode()))->toBe(8);
});
