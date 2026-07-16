<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\controllers;

use Craft;
use craft\elements\User;
use craft\filters\IpRateLimitIdentity;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Request;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\services\Tokens;
use craftpulse\warp\helpers\Redirect;
use craftpulse\warp\models\Login;
use craftpulse\warp\models\Settings;
use craftpulse\warp\services\Passwordless;
use craftpulse\warp\Warp;
use yii\filters\RateLimiter;
use yii\web\Response;

/**
 * AuthController owns Warp's front-end passwordless login endpoints for existing
 * users: request a credential (anonymous, per-IP rate-limited, enumeration-safe)
 * and verify one — a magic link (GET, single-use) or an OTP code (POST,
 * attempt-capped).
 *
 * The same posted email form drives login and registration: [[actionRequest()]]
 * resolves the address and either issues a login credential for an already-active
 * account or, when registration is open, a registration link for an address
 * without one — an unknown address, or a pending account that can still finish
 * activating through the link — responding byte-identically in every branch so
 * the endpoint never reveals which addresses are registered. [[actionVerifyRegistration()]] closes the
 * signup loop, mirroring [[actionVerifyLink()]]'s opaque, generic-failure shape.
 *
 * Every endpoint is deliberately opaque. All three verify actions log any
 * failure server-side and surface only a generic message — a verbose error on
 * an anonymous endpoint is a probing surface.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class AuthController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var int The maximum number of request-credential posts allowed per IP
     * within [[REQUEST_RATE_LIMIT_WINDOW]] seconds.
     *
     * @since 5.0.0
     */
    public const REQUEST_RATE_LIMIT = 5;

    /**
     * @var int The per-IP rate-limit window for request-credential posts, in
     * seconds.
     *
     * @since 5.0.0
     */
    public const REQUEST_RATE_LIMIT_WINDOW = 60;

    /**
     * @var int The maximum number of OTP verify posts allowed per IP within
     * [[VERIFY_CODE_RATE_LIMIT_WINDOW]] seconds. Compounds with the per-code
     * attempt cap Auth Kit enforces.
     *
     * @since 5.0.0
     */
    public const VERIFY_CODE_RATE_LIMIT = 10;

    /**
     * @var int The per-IP rate-limit window for OTP verify posts, in seconds.
     *
     * @since 5.0.0
     */
    public const VERIFY_CODE_RATE_LIMIT_WINDOW = 60;

    /**
     * @var string The session key holding the address the visitor last
     * requested a credential for, so the code-entry page can prefill it
     * instead of asking twice. It only ever echoes the visitor's own input
     * back to them, and is cleared on a successful code verify.
     *
     * @since 5.0.0
     */
    public const SESSION_REQUESTED_EMAIL = 'warp:requestedEmail';

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['request', 'verify-link', 'verify-code', 'verify-registration'];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<string, mixed>
     */
    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            'requestRateLimiter' => [
                'class' => RateLimiter::class,
                'only' => ['request'],
                'enableRateLimitHeaders' => false,
                'user' => fn(): IpRateLimitIdentity => new IpRateLimitIdentity([
                    'limit' => self::REQUEST_RATE_LIMIT,
                    'window' => self::REQUEST_RATE_LIMIT_WINDOW,
                    'keyPrefix' => 'warp-auth-request',
                    'ip' => $this->_userIp(),
                ]),
            ],
            'verifyCodeRateLimiter' => [
                'class' => RateLimiter::class,
                'only' => ['verify-code'],
                'enableRateLimitHeaders' => false,
                'user' => fn(): IpRateLimitIdentity => new IpRateLimitIdentity([
                    'limit' => self::VERIFY_CODE_RATE_LIMIT,
                    'window' => self::VERIFY_CODE_RATE_LIMIT_WINDOW,
                    'keyPrefix' => 'warp-auth-verify-code',
                    'ip' => $this->_userIp(),
                ]),
            ],
        ]);
    }

    /**
     * Requests a passwordless login credential for the posted email address
     * over the chosen channel.
     *
     * The response is identical whether or not an account matches, so the
     * endpoint never reveals which addresses are registered.
     *
     * @return Response
     * @throws \yii\web\MethodNotAllowedHttpException if the request is not a POST
     * @throws \yii\web\BadRequestHttpException if the request cannot be fulfilled
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionRequest(): Response
    {
        $this->requirePostRequest();

        $email = (string)$this->request->getBodyParam('email', '');
        $channel = $this->_resolveChannel((string)$this->request->getBodyParam('channel', ''));
        $returnUrl = $this->_returnUrl($this->request->getBodyParam('returnUrl'));

        // Remember the visitor's own typed address so the code-entry page can
        // prefill it instead of asking twice. Set unconditionally, before any
        // branch, so the write cost is identical whether or not an account
        // matches and the response stays enumeration-safe.
        Craft::$app->getSession()->set(self::SESSION_REQUESTED_EMAIL, $email);

        // One form, two outcomes, one response. An address with no active
        // account — unknown or pending — takes the registration path when signup
        // is open, so a pending holder can finish activating through the signup
        // link (Auth Kit issues for an unknown or pending address and refuses,
        // equalized, a suspended or locked one). An already-active account, or
        // any address with registration closed, takes the login path, whose
        // timing equalizer covers its send-nothing branch. Both live paths do
        // the same token-write + email work, so no branch is distinguishable.
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);
        $isActive = $user !== null && $user->getStatus() === User::STATUS_ACTIVE;

        if (!$isActive && Warp::$plugin->getRegistration()->isEnabled()) {
            AuthKit::$plugin->getTokens()->issueRegistration($email, $returnUrl, [
                'origin' => Passwordless::TOKEN_ORIGIN,
                'route' => 'warp/auth/verify-registration',
                'ttl' => $this->_settings()->getTokenTtl(),
                'perEmailLimit' => $this->_settings()->getPerEmailLimit(),
                'perEmailWindow' => $this->_settings()->getPerEmailWindow(),
            ]);
        } else {
            Warp::$plugin->getPasswordless()->request($email, $channel, $returnUrl);
        }

        $message = Craft::t('warp', 'If an account matches that address, a sign-in message is on its way.');

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess($message) ?? $this->asJson(['success' => true]);
        }

        $this->setSuccessFlash($message);

        return $this->redirectToPostedUrl();
    }

    /**
     * Consumes a magic-link token and logs the user in.
     *
     * @return Response
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionVerifyLink(): Response
    {
        $token = (string)$this->request->getQueryParam(Tokens::TOKEN_PARAM, '');
        $user = AuthKit::$plugin->getTokens()->consumeMagicLink($token, Passwordless::TOKEN_ORIGIN);

        if ($user === null) {
            Craft::warning('A magic-link verification failed or was reused.', __METHOD__);
            $this->setFailFlash(Craft::t('warp', 'This sign-in link is invalid or has expired. Please request a new one.'));

            return $this->redirect($this->_failureLandingUrl());
        }

        if (!Warp::$plugin->getPasswordless()->loginUser($user, Login::METHOD_MAGIC_LINK)) {
            Craft::error('Craft refused the magic-link session login.', __METHOD__);
            $this->setFailFlash(Craft::t('warp', 'Sign-in failed. Please try again.'));

            return $this->redirect($this->_failureLandingUrl());
        }

        $returnUrl = $this->_returnUrl($this->request->getQueryParam('returnUrl'));

        return $this->redirect($returnUrl ?? UrlHelper::siteUrl());
    }

    /**
     * Consumes an OTP code for the posted email address and logs the user in.
     *
     * @return Response
     * @throws \yii\web\MethodNotAllowedHttpException if the request is not a POST
     * @throws \yii\web\BadRequestHttpException if the request cannot be fulfilled
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionVerifyCode(): Response
    {
        $this->requirePostRequest();

        $email = (string)$this->request->getBodyParam('email', '');
        $code = (string)$this->request->getBodyParam('code', '');

        $user = AuthKit::$plugin->getTokens()->consumeOtp($email, $code, Passwordless::TOKEN_ORIGIN);

        if ($user === null) {
            Craft::warning('An OTP verification failed or was reused.', __METHOD__);

            return $this->_codeFailureResponse();
        }

        if (!Warp::$plugin->getPasswordless()->loginUser($user, Login::METHOD_OTP)) {
            Craft::error('Craft refused the OTP session login.', __METHOD__);

            return $this->_codeFailureResponse();
        }

        Craft::$app->getSession()->remove(self::SESSION_REQUESTED_EMAIL);

        $returnUrl = $this->_returnUrl($this->request->getBodyParam('returnUrl')) ?? UrlHelper::siteUrl();

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('warp', 'Signed in.'), ['returnUrl' => $returnUrl])
                ?? $this->asJson(['success' => true]);
        }

        return $this->redirect($returnUrl);
    }

    /**
     * Consumes a registration token, provisions or resolves the account its
     * payload names, and logs that user in.
     *
     * Mirrors [[actionVerifyLink()]]: the token (a 32-byte secret) authorizes,
     * and any failure — an unknown, expired, or reused token, or an account
     * state that must fail closed — collapses to a single generic flash, its
     * cause logged server-side only.
     *
     * @return Response
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionVerifyRegistration(): Response
    {
        $token = (string)$this->request->getQueryParam(Tokens::TOKEN_PARAM, '');
        $consumed = AuthKit::$plugin->getTokens()->consumeRegistration($token, Passwordless::TOKEN_ORIGIN);

        if ($consumed === null) {
            return $this->_registrationFailureResponse('A registration verification failed or was reused.');
        }

        $user = Warp::$plugin->getRegistration()->fulfill($consumed);

        if ($user === null) {
            return $this->_registrationFailureResponse('A registration token could not be fulfilled into a usable account.');
        }

        if (!Warp::$plugin->getPasswordless()->loginUser($user, Login::METHOD_REGISTER)) {
            return $this->_registrationFailureResponse('Craft refused the registration session login.');
        }

        $returnUrl = $this->_returnUrl($this->request->getQueryParam('returnUrl'));

        return $this->redirect($returnUrl ?? UrlHelper::siteUrl());
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the generic OTP-verification failure response, opaque to whether
     * the code, the address, or the session login was the point of failure.
     *
     * @return Response
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _codeFailureResponse(): Response
    {
        $message = Craft::t('warp', 'That code is invalid or has expired. Please request a new one.');

        if ($this->request->getAcceptsJson()) {
            return $this->asFailure($message) ?? $this->asJson(['success' => false]);
        }

        $this->setFailFlash($message);

        return $this->redirect($this->request->getReferrer() ?? $this->_failureLandingUrl());
    }

    /**
     * Builds the generic registration-verification failure response, opaque to
     * whether the token, the account state, or the session login was the point
     * of failure. The specific cause is logged, never surfaced — it mirrors
     * [[actionVerifyLink()]]'s single generic flash.
     *
     * @param string $logMessage the internal detail written to the warning log
     * @return Response
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registrationFailureResponse(string $logMessage): Response
    {
        Craft::warning($logMessage, __METHOD__);
        $this->setFailFlash(Craft::t('warp', 'This sign-in link is invalid or has expired. Please request a new one.'));

        return $this->redirect($this->_failureLandingUrl());
    }

    /**
     * Resolves the front-end page a failed verification redirects to: the
     * site's `loginPath` general config value when it resolves to a path, or
     * the site root when login paths are disabled (`loginPath: false` or
     * headless installs). A failed link must land somewhere the fail flash
     * actually renders and a fresh credential can be requested, and the login
     * page is the one URL core already asks every site to name.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _failureLandingUrl(): string
    {
        $loginPath = Craft::$app->getConfig()->getGeneral()->getLoginPath();

        if (!is_string($loginPath) || $loginPath === '') {
            return UrlHelper::siteUrl();
        }

        return UrlHelper::siteUrl($loginPath);
    }

    /**
     * Resolves the login channel for a request, defaulting an unspecified
     * channel to the first enabled login method. An unsupported or disabled
     * channel is refused downstream by [[\craftpulse\warp\services\Passwordless]].
     *
     * @param string $requested the posted channel, possibly empty
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    /**
     * Returns Warp's settings, narrowed for static analysis.
     *
     * @return Settings
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _settings(): Settings
    {
        $settings = Warp::$plugin->getSettings();
        assert($settings instanceof Settings);

        return $settings;
    }

    private function _resolveChannel(string $requested): string
    {
        if ($requested !== '') {
            return $requested;
        }

        $settings = Warp::$plugin->getSettings();
        assert($settings instanceof Settings);

        return $settings->loginMethods[0] ?? Settings::CHANNEL_MAGIC_LINK;
    }

    /**
     * Validates a raw return-URL parameter as same-site, returning it when safe
     * or null when absent, non-string, or an open-redirect attempt.
     *
     * @param mixed $param the raw query or body parameter
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _returnUrl(mixed $param): ?string
    {
        return Redirect::validateReturnUrl(is_string($param) ? $param : null);
    }

    /**
     * Returns the requesting IP for rate-limit identity, falling back to a
     * fixed bucket when it cannot be determined.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _userIp(): string
    {
        $request = Craft::$app->getRequest();
        $ip = $request instanceof Request ? $request->getUserIP() : null;

        return $ip ?? 'unknown';
    }
}
