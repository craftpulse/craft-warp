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
use craft\helpers\StringHelper;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\models\Token;
use craftpulse\authkit\records\Token as TokenRecord;
use craftpulse\authkit\services\Tokens;
use craftpulse\warp\controllers\AuthController;
use craftpulse\warp\tests\Support\CollectingMailer;
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

beforeEach(function() {
    Craft::$app->getUser()->setIdentity(null);

    // Swap Auth Kit's token store for one wired to a captured mailer so request
    // tests never touch a real transport.
    AuthKit::getInstance()->set('tokens', new Tokens(['mailer' => new CollectingMailer()]));
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

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
