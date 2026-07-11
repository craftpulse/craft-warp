<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\variables;

use Craft;
use craftpulse\warp\services\Passwordless;

/**
 * WarpVariable is the `craft.warp` Twig variable — the single front-end handle
 * onto Warp's passwordless surface: passkey state for a management UI (delegated
 * to Auth Kit), the reference WebAuthn client URL, whether registration is open,
 * the enabled login methods, and the show-once passkey-enrollment nudge.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class WarpVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Returns whether the passkey-enrollment nudge should be shown, clearing the
     * flag as it reads it so the nudge surfaces exactly once per triggering
     * login. Set by [[Passwordless::loginUser()]] after an email-flow login by a
     * user who holds no passkey.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getShowPasskeyNudge(): bool
    {
        $session = $this->_session();
        $show = (bool)$session->get(Passwordless::SESSION_PASSKEY_NUDGE_KEY);
        $session->remove(Passwordless::SESSION_PASSKEY_NUDGE_KEY);

        return $show;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns Craft's session component, narrowed for static analysis — the
     * variable only ever renders on web requests.
     *
     * @return \craft\web\Session
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _session(): \craft\web\Session
    {
        /** @var \craft\web\Application $app */
        $app = Craft::$app;

        return $app->getSession();
    }
}
