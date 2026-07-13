<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * HTTP tests for the front-end AuthController: enumeration-safe credential
 * request, per-IP rate limiting on both request and OTP verify, the single-use
 * magic-link and attempt-capped OTP verify-into-session flows, and the opaque
 * failure path for bad, expired, or reused credentials.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\UserGroup;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\models\Token;
use craftpulse\authkit\records\Token as TokenRecord;
use craftpulse\authkit\services\Tokens;
use craftpulse\warp\controllers\AuthController;
use craftpulse\warp\tests\Support\CollectingMailer;
use craftpulse\warp\Warp;
use yii\web\TooManyRequestsHttpException;

function activeAuthUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "auth-{$unique}@warp-test.example";
    $user->email = "auth-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save auth controller test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function seedMagicLink(int $userId, string $rawToken, string $expiryModifier = '+15 minutes'): void
{
    $record = new TokenRecord();
    $record->userId = $userId;
    $record->type = Token::TYPE_MAGIC_LINK;
    $record->tokenHash = hash('sha256', $rawToken);
    $record->expiryDate = (string)Db::prepareDateForDb((new DateTime())->modify($expiryModifier));
    $record->save(false);
}

function seedOtp(User $user, string $code): void
{
    $record = new TokenRecord();
    $record->userId = (int)$user->id;
    $record->type = Token::TYPE_OTP;
    $record->tokenHash = hash('sha256', (string)$user->uid . ':' . $code);
    $record->maxAttempts = 5;
    $record->expiryDate = (string)Db::prepareDateForDb((new DateTime())->modify('+15 minutes'));
    $record->save(false);
}

function seedRegistration(string $email, string $rawToken, string $expiryModifier = '+15 minutes'): void
{
    $record = new TokenRecord();
    $record->userId = null;
    $record->type = Token::TYPE_REGISTER;
    $record->tokenHash = hash('sha256', $rawToken);
    $record->expiryDate = (string)Db::prepareDateForDb((new DateTime())->modify($expiryModifier));
    $record->payload = Json::encode(['email' => $email]);
    $record->save(false);
}

function suspendedAuthUser(string $email): User
{
    $user = new User();
    $user->username = $email;
    $user->email = $email;

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save suspended auth controller test user.');
    }

    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUsers()->suspendUser(Craft::$app->getUsers()->getUserById((int)$user->id));

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function pendingAuthUser(string $email): User
{
    $user = new User();
    $user->username = $email;
    $user->email = $email;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save pending auth controller test user.');
    }

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function makeAuthGroup(): UserGroup
{
    $unique = strtolower(str_replace('-', '', StringHelper::UUID()));
    $group = new UserGroup(['name' => "WA {$unique}", 'handle' => "wa{$unique}"]);

    if (!Craft::$app->getUserGroups()->saveGroup($group)) {
        throw new RuntimeException('Could not save auth controller test user group.');
    }

    return $group;
}

function capturedMailer(): CollectingMailer
{
    $mailer = AuthKit::$plugin->getTokens()->mailer;
    assert($mailer instanceof CollectingMailer);

    return $mailer;
}

function enablePublicRegistration(): void
{
    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', true);
    Warp::$plugin->getSettings()->enableRegistration = true;
}

beforeEach(function() {
    Craft::$app->getUser()->setIdentity(null);

    // Swap Auth Kit's token store for one wired to a captured mailer so request
    // tests never touch a real transport, then re-apply Warp's wiring so the
    // fresh instance carries Warp's verify routes (the emitted links must point
    // at warp/auth/*), not Auth Kit's defaults.
    AuthKit::getInstance()->set('tokens', new Tokens(['mailer' => new CollectingMailer()]));
    (new ReflectionMethod(Warp::class, '_configureAuthKit'))->invoke(Warp::$plugin);
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    // Registration tokens carry a null userId, so they do not cascade-delete
    // with the test users above and craft-pest's HTTP dispatch commits them past
    // the wrapping transaction. Purge them explicitly so fixed-hash fixtures
    // never collide across runs.
    TokenRecord::deleteAll(['and', ['type' => Token::TYPE_REGISTER], ['like', 'payload', 'warp-test.example']]);

    $settings = Warp::$plugin->getSettings();
    $settings->enableRegistration = true;
    $settings->registrationGroupUid = null;

    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', false);
    AuthKit::getInstance()->set('tokens', ['class' => Tokens::class]);
});

// =============================================================================
// request — enumeration-safe issuance + rate limiting
// =============================================================================

