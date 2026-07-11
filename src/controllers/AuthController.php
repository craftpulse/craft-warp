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
use craft\filters\IpRateLimitIdentity;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Request;
use craftpulse\authkit\AuthKit;
use craftpulse\authkit\services\Tokens;
use craftpulse\warp\helpers\Redirect;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use yii\filters\RateLimiter;
use yii\web\Response;

/**
 * AuthController owns Warp's front-end passwordless login endpoints for existing
 * users: request a credential (anonymous, per-IP rate-limited, enumeration-safe)
 * and verify one — a magic link (GET, single-use) or an OTP code (POST,
 * attempt-capped).
 *
 * Every endpoint is deliberately opaque. [[actionRequest()]] responds
 * identically whether or not the address belongs to an account; both verify
 * actions log any failure server-side and surface only a generic message — a
 * verbose error on an anonymous endpoint is a probing surface.
 *
 * [[actionRequest()]] is structured for Phase 3: registration branches from the
 * same posted email form without changing the response.
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

    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['request', 'verify-link', 'verify-code'];

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

        // Existing-user login credential. Phase 3 branches from here to
        // registration when the address maps to no account; the response below
        // stays identical so neither branch is distinguishable.
        Warp::$plugin->getPasswordless()->request($email, $channel, $returnUrl);

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
        $user = AuthKit::$plugin->getTokens()->consumeMagicLink($token);

        if ($user === null) {
            Craft::warning('A magic-link verification failed or was reused.', __METHOD__);
            $this->setFailFlash(Craft::t('warp', 'This sign-in link is invalid or has expired. Please request a new one.'));

            return $this->redirect(UrlHelper::siteUrl());
        }

        if (!Warp::$plugin->getPasswordless()->loginUser($user)) {
            Craft::error('Craft refused the magic-link session login.', __METHOD__);
            $this->setFailFlash(Craft::t('warp', 'Sign-in failed. Please try again.'));

            return $this->redirect(UrlHelper::siteUrl());
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

        $user = AuthKit::$plugin->getTokens()->consumeOtp($email, $code);

        if ($user === null) {
            Craft::warning('An OTP verification failed or was reused.', __METHOD__);

            return $this->_codeFailureResponse();
        }

        if (!Warp::$plugin->getPasswordless()->loginUser($user)) {
            Craft::error('Craft refused the OTP session login.', __METHOD__);

            return $this->_codeFailureResponse();
        }

        $returnUrl = $this->_returnUrl($this->request->getBodyParam('returnUrl')) ?? UrlHelper::siteUrl();

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess(Craft::t('warp', 'Signed in.'), ['returnUrl' => $returnUrl])
                ?? $this->asJson(['success' => true]);
        }

        return $this->redirect($returnUrl);
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

        return $this->redirect($this->request->getReferrer() ?? UrlHelper::siteUrl());
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
