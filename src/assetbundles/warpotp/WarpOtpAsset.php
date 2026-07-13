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
 * WarpOtpAsset is the front-end client asset behind the `craft.warp.otpInput`
 * and `craft.warp.otpForm` render builders: the vanilla JS that enhances the
 * single code input into one square per digit (auto-advance, backspace, arrow
 * keys, paste distribution) and a small neutral baseline stylesheet for the
 * boxes. No framework dependency, no build-tool assumption on the consumer
 * side; auto-registered by the builders when they render.
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

        $this->css = [
            'warp-otp.css',
        ];

        parent::init();
    }
}
