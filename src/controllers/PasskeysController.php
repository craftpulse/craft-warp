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
use craft\web\Controller;
use craftpulse\authkit\AuthKit;
use yii\web\Response;

/**
 * PasskeysController lets a logged-in front-end user enroll, list, and remove
 * their own WebAuthn passkeys — the credential-management half of passkeys that
 * core gates behind the CP and a password-based elevated session.
 *
 * Passkey *login* is not handled here: it runs through core's anonymous
 * `auth/passkey-request-options` and `users/login-with-passkey` endpoints, which
 * Warp ships front-end JS and templates for. This controller only covers
 * operations on an authenticated user's own credentials.
 *
 * Every action is JSON, POST-only, requires a login (guests are rejected by the
 * non-anonymous default), and passes the recent-auth gate — the passwordless
 * replacement for `requireElevatedSession()`. The gate is checked here so a
 * stale session returns the friendly `reauthRequired` JSON the front end keys
 * off; Auth Kit's service enforces it again on the credential-changing calls, so
 * the boundary holds even if this guard were ever removed.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class PasskeysController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Returns serialized passkey creation options for the current user.
     *
     * @return Response
     * @throws \yii\web\MethodNotAllowedHttpException if the request is not a POST
     * @throws \yii\web\BadRequestHttpException if the request does not accept JSON
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionCreationOptions(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (($reauth = $this->_guardRecentAuth()) !== null) {
            return $reauth;
        }

        $options = AuthKit::$plugin->getPasskeys()->getCreationOptions($this->_currentUser());

        return $this->asJson(['options' => $options]);
    }

    /**
     * Deletes one of the current user's passkeys.
     *
     * @return Response
     * @throws \yii\web\MethodNotAllowedHttpException if the request is not a POST
     * @throws \yii\web\BadRequestHttpException if the request does not accept JSON or omits the uid
     * @throws \yii\web\ForbiddenHttpException if the recent-auth gate is stale when the service re-checks it
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (($reauth = $this->_guardRecentAuth()) !== null) {
            return $reauth;
        }

        $uid = (string)$this->request->getRequiredBodyParam('uid');
        AuthKit::$plugin->getPasskeys()->deletePasskey($this->_currentUser(), $uid);

        return $this->asSuccess(Craft::t('warp', 'Passkey deleted.'))
            ?? $this->asJson(['success' => true]);
    }

    /**
     * Verifies a passkey creation response from the authenticator and stores the
     * new credential.
     *
     * @return Response
     * @throws \yii\web\MethodNotAllowedHttpException if the request is not a POST
     * @throws \yii\web\BadRequestHttpException if the request does not accept JSON or omits the credentials
     * @throws \yii\web\ForbiddenHttpException if the recent-auth gate is stale when the service re-checks it
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionVerifyCreation(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (($reauth = $this->_guardRecentAuth()) !== null) {
            return $reauth;
        }

        $credentials = (string)$this->request->getRequiredBodyParam('credentials');
        $credentialName = $this->request->getBodyParam('credentialName');
        $credentialName = is_string($credentialName) ? $credentialName : null;

        if (!AuthKit::$plugin->getPasskeys()->verifyCreation($credentials, $credentialName)) {
            return $this->asFailure(Craft::t('warp', 'Passkey creation failed.'))
                ?? $this->asJson(['success' => false]);
        }

        return $this->asSuccess(Craft::t('warp', 'Passkey created.'))
            ?? $this->asJson(['success' => true]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the logged-in user. The non-anonymous default guarantees this is
     * never a guest by the time an action runs.
     *
     * @return User
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _currentUser(): User
    {
        $user = Craft::$app->getUser()->getIdentity();
        assert($user instanceof User);

        return $user;
    }

    /**
     * Returns a re-authentication response when the recent-auth gate is not
     * satisfied, or null when it is. Passwordless users re-authenticate by
     * logging in again — the front end keys off `reauthRequired`.
     *
     * @return Response|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _guardRecentAuth(): ?Response
    {
        if (AuthKit::$plugin->getPasskeys()->hasRecentAuth()) {
            return null;
        }

        $response = $this->asFailure(
            Craft::t('warp', 'Please sign in again to manage your passkeys.'),
            ['reauthRequired' => true],
        ) ?? $this->asJson(['reauthRequired' => true]);

        // Override asFailure()'s default 400 — a stale gate is an authorization
        // problem the front end keys off, not a malformed request.
        $response->setStatusCode(403);

        return $response;
    }
}