it('responds byte-identically to a known and an unknown address', function() {
    $user = activeAuthUser();

    $known = $this->postJson('/warp/auth/request', ['email' => $user->email]);
    $unknown = $this->postJson('/warp/auth/request', ['email' => 'ghost-' . StringHelper::UUID() . '@warp-test.example']);

    expect($known->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($known->content)->toBe($unknown->content);

    // A token exists only for the real account — but the response never said so.
    expect((int)TokenRecord::find()->where(['userId' => $user->id, 'type' => Token::TYPE_MAGIC_LINK])->count())->toBe(1);
});

it('rate-limits request posts per IP', function() {
    for ($i = 0; $i < AuthController::REQUEST_RATE_LIMIT; $i++) {
        $this->post('/warp/auth/request', ['email' => "flood-{$i}@warp-test.example"]);
    }

    expect(fn() => $this->post('/warp/auth/request', ['email' => 'flood-final@warp-test.example']))
        ->toThrow(TooManyRequestsHttpException::class);
});

it('rejects a request that is not a POST', function() {
    $this->get('/warp/auth/request');
})->throws(yii\web\MethodNotAllowedHttpException::class);

// =============================================================================
// verify-link — single-use magic-link login
// =============================================================================

it('verifies a valid magic link into a logged-in session and is single-use', function() {
    $user = activeAuthUser();
    seedMagicLink((int)$user->id, 'auth-raw-token');

    $response = $this->get('/warp/auth/verify-link?mlToken=auth-raw-token&returnUrl=/members');

    expect($response->getStatusCode())->toBe(302)
        ->and((string)$response->getHeaders()->get('location'))->toContain('/members')
        ->and(Craft::$app->getUser()->getId())->toBe((int)$user->id);

    // Reuse of the same link must not log anyone in.
    Craft::$app->getUser()->logout();
    $replay = $this->get('/warp/auth/verify-link?mlToken=auth-raw-token');

    expect($replay->getStatusCode())->toBe(302)
        ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
});

it('fails opaquely on an unknown magic-link token without logging anyone in', function() {
    $response = $this->get('/warp/auth/verify-link?mlToken=never-issued');

    expect($response->getStatusCode())->toBe(302)
        ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
});

it('fails opaquely on an expired magic-link token', function() {
    $user = activeAuthUser();
    seedMagicLink((int)$user->id, 'stale-raw-token', '-1 minute');

    $response = $this->get('/warp/auth/verify-link?mlToken=stale-raw-token');

    expect($response->getStatusCode())->toBe(302)
        ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
});

it('lands a failed magic-link verify on the loginPath page so the flash renders', function() {
    $generalConfig = Craft::$app->getConfig()->getGeneral();
    $originalLoginPath = $generalConfig->loginPath;
    $generalConfig->loginPath = 'members/login';

    try {
        $response = $this->get('/warp/auth/verify-link?mlToken=never-issued');

        expect($response->getStatusCode())->toBe(302)
            ->and((string)$response->getHeaders()->get('location'))->toContain('members/login')
            ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
    } finally {
        $generalConfig->loginPath = $originalLoginPath;
    }
});

it('falls back to the site root on a failed magic-link verify when loginPath is disabled', function() {
    $generalConfig = Craft::$app->getConfig()->getGeneral();
    $originalLoginPath = $generalConfig->loginPath;
    $generalConfig->loginPath = false;

    try {
        $response = $this->get('/warp/auth/verify-link?mlToken=never-issued');

        expect($response->getStatusCode())->toBe(302)
            ->and((string)$response->getHeaders()->get('location'))->not->toContain('login')
            ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
    } finally {
        $generalConfig->loginPath = $originalLoginPath;
    }
});

it('drops an unsafe returnUrl on magic-link verify instead of redirecting offsite', function() {
    $user = activeAuthUser();
    seedMagicLink((int)$user->id, 'offsite-raw-token');

    $response = $this->get('/warp/auth/verify-link?mlToken=offsite-raw-token&returnUrl=https://evil.example.com/phish');

    expect($response->getStatusCode())->toBe(302)
        ->and((string)$response->getHeaders()->get('location'))->not->toContain('evil.example.com');
});

// =============================================================================
// verify-code — attempt-capped OTP login
// =============================================================================

it('verifies a correct OTP into a logged-in session', function() {
    $user = activeAuthUser();
    seedOtp($user, '123456');

    $response = $this->post('/warp/auth/verify-code', [
        'email' => $user->email,
        'code' => '123456',
        'returnUrl' => '/members',
    ]);

    expect($response->getStatusCode())->toBe(302)
        ->and((string)$response->getHeaders()->get('location'))->toContain('/members')
        ->and(Craft::$app->getUser()->getId())->toBe((int)$user->id);
});

it('carries the requested address into the session and clears it on a successful verify', function() {
    $user = activeAuthUser();

    $this->post('/warp/auth/request', ['email' => $user->email, 'channel' => 'otp']);

    expect(Craft::$app->getSession()->get(AuthController::SESSION_REQUESTED_EMAIL))->toBe($user->email);

    seedOtp($user, '424242');
    $this->post('/warp/auth/verify-code', [
        'email' => $user->email,
        'code' => '424242',
    ]);

    expect(Craft::$app->getUser()->getId())->toBe((int)$user->id)
        ->and(Craft::$app->getSession()->get(AuthController::SESSION_REQUESTED_EMAIL))->toBeNull();
});

it('burns the OTP after repeated wrong codes and refuses the correct one after', function() {
    $user = activeAuthUser();
    seedOtp($user, '654321');

    for ($i = 0; $i < 5; $i++) {
        $this->post('/warp/auth/verify-code', ['email' => $user->email, 'code' => '000000']);
    }

    $this->post('/warp/auth/verify-code', ['email' => $user->email, 'code' => '654321']);

    expect(Craft::$app->getUser()->getIsGuest())->toBeTrue();
});

it('returns a generic JSON failure for a wrong OTP', function() {
    $user = activeAuthUser();
    seedOtp($user, '111222');

    $response = $this->postJson('/warp/auth/verify-code', ['email' => $user->email, 'code' => '999888']);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getJsonContent()['message'])->toBeString()
        ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
});

