<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\assetbundles\warpforms;

use craft\web\AssetBundle;

/**
 * WarpFormsStyleAsset is the neutral baseline stylesheet behind Warp's front-end
 * render builders — the segmented code input's boxes plus the shared field,
 * label, hint, email-input and submit-button furniture of the request and verify
 * forms. Deliberately low-specificity so a site's own rules can take over, and
 * suppressible outright through the `renderCss` setting or a per-render
 * `renderCss: false`.
 *
 * Registered separately from [[\craftpulse\warp\assetbundles\warpotp\WarpOtpAsset]]
 * so the styling and the client behavior can be switched off independently.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class WarpFormsStyleAsset extends AssetBundle
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
        $this->sourcePath = '@craftpulse/warp/web/assets/warpforms/';

        $this->css = [
            'warp-forms.css',
        ];

        parent::init();
    }
}
