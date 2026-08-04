<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\twig\tags;

use Craft;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Request;
use craft\web\View;
use craftpulse\authkit\variables\AuthKitVariable;
use craftpulse\warp\assetbundles\warppasskey\WarpPasskeyAsset;
use craftpulse\warp\helpers\Redirect;
use Throwable;

/**
 * PasskeyButtonTag is the fluent builder for the passkey sign-in section,
 * rendered via `craft.warp.passkeyButton({...}).render()` — the whole optional
 * affordance a sign-in page offers a member who already enrolled a passkey: the
 * button that starts the WebAuthn ceremony, the line that replaces it in a
 * browser that cannot do passkeys, the live region a failure is announced in,
 * and Craft's CSRF field for the two core endpoints the ceremony posts to
 * (`auth/passkey-request-options` and `users/login-with-passkey`).
 *
 * It is progressive enhancement, not a form: the ceremony runs in the browser,
 * so the button is only ever an addition to the email form beside it, never a
 * replacement. With no JavaScript nothing here does anything, which is why the
 * fallback line and the email form both stay on the page.
 *
 * Every element it emits is addressable through an `*Attrs` option and every
 * string through a copy option, so the section can wear a site's own design
 * system without being rewritten. The one element it does not expose is Craft's
 * own CSRF field, whose markup Craft owns (and replaces wholesale when
 * `asyncCsrfInputs` is on).
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class PasskeyButtonTag extends BaseTag
{
    // Public Methods
    // =========================================================================

    /**
     * Merges attributes into the container `<div>`. Your classes accumulate onto
     * Warp's rather than replacing them; pass `resetClass: true` in the same
     * array to own the `class` outright.
     *
     * Its `data` attributes are the contract the client script reads, and a
     * `data` array merges key by key, so adding one of your own cannot take
     * `data-warp-passkey` with it and silently disable the button.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function attrs(array $attrs): self
    {
        $this->config['attrs'] = $attrs;
        return $this;
    }

    /**
     * Merges attributes into the `<button>`.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function buttonAttrs(array $attrs): self
    {
        $this->config['buttonAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the message announced when the member cancels the browser's passkey
     * prompt or lets it time out. Default: "Passkey sign-in was cancelled or
     * timed out. Try again, or use your email above instead."
     *
     * @param string $text
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function cancelledText(string $text): self
    {
        $this->config['cancelledText'] = $text;
        return $this;
    }

    /**
     * Sets the message announced when the ceremony fails for any other reason
     * and the failure carries no message of its own. Default: "Passkey sign-in
     * failed. Please try your email instead."
     *
     * @param string $text
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function failedText(string $text): self
    {
        $this->config['failedText'] = $text;
        return $this;
    }

    /**
     * Merges attributes into the fallback `<p>`, the line that replaces the
     * button in a browser without passkey support.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function fallbackAttrs(array $attrs): self
    {
        $this->config['fallbackAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the fallback line's text. Default: "Passkeys are not available in
     * this browser. Use your email above instead."
     *
     * @param string $text
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function fallbackText(string $text): self
    {
        $this->config['fallbackText'] = $text;
        return $this;
    }

    /**
     * Sets the button label. Default: "Sign in with a passkey".
     *
     * @param string $label
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function label(string $label): self
    {
        $this->config['label'] = $label;
        return $this;
    }

    /**
     * Sets where a completed ceremony lands the member. Validated exactly as the
     * endpoints validate a posted `returnUrl` — it must belong to the site the
     * request was made against — because the client script assigns it to
     * `window.location.href`, so a scheme of any kind must never reach it.
     * Anything refused is logged and replaced with the site root. Default: the
     * site root.
     *
     * @param string $url
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function returnUrl(string $url): self
    {
        $this->config['returnUrl'] = $url;
        return $this;
    }

    /**
     * Merges attributes into the status `<p>`, the live region a failure is
     * announced in. It carries `role="alert"` and `tabindex="-1"` so the message
     * is announced and can take focus; replace those only with equivalents.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function statusAttrs(array $attrs): self
    {
        $this->config['statusAttrs'] = $attrs;
        return $this;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function _renderHtml(): string
    {
        $this->_registerStyleAsset();
        $this->_registerWebauthnJs();
        $this->_registerAsset(WarpPasskeyAsset::class);

        $html = Html::beginTag('div', $this->_containerAttrs());
        $html .= $this->_csrfInputHtml();

        $html .= Html::button(
            Html::encode($this->config['label'] ?? Craft::t('warp', 'Sign in with a passkey')),
            $this->_mergeAttrs([
                'type' => 'button',
                'class' => 'warp-passkey__button',
                'data' => ['warp-passkey-button' => true],
            ], $this->config['buttonAttrs'] ?? []),
        );

        // Both live regions must be in the DOM before anything is written into
        // them, so they render hidden rather than being created on demand.
        $html .= Html::tag(
            'p',
            Html::encode($this->config['fallbackText'] ?? Craft::t('warp', 'Passkeys are not available in this browser. Use your email above instead.')),
            $this->_mergeAttrs([
                'class' => 'warp-passkey__fallback',
                'hidden' => true,
                'data' => ['warp-passkey-fallback' => true],
            ], $this->config['fallbackAttrs'] ?? []),
        );

        $html .= Html::tag('p', '', $this->_mergeAttrs([
            'class' => 'warp-passkey__status',
            'role' => 'alert',
            'tabindex' => '-1',
            'hidden' => true,
            'data' => ['warp-passkey-status' => true],
        ], $this->config['statusAttrs'] ?? []));

        return $html . Html::endTag('div');
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the resolved attributes for the container, whose `data` attributes
     * carry everything the client script needs: the marker it selects on, the
     * two core endpoint URLs, the validated return URL, the name of the CSRF
     * field to read, and the two failure messages.
     *
     * @return array<string, mixed>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _containerAttrs(): array
    {
        return $this->_mergeAttrs([
            'class' => 'warp-passkey',
            'data' => [
                'warp-passkey' => true,
                'warp-passkey-options-url' => UrlHelper::actionUrl('auth/passkey-request-options'),
                'warp-passkey-login-url' => UrlHelper::actionUrl('users/login-with-passkey'),
                'warp-passkey-return-url' => $this->_returnUrl(),
                'warp-passkey-csrf-field' => $this->_csrfField(),
                'warp-passkey-cancelled-text' => $this->config['cancelledText']
                    ?? Craft::t('warp', 'Passkey sign-in was cancelled or timed out. Try again, or use your email above instead.'),
                'warp-passkey-failed-text' => $this->config['failedText']
                    ?? Craft::t('warp', 'Passkey sign-in failed. Please try your email instead.'),
            ],
        ], $this->config['attrs'] ?? []);
    }

    /**
     * Returns the name of the request's CSRF field, or null outside a web
     * request, in which case no CSRF field is rendered either.
     *
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _csrfField(): ?string
    {
        $request = Craft::$app->getRequest();

        return $request instanceof Request ? $request->csrfParam : null;
    }

    /**
     * Renders Craft's own CSRF field inside the container, so the client script
     * finds it scoped to this render rather than hunting the page for one.
     *
     * It goes through [[\craft\helpers\Html::csrfInput()]] rather than writing
     * the token into a data attribute, so a statically cached page still posts a
     * fresh token: with `asyncCsrfInputs` on, Craft renders a placeholder and
     * fills it from its own endpoint, which is why the script reads the field by
     * name at click time instead of at load.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _csrfInputHtml(): string
    {
        if ($this->_csrfField() === null) {
            return '';
        }

        return Html::csrfInput();
    }

    /**
     * Registers Auth Kit's WebAuthn client script, which owns the ceremony
     * [[WarpPasskeyAsset]]'s script drives. It is registered from Auth Kit's own
     * published URL rather than as a bundle dependency, because Auth Kit
     * publishes that file itself and ships no bundle for it — the same URL
     * `craft.warp.webauthnJsUrl` hands a template that wires the ceremony by
     * hand.
     *
     * Registered before the behavior bundle, so the ceremony's own script tag
     * comes first, and failure-swallowing like every other registration here: a
     * published-asset problem must never take a page down.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerWebauthnJs(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        try {
            $url = (new AuthKitVariable())->webauthnJsUrl();

            if ($url === '') {
                return;
            }

            Craft::$app->getView()->registerJsFile($url, ['position' => View::POS_END]);
        } catch (Throwable) {
            // Defensive — never let asset registration break Twig rendering.
        }
    }

    /**
     * Returns the URL a completed ceremony lands the member on: the given one
     * when it survives the same validation the endpoints apply to a posted
     * `returnUrl`, the site root otherwise.
     *
     * A refused URL is logged rather than silently swapped, since the member
     * lands somewhere other than the page asked for.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _returnUrl(): string
    {
        $requested = $this->config['returnUrl'] ?? null;

        if ($requested === null || $requested === '') {
            return UrlHelper::siteUrl();
        }

        $validated = Redirect::validateReturnUrl((string)$requested);

        if ($validated !== null) {
            return $validated;
        }

        Craft::warning(
            sprintf('A Warp passkey button was rendered with returnUrl="%s", which does not belong to this site. It will land on the site root instead.', $requested),
            __METHOD__,
        );

        return UrlHelper::siteUrl();
    }
}