it('rate-limits OTP verify posts per IP', function() {
    $user = activeAuthUser();
    seedOtp($user, '333444');

    for ($i = 0; $i < AuthController::VERIFY_CODE_RATE_LIMIT; $i++) {
        $this->post('/warp/auth/verify-code', ['email' => $user->email, 'code' => '000000']);
    }

    expect(fn() => $this->post('/warp/auth/verify-code', ['email' => $user->email, 'code' => '000000']))
        ->toThrow(TooManyRequestsHttpException::class);
});

it('rejects an OTP verify that is not a POST', function() {
    $this->get('/warp/auth/verify-code');
})->throws(yii\web\MethodNotAllowedHttpException::class);

// =============================================================================
// request — enumeration safety across the login/registration branches
// =============================================================================

it('responds byte-identically across active, unknown, pending, suspended, and registration-off addresses', function() {
    enablePublicRegistration();

    $active = activeAuthUser();
    $pending = pendingAuthUser('pend-' . str_replace('-', '', StringHelper::UUID()) . '@warp-test.example');
    $suspended = suspendedAuthUser('susp-' . str_replace('-', '', StringHelper::UUID()) . '@warp-test.example');

    $existing = $this->postJson('/warp/auth/request', ['email' => $active->email]);
    $unknown = $this->postJson('/warp/auth/request', ['email' => 'ghost-' . StringHelper::UUID() . '@warp-test.example']);
    $pendingResp = $this->postJson('/warp/auth/request', ['email' => $pending->email]);
    $suspendedResp = $this->postJson('/warp/auth/request', ['email' => $suspended->email]);

    Craft::$app->getProjectConfig()->set('users.allowPublicRegistration', false);
    $registrationOff = $this->postJson('/warp/auth/request', ['email' => 'ghost2-' . StringHelper::UUID() . '@warp-test.example']);

    expect($unknown->getStatusCode())->toBe($existing->getStatusCode())
        ->and($unknown->content)->toBe($existing->content)
        ->and($pendingResp->getStatusCode())->toBe($existing->getStatusCode())
        ->and($pendingResp->content)->toBe($existing->content)
        ->and($suspendedResp->getStatusCode())->toBe($existing->getStatusCode())
        ->and($suspendedResp->content)->toBe($existing->content)
        ->and($registrationOff->getStatusCode())->toBe($existing->getStatusCode())
        ->and($registrationOff->content)->toBe($existing->content);
});

it('sends no registration email for a suspended address even with registration open', function() {
    enablePublicRegistration();
    $suspended = suspendedAuthUser('suspreg-' . str_replace('-', '', StringHelper::UUID()) . '@warp-test.example');

    $this->post('/warp/auth/request', ['email' => $suspended->email]);

    expect(capturedMailer()->sent)->toHaveCount(0);
});

it('sends no registration email when public registration is off', function() {
    // allowPublicRegistration defaults off; an unknown address gets nothing.
    $this->post('/warp/auth/request', ['email' => 'noreg-' . StringHelper::UUID() . '@warp-test.example']);

    expect(capturedMailer()->sent)->toHaveCount(0);
});

