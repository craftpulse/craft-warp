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
use craftpulse\warp\services\Geo;
use craftpulse\warp\services\Logins;
use craftpulse\warp\tests\Support\CollectingMailer;
use craftpulse\warp\Warp;

/**
 * Returns a Geo test double that resolves every IP to a fixed location, so
 * new-location detection can be exercised without a real MMDB.
 */
function fixedGeo(?string $city, ?string $country): Geo
{
    return new class($city, $country) extends Geo {
        public function __construct(private readonly ?string $_city, private readonly ?string $_country)
        {
            parent::__construct();
        }

        public function lookup(?string $ip): array
        {
            return ['city' => $this->_city, 'country' => $this->_country];
        }
    };
}

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

it('paginates, searches, and sorts the table data newest first', function() {
    $alice = loginLogUser();
    $bob = loginLogUser();

    Warp::$plugin->getLogins()->record($alice, Login::METHOD_MAGIC_LINK);
    Warp::$plugin->getLogins()->record($bob, Login::METHOD_OTP);

    // Default sort is newest first, so the just-recorded rows lead the page
    // regardless of any older rows already in the shared playground table.
    $all = Warp::$plugin->getLogins()->getTableData(1, 20);

    expect($all['total'])->toBeGreaterThanOrEqual(2)
        ->and((int)$all['rows'][0]['userId'])->toBe((int)$bob->id)
        ->and($all['rows'][0]['email'])->toBe($bob->email);

    // Search narrows to the single user whose unique email matches.
    $found = Warp::$plugin->getLogins()->getTableData(1, 20, $alice->email);

    expect($found['total'])->toBe(1)
        ->and($found['rows'][0]['email'])->toBe($alice->email);
});

it('bounds a page to the requested limit', function() {
    foreach (range(1, 3) as $i) {
        Warp::$plugin->getLogins()->record(loginLogUser(), Login::METHOD_OTP);
    }

    $page = Warp::$plugin->getLogins()->getTableData(1, 2);

    expect($page['rows'])->toHaveCount(2)
        ->and($page['total'])->toBeGreaterThanOrEqual(3);
});

it('never flags a first-ever login as new, nor a repeat from the same place', function() {
    $user = loginLogUser();
    Warp::$plugin->set('geo', fixedGeo('Brussels', 'BE'));
    $originalMailer = Craft::$app->getMailer();
    Craft::$app->set('mailer', new CollectingMailer());

    try {
        Warp::$plugin->getLogins()->record($user, Login::METHOD_OTP);
        $first = LoginRecord::find()->where(['userId' => $user->id])->orderBy(['id' => SORT_DESC])->one();

        expect((bool)$first->isNewLocation)->toBeFalse()
            ->and($first->country)->toBe('BE')
            ->and($first->city)->toBe('Brussels');

        Warp::$plugin->getLogins()->record($user, Login::METHOD_OTP);
        $second = LoginRecord::find()->where(['userId' => $user->id])->orderBy(['id' => SORT_DESC])->one();

        expect((bool)$second->isNewLocation)->toBeFalse();
    } finally {
        Craft::$app->set('mailer', $originalMailer);
        Warp::$plugin->set('geo', ['class' => Geo::class]);
    }
});

it('flags a new location and alerts the member once a baseline exists', function() {
    $user = loginLogUser();

    // A baseline sign-in from Paris makes a later sign-in from elsewhere new.
    $baseline = new LoginRecord();
    $baseline->userId = (int)$user->id;
    $baseline->method = Login::METHOD_OTP;
    $baseline->country = 'FR';
    $baseline->city = 'Paris';
    $baseline->save(false);

    Warp::$plugin->set('geo', fixedGeo('Tokyo', 'JP'));
    $mailer = new CollectingMailer();
    $originalMailer = Craft::$app->getMailer();
    Craft::$app->set('mailer', $mailer);

    try {
        Warp::$plugin->getLogins()->record($user, Login::METHOD_MAGIC_LINK);
        $row = LoginRecord::find()->where(['userId' => $user->id, 'country' => 'JP'])->one();

        expect((bool)$row->isNewLocation)->toBeTrue()
            ->and($mailer->sent)->toHaveCount(1)
            ->and($mailer->lastRecipients())->toBe([$user->email]);
    } finally {
        Craft::$app->set('mailer', $originalMailer);
        Warp::$plugin->set('geo', ['class' => Geo::class]);
    }
});

