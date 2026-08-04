<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\assetbundles\warppasskey;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * WarpPasskeyAsset is the client JavaScript behind the
 * `craft.warp.passkeyButton` render builder: the vanilla script that runs Auth
 * Kit's WebAuthn login ceremony from the rendered button, disables it for the
 * duration, announces a failure in the status live region, and swaps the button
 * for the fallback line in a browser that cannot do passkeys.
 *
 * It needs Auth Kit's own WebAuthn client script, which the builder registers
 * from Auth Kit's published URL rather than through a bundle dependency, since
 * Auth Kit publishes that file itself and does not ship a bundle for it.
 *
 * The baseline stylesheet is a separate bundle
 * ([[\craftpulse\warp\assetbundles\warpforms\WarpFormsStyleAsset]]) so styling
 * and behavior can be switched off independently.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class WarpPasskeyAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function init(): void
    {
        $this->sourcePath = '@craftpulse/warp/web/assets/warppasskey/';

        $this->js = [
            ['warp-passkey.js', 'position' => View::POS_END],
        ];

        parent::init();
    }
}
