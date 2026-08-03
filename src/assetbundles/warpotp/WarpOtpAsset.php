<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\assetbundles\warpotp;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * WarpOtpAsset is the client JavaScript behind the `craft.warp.otpInput` and
 * `craft.warp.otpForm` render builders: the vanilla script that enhances the
 * single code input into one square per digit (auto-advance, backspace, arrow
 * keys, paste distribution). No framework dependency and no build-tool
 * assumption on the consumer side; auto-registered by the builders when they
 * render, and suppressible through the `renderJs` setting or a per-render
 * `renderJs: false`.
 *
 * The baseline stylesheet is a separate bundle
 * ([[\craftpulse\warp\assetbundles\warpforms\WarpFormsStyleAsset]]) so styling
 * and behavior can be switched off independently.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class WarpOtpAsset extends AssetBundle
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
        $this->sourcePath = '@craftpulse/warp/web/assets/warpotp/';

        $this->js = [
            ['warp-otp.js', 'position' => View::POS_END],
        ];

        parent::init();
    }
}