it('does not detect or alert without a geo database', function() {
    $user = loginLogUser();
    $mailer = new CollectingMailer();
    $originalMailer = Craft::$app->getMailer();
    Craft::$app->set('mailer', $mailer);

    try {
        Warp::$plugin->getLogins()->record($user, Login::METHOD_OTP);
        Warp::$plugin->getLogins()->record($user, Login::METHOD_OTP);
        $row = LoginRecord::find()->where(['userId' => $user->id])->orderBy(['id' => SORT_DESC])->one();

        expect((bool)$row->isNewLocation)->toBeFalse()
            ->and($row->country)->toBeNull()
            ->and($mailer->sent)->toHaveCount(0);
    } finally {
        Craft::$app->set('mailer', $originalMailer);
    }
});

it('respects the notifyOnNewLocation setting', function() {
    $user = loginLogUser();

    $baseline = new LoginRecord();
    $baseline->userId = (int)$user->id;
    $baseline->method = Login::METHOD_OTP;
    $baseline->country = 'FR';
    $baseline->city = 'Paris';
    $baseline->save(false);

    Warp::$plugin->set('geo', fixedGeo('Tokyo', 'JP'));
    $mailer = new CollectingMailer();
    $originalMailer = Craft::$app->getMailer();
    Craft::$app->set('mailer', $mailer);
    $originalNotify = Warp::$plugin->getSettings()->notifyOnNewLocation;
    Warp::$plugin->getSettings()->notifyOnNewLocation = false;

    try {
        Warp::$plugin->getLogins()->record($user, Login::METHOD_MAGIC_LINK);
        $row = LoginRecord::find()->where(['userId' => $user->id, 'country' => 'JP'])->one();

        // The location is still flagged; only the email is suppressed.
        expect((bool)$row->isNewLocation)->toBeTrue()
            ->and($mailer->sent)->toHaveCount(0);
    } finally {
        Warp::$plugin->getSettings()->notifyOnNewLocation = $originalNotify;
        Craft::$app->set('mailer', $originalMailer);
        Warp::$plugin->set('geo', ['class' => Geo::class]);
    }
});

it('anonymizes the stored IP when the setting is on, without disturbing geo', function() {
    $user = loginLogUser();
    Warp::$plugin->set('geo', fixedGeo('Brussels', 'BE'));
    $originalAnonymize = Warp::$plugin->getSettings()->anonymizeIp;
    Warp::$plugin->getSettings()->anonymizeIp = true;

    // craft-pest identifies the fake request via an X-Forwarded-For header,
    // and getUserIP() memoizes its first answer — plant a known address in
    // that header and reset the memo on both sides.
    $request = Craft::$app->getRequest();
    $ipProperty = new ReflectionProperty(craft\web\Request::class, '_ipAddress');
    $ipProperty->setValue($request, null);
    $originalForwardedFor = $request->getHeaders()->get('X-Forwarded-For');
    $request->getHeaders()->set('X-Forwarded-For', '203.0.113.45');

    try {
        Warp::$plugin->getLogins()->record($user, Login::METHOD_OTP);
    } finally {
        Warp::$plugin->getSettings()->anonymizeIp = $originalAnonymize;
        Warp::$plugin->set('geo', ['class' => Geo::class]);
        $request->getHeaders()->set('X-Forwarded-For', $originalForwardedFor);
        $ipProperty->setValue($request, null);
    }

    $record = LoginRecord::findOne(['userId' => $user->id]);

    expect($record->ip)->toBe('203.0.113.0')
        ->and($record->city)->toBe('Brussels')
        ->and($record->country)->toBe('BE');
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
