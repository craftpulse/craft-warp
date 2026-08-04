<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Behaviour tests for the shipped example bundle, rendered as real HTTP requests
 * against a freshly installed copy. The bundle is documentation people copy and
 * walk, so its journeys are pinned here rather than only eyeballed: a cold visit
 * to the code-entry page (nobody emailed this visitor) belongs on the request
 * form, while a visitor whose address is carried in the session gets the page.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\FileHelper;
use craftpulse\warp\console\controllers\ExampleTemplatesController;
use craftpulse\warp\controllers\AuthController;
use yii\console\ExitCode;

const BUNDLE_FOLDER = 'warp-tpl-test';

function installBundle(): void
{
    $controller = new ExampleTemplatesController('example-templates', Craft::$app);
    $controller->interactive = false;
    $controller->folderName = BUNDLE_FOLDER;
    $controller->overwrite = true;

    if ($controller->runAction('install') !== ExitCode::OK) {
        throw new RuntimeException('Could not install the example bundle for the template tests.');
    }
}

beforeEach(function() {
    installBundle();
    Craft::$app->getSession()->remove(AuthController::SESSION_REQUESTED_EMAIL);
});

afterEach(function() {
    Craft::$app->getSession()->remove(AuthController::SESSION_REQUESTED_EMAIL);

    $path = Craft::$app->getPath()->getSiteTemplatesPath() . DIRECTORY_SEPARATOR . BUNDLE_FOLDER;

    if (is_dir($path)) {
        FileHelper::removeDirectory($path);
    }
});

it('sends a cold visitor from the code-entry page to the request form', function() {
    // No session-carried address: nobody emailed this visitor a code, so "we
    // emailed you a code" plus a bare email field would be incoherent. The
    // exception handling is on because the page's {% redirect %} ends the request.
    $response = $this->withExceptionHandling()->get('/' . BUNDLE_FOLDER . '/otp-verify');

    expect($response->getStatusCode())->toBe(302)
        ->and((string)$response->getHeaders()->get('location'))->toContain(BUNDLE_FOLDER . '/login');
});

it('renders the code-entry page for a visitor whose address is carried in the session', function() {
    Craft::$app->getSession()->set(AuthController::SESSION_REQUESTED_EMAIL, 'member@warp-test.example');

    $response = $this->get('/' . BUNDLE_FOLDER . '/otp-verify');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->content)->toContain('member@warp-test.example')
        ->and($response->content)->toContain('warp/auth/verify-code');
});

it('renders the sign-in email input at full width, lifting the baseline max-width cap', function() {
    // Warp's baseline caps the email input at 24rem inside its cascade layer, and
    // w-full sets width, not max-width, so the cap survives it and the input stops
    // short of the full-width submit button. The bundle passes max-w-none for that.
    $response = $this->get('/' . BUNDLE_FOLDER . '/login');

    expect($response->getStatusCode())->toBe(200);

    preg_match('/<input[^>]+name="email"[^>]*>/', (string)$response->content, $matches);

    expect($matches[0] ?? '')->toContain('warp-request-form__email')
        ->and($matches[0] ?? '')->toContain('max-w-none');
});

it('keeps the code-entry page reachable after a wrong code, never looping back to login', function() {
    // A failed verify redirects back to the referring page and leaves the carried
    // address in place (only a successful verify clears it), so the retry must
    // land on the sent-to variant of the page, not in the cold-visit redirect.
    Craft::$app->getSession()->set(AuthController::SESSION_REQUESTED_EMAIL, 'member@warp-test.example');

    $this->post('/warp/auth/verify-code', ['email' => 'member@warp-test.example', 'code' => '000000']);

    $response = $this->get('/' . BUNDLE_FOLDER . '/otp-verify');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->content)->toContain('member@warp-test.example');
});
