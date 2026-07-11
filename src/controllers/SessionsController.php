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
use craftpulse\warp\Warp;
use yii\web\Response;

/**
 * SessionsController lets a logged-in front-end user sign out their own active
 * sessions — one device at a time, or everywhere but the current one.
 *
 * Both actions are POST-only, require a login (guests are rejected by the
 * non-anonymous default), and answer either JSON or a redirect-with-flash so the
 * page can post over fetch or a plain form. Killing a session is sensitive, so
 * both pass the recent-auth gate — the passwordless replacement for
 * `requireElevatedSession()` — and a stale session gets the friendly
 * `reauthRequired` envelope the front end keys off, exactly as
 * [[PasskeysController]] does.
 *
 * The controller stays thin: ownership scoping, the authoritative kill, and the
 * registry bookkeeping all live in [[\craftpulse\warp\services\Sessions]], which
 * scopes every delete to the current user so a posted uid can never reach
 * another account's session.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class SessionsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Signs out one of the current user's sessions by its registry uid.
     *
     * @return Response
     * @throws \yii\web\MethodNotAllowedHttpException if the request is not a POST
     * @throws \yii\web\BadRequestHttpException if the uid is missing
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionRevoke(): Response
    {
        $this->requirePostRequest();

        if (($reauth = $this->_guardRecentAuth()) !== null) {
            return $reauth;
        }

        $uid = (string)$this->request->getRequiredBodyParam('uid');

        if (!Warp::$plugin->getSessions()->revoke($this->_currentUser(), $uid)) {
            return $this->asFailure(Craft::t('warp', 'That session could not be found.'))
                ?? $this->asJson(['success' => false]);
        }

        return $this->asSuccess(Craft::t('warp', 'Signed out of that session.'))
            ?? $this->asJson(['success' => true]);
    }

    /**
     * Signs out every session the current user holds except the one making this
     * request.
     *
     * @return Response
     * @throws \yii\web\MethodNotAllowedHttpException if the request is not a POST
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionRevokeOthers(): Response
    {
        $this->requirePostRequest();

        if (($reauth = $this->_guardRecentAuth()) !== null) {
            return $reauth;
        }

        $count = Warp::$plugin->getSessions()->revokeOthers($this->_currentUser());

        return $this->asSuccess(Craft::t('warp', 'Signed out of your other sessions.'), ['count' => $count])
            ?? $this->asJson(['success' => true, 'count' => $count]);
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
            Craft::t('warp', 'Please sign in again to manage your sessions.'),
            ['reauthRequired' => true],
        ) ?? $this->asJson(['reauthRequired' => true]);

        // Override asFailure()'s default 400 — a stale gate is an authorization
        // problem the front end keys off, not a malformed request.
        $response->setStatusCode(403);

        return $response;
    }
}
