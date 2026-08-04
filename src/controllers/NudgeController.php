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

use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftpulse\warp\Warp;
use yii\web\Response;

/**
 * NudgeController owns the one write the passkey-enrollment nudge has: a member
 * answering "not now", which clears the nudge for the rest of their session.
 *
 * It is deliberately its own controller rather than an action on
 * [[PasskeysController]]: nothing here touches a credential, so it carries none
 * of that controller's contract (JSON only, and the recent-auth step-up). A
 * member declining a suggestion must never be asked to re-authenticate first.
 *
 * The action is POST-only and requires a login (guests are rejected by the
 * non-anonymous default), and it answers either JSON or a redirect back, so the
 * nudge can be dismissed over fetch or a plain form post with no JavaScript at
 * all. It sets no flash: a dismissal the member just asked for needs no
 * confirming, and the nudge's disappearance is the feedback.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class NudgeController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Clears the passkey-enrollment nudge for the rest of the session.
     *
     * @return Response
     * @throws \yii\web\MethodNotAllowedHttpException if the request is not a POST
     * @throws \yii\web\BadRequestHttpException if a posted redirect cannot be validated
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionDismiss(): Response
    {
        $this->requirePostRequest();

        Warp::$plugin->getPasswordless()->dismissPasskeyNudge();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        // A plain form post lands the member back where they were: the page that
        // carried the nudge, which now renders without it.
        return $this->redirectToPostedUrl(null, $this->request->getReferrer() ?? UrlHelper::siteUrl());
    }
}
