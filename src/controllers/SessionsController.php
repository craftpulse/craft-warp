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
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftpulse\warp\Warp;
use yii\web\Response;

/**
 * SessionsController lets a logged-in front-end user sign out their own active
 * sessions — one device at a time, or everywhere but the current one.
 *
 * Both actions are POST-only, require a login (guests are rejected by the
 * non-anonymous default), and answer either JSON or a redirect-with-flash so the
 * page can post over fetch or a plain form. Unlike passkey management, session
 * revocation is deliberately NOT behind the recent-auth gate: it is a defensive,
 * reversible action (the worst outcome is a sign-out), and a member spotting a
 * suspicious device must be able to kill it immediately, however old their own
 * session is. Credential management keeps the step-up; cleanup does not.
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

        $uid = (string)$this->request->getRequiredBodyParam('uid');

        if (!Warp::$plugin->getSessions()->revoke($this->_currentUser(), $uid)) {
            $message = Craft::t('warp', 'That session could not be found.');

            if ($this->request->getAcceptsJson()) {
                return $this->asFailure($message) ?? $this->asJson(['success' => false]);
            }

            // asFailure() only queues the flash and returns null for a non-JSON
            // request, so a plain form POST needs its own redirect back to
            // surface it — the controller promises a redirect-with-flash there.
            $this->setFailFlash($message);

            return $this->redirect($this->request->getReferrer() ?? UrlHelper::siteUrl());
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
}