// =============================================================================
// verify-registration — full passwordless signup
// =============================================================================

it('completes a full passwordless signup from request through to a logged-in session', function() {
    enablePublicRegistration();
    $group = makeAuthGroup();
    Warp::$plugin->getSettings()->registrationGroupUid = $group->uid;

    $email = 'signup-' . str_replace('-', '', StringHelper::UUID()) . '@warp-test.example';

    $this->post('/warp/auth/request', ['email' => $email, 'returnUrl' => '/members']);

    $mailer = capturedMailer();
    expect($mailer->sent)->toHaveCount(1)
        ->and($mailer->lastRecipients())->toBe([$email])
        ->and($mailer->lastLink())->toContain('warp/auth/verify-registration');

    parse_str((string)parse_url((string)$mailer->lastLink(), PHP_URL_QUERY), $params);
    $rawToken = (string)($params[Tokens::TOKEN_PARAM] ?? '');

    $response = $this->get('/warp/auth/verify-registration?' . http_build_query([
        Tokens::TOKEN_PARAM => $rawToken,
        'returnUrl' => '/members',
    ]));

    $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);

    expect($response->getStatusCode())->toBe(302)
        ->and((string)$response->getHeaders()->get('location'))->toContain('/members')
        ->and($user)->not->toBeNull()
        ->and($user->getStatus())->toBe(User::STATUS_ACTIVE)
        ->and($user->getHasPassword())->toBeFalse()
        ->and($user->isInGroup($group))->toBeTrue()
        ->and(Craft::$app->getUser()->getId())->toBe((int)$user->id);

    Craft::$app->getUserGroups()->deleteGroupById((int)$group->id);
});

it('activates a pending account and logs it in through the registration flow', function() {
    enablePublicRegistration();

    $email = 'pending-' . str_replace('-', '', StringHelper::UUID()) . '@warp-test.example';
    $pending = pendingAuthUser($email);
    expect($pending->getStatus())->toBe(User::STATUS_PENDING);

    // The pending address flows through the same request form and is emailed a
    // signup link exactly as an unknown one would be.
    $this->post('/warp/auth/request', ['email' => $email, 'returnUrl' => '/members']);

    $mailer = capturedMailer();
    expect($mailer->sent)->toHaveCount(1)
        ->and($mailer->lastRecipients())->toBe([$email])
        ->and($mailer->lastLink())->toContain('warp/auth/verify-registration');

    parse_str((string)parse_url((string)$mailer->lastLink(), PHP_URL_QUERY), $params);
    $rawToken = (string)($params[Tokens::TOKEN_PARAM] ?? '');

    $response = $this->get('/warp/auth/verify-registration?' . http_build_query([
        Tokens::TOKEN_PARAM => $rawToken,
        'returnUrl' => '/members',
    ]));

    $user = Craft::$app->getUsers()->getUserById((int)$pending->id);

    expect($response->getStatusCode())->toBe(302)
        ->and((string)$response->getHeaders()->get('location'))->toContain('/members')
        ->and($user->getStatus())->toBe(User::STATUS_ACTIVE)
        ->and(Craft::$app->getUser()->getId())->toBe((int)$pending->id);
});

it('fails generically and logs no one in when a registration link is reused', function() {
    $email = 'reuse-' . str_replace('-', '', StringHelper::UUID()) . '@warp-test.example';
    $rawToken = 'reuse-' . StringHelper::UUID();
    seedRegistration($email, $rawToken);

    $first = $this->get('/warp/auth/verify-registration?mlToken=' . $rawToken);

    expect($first->getStatusCode())->toBe(302)
        ->and(Craft::$app->getUser()->getId())->not->toBeNull();

    Craft::$app->getUser()->logout();
    $replay = $this->get('/warp/auth/verify-registration?mlToken=' . $rawToken);

    expect($replay->getStatusCode())->toBe(302)
        ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
});

it('fails closed when a registration token resolves to a suspended account', function() {
    $email = 'suspreg-' . str_replace('-', '', StringHelper::UUID()) . '@warp-test.example';
    $rawToken = 'susp-' . StringHelper::UUID();
    suspendedAuthUser($email);
    seedRegistration($email, $rawToken);

    $response = $this->get('/warp/auth/verify-registration?mlToken=' . $rawToken);

    expect($response->getStatusCode())->toBe(302)
        ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
});

it('fails opaquely on an unknown registration token without creating an account', function() {
    $response = $this->get('/warp/auth/verify-registration?mlToken=never-issued-registration');

    expect($response->getStatusCode())->toBe(302)
        ->and(Craft::$app->getUser()->getIsGuest())->toBeTrue();
});
