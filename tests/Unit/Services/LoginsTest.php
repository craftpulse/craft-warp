<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Service tests for the passwordless login log: recording captures the user,
 * method, and a truncated device string; getRecent returns the newest rows
 * first within the limit; and Craft's garbage collection prunes rows past the
 * retention window while sparing recent ones.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use Carbon\Carbon;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craftpulse\warp\db\Table;
use craftpulse\warp\models\Login;
use craftpulse\warp\records\Login as LoginRecord;
use craftpulse\warp\services\Logins;
use craftpulse\warp\Warp;

function loginLogUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "log-{$unique}@warp-test.example";
    $user->email = "log-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save login-log test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

afterEach(function() {
    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('records a login with its method', function() {
    $user = loginLogUser();

    Warp::$plugin->getLogins()->record($user, Login::METHOD_OTP);

    $record = LoginRecord::findOne(['userId' => $user->id]);

    expect($record)->not->toBeNull()
        ->and($record->method)->toBe(Login::METHOD_OTP);
});

it('truncates a long user-agent string to the column width', function() {
    $user = loginLogUser();
    Craft::$app->getRequest()->getHeaders()->set('User-Agent', str_repeat('x', 400));

    try {
        Warp::$plugin->getLogins()->record($user, Login::METHOD_MAGIC_LINK);
    } finally {
        Craft::$app->getRequest()->getHeaders()->remove('User-Agent');
    }

    $record = LoginRecord::findOne(['userId' => $user->id]);

    expect(mb_strlen((string)$record->userAgent))->toBe(255);
});

it('returns the most recent logins first within the limit', function() {
    $first = loginLogUser();
    $second = loginLogUser();
    $third = loginLogUser();

    Warp::$plugin->getLogins()->record($first, Login::METHOD_MAGIC_LINK);
    Warp::$plugin->getLogins()->record($second, Login::METHOD_OTP);
    Warp::$plugin->getLogins()->record($third, Login::METHOD_PASSKEY);

    $recent = Warp::$plugin->getLogins()->getRecent(2);

    expect($recent)->toHaveCount(2)
        ->and($recent[0])->toBeInstanceOf(Login::class)
        ->and($recent[0]->userId)->toBe((int)$third->id)
        ->and($recent[1]->userId)->toBe((int)$second->id);
});

it('prunes rows past the retention window when garbage collection runs', function() {
    $stale = loginLogUser();
    $fresh = loginLogUser();

    Warp::$plugin->getLogins()->record($stale, Login::METHOD_MAGIC_LINK);
    Warp::$plugin->getLogins()->record($fresh, Login::METHOD_OTP);

    // Backdate the stale row past the retention window; the fresh row stays put.
    $cutoff = Db::prepareDateForDb(Carbon::now()->subDays(Logins::PRUNE_MAX_AGE_DAYS + 1));
    Db::update(Table::LOGINS, ['dateCreated' => $cutoff], ['userId' => $stale->id]);

    // The plugin wires prune() onto Gc::EVENT_RUN; a forced GC pass must fire it
    // and clear the stale row while sparing the recent one.
    Craft::$app->getGc()->run(true);

    expect(LoginRecord::findOne(['userId' => $stale->id]))->toBeNull()
        ->and(LoginRecord::findOne(['userId' => $fresh->id]))->not->toBeNull();
});
