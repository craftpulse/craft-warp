<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\assetbundles\warprequest;

use craft\web\AssetBundle;
use craft\web\View;

/**
 * WarpRequestAsset is the client JavaScript behind the `craft.warp.requestForm`
 * render builder: it keeps the form's hashed `redirect` field in step with the
 * selected channel, so choosing "email me a code" lands on the code-entry page
 * and choosing "email me a link" lands on the check-your-email page.
 *
 * Registered only when the form actually renders a channel choice — a
 * single-channel site posts a fixed redirect and needs no script at all — and
 * suppressible through the `renderJs` setting or a per-render `renderJs: false`.
 * With it off the form still submits and still signs the member in; it just
 * posts the redirect of the channel that was selected when the page rendered.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class WarpRequestAsset extends AssetBundle
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
        $this->sourcePath = '@craftpulse/warp/web/assets/warprequest/';

        $this->js = [
            ['warp-request.js', 'position' => View::POS_END],
        ];

        parent::init();
    }
}
