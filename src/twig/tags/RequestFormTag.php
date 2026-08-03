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
use craftpulse\warp\assetbundles\warprequest\WarpRequestAsset;
use craftpulse\warp\models\Settings;

/**
 * RequestFormTag is the fluent builder for the unified sign-in and sign-up
 * request form, rendered via `craft.warp.requestForm({...}).render()` — the one
 * email field every visitor starts at, and the busiest form Warp has: the post
 * to `warp/auth/request` with CSRF, the labelled email input, the channel choice
 * when the site offers both a magic link and a one-time code, and the submit
 * button.
 *
 * One address drives both outcomes. The endpoint resolves it server-side and
 * either emails an existing account a sign-in credential or, with registration
 * open, emails an unknown address a sign-up link, responding identically either
 * way. So the form never reveals which addresses are registered, and neither
 * should the copy around it.
 *
 * Every element it emits is addressable through an `*Attrs` option and every
 * string through a copy option, so the form can wear a site's own design system
 * without being rewritten.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class RequestFormTag extends BaseTag
{
    // Public Methods
    // =========================================================================

    /**
     * Merges attributes into the `<form>` element. Your classes accumulate onto
     * Warp's rather than replacing them; pass `resetClass: true` in the same
     * array to own the `class` outright.
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
     * Forces a single channel, `magic-link` or `otp`, posted as a hidden field
     * instead of offered as a choice. Default: every enabled login method, as a
     * choice when there is more than one.
     *
     * @param string $channel
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function channel(string $channel): self
    {
        $this->config['channel'] = $channel;
        return $this;
    }

    /**
     * Merges attributes into the `<fieldset>` around the channel choice.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function channelsAttrs(array $attrs): self
    {
        $this->config['channelsAttrs'] = $attrs;
        return $this;
    }

    /**
     * Merges attributes into each `<label>` wrapping a channel radio.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function choiceAttrs(array $attrs): self
    {
        $this->config['choiceAttrs'] = $attrs;
        return $this;
    }

    /**
     * Prefills the email input. Default: empty. `craft.warp.requestedEmail`
     * holds the address the visitor last submitted, if you want the form to
     * remember it after a failed attempt.
     *
     * @param string $email
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function email(string $email): self
    {
        $this->config['email'] = $email;
        return $this;
    }

    /**
     * Merges attributes into the email `<input>`.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function emailAttrs(array $attrs): self
    {
        $this->config['emailAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the email input's label. Default: "Email address".
     *
     * @param string $label
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function emailLabel(string $label): self
    {
        $this->config['emailLabel'] = $label;
        return $this;
    }

    /**
     * Merges attributes into the field wrapper around the email input and its
     * label.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function fieldAttrs(array $attrs): self
    {
        $this->config['fieldAttrs'] = $attrs;
        return $this;
    }

    /**
     * Merges attributes into the email `<label>`.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function labelAttrs(array $attrs): self
    {
        $this->config['labelAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the `<legend>` above the channel choice. Default: "How would you
     * like to sign in?"
     *
     * @param string $legend
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function legend(string $legend): self
    {
        $this->config['legend'] = $legend;
        return $this;
    }

    /**
     * Merges attributes into the `<legend>` above the channel choice.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function legendAttrs(array $attrs): self
    {
        $this->config['legendAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the page a magic-link request lands the visitor on after posting —
     * your "check your email" page. Posted as Craft's hashed `redirect`.
     *
     * With only one of the two page URLs set, both channels use it. With
     * neither, no `redirect` is posted and the visitor stays on the form with a
     * success flash.
     *
     * @param string $url
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function linkSentUrl(string $url): self
    {
        $this->config['linkSentUrl'] = $url;
        return $this;
    }

    /**
     * Sets the label of the magic-link channel choice. Default: "Email me a
     * sign-in link".
     *
     * @param string $label
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function magicLinkLabel(string $label): self
    {
        $this->config['magicLinkLabel'] = $label;
        return $this;
    }

    /**
     * Sets the label of the one-time-code channel choice. Default: "Email me a
     * sign-in code".
     *
     * @param string $label
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function otpLabel(string $label): self
    {
        $this->config['otpLabel'] = $label;
        return $this;
    }

    /**
     * Sets the page a one-time-code request lands the visitor on after posting —
     * your code-entry page. Posted as Craft's hashed `redirect`. See
     * [[linkSentUrl()]] for what happens when only one is set.
     *
     * @param string $url
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function otpVerifyUrl(string $url): self
    {
        $this->config['otpVerifyUrl'] = $url;
        return $this;
    }

    /**
     * Merges attributes into each channel radio `<input>`.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function radioAttrs(array $attrs): self
    {
        $this->config['radioAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets where the emailed credential lands the member once they use it,
     * posted as `returnUrl`. The endpoint honours it only when it sits under one
     * of the install's site base URLs. Default: none, which lands on the site
     * root.
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
     * Merges attributes into the submit button.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function submitAttrs(array $attrs): self
    {
        $this->config['submitAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the submit button label. Default: the label of the only enabled
     * channel, or "Continue" when the visitor is choosing between two.
     *
     * @param string $label
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function submitLabel(string $label): self
    {
        $this->config['submitLabel'] = $label;
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

        $channels = $this->_channels();
        $redirects = $this->_redirects();
        $formAttrs = $this->_mergeAttrs([
            'class' => 'warp-request-form',
            'accept-charset' => 'UTF-8',
            'data' => ['warp-request' => true],
        ], $this->config['attrs'] ?? []);

        $html = Html::beginForm(UrlHelper::actionUrl('warp/auth/request'), 'post', $formAttrs);

        if (($redirect = $redirects[$channels[0]] ?? null) !== null) {
            $html .= Html::redirectInput((string)$redirect);
        }

        if (!empty($this->config['returnUrl'])) {
            $html .= Html::hiddenInput('returnUrl', (string)$this->config['returnUrl']);
        }

        $html .= $this->_emailFieldHtml();

        $html .= count($channels) > 1
            ? $this->_channelChoiceHtml($channels, $redirects)
            : Html::hiddenInput('channel', $channels[0]);

        $html .= Html::submitButton(
            $this->_submitLabel($channels),
            $this->_mergeAttrs(['class' => 'warp-request-form__submit'], $this->config['submitAttrs'] ?? []),
        );

        return $html . Html::endForm();
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders the channel choice as a fieldset of radios, each carrying the
     * hashed redirect of its own destination page so the client script can keep
     * the form's `redirect` field in step with the selection.
     *
     * @param array<int, string> $channels the channels to offer
     * @param array<string, string|null> $redirects the destination page per channel
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _channelChoiceHtml(array $channels, array $redirects): string
    {
        if (array_filter($redirects) !== []) {
            $this->_registerAsset(WarpRequestAsset::class);
        }

        $security = Craft::$app->getSecurity();

        $html = Html::beginTag('fieldset', $this->_mergeAttrs(
            ['class' => 'warp-request-form__channels'],
            $this->config['channelsAttrs'] ?? [],
        ));

        $html .= Html::tag('legend', $this->config['legend'] ?? Craft::t('warp', 'How would you like to sign in?'), $this->_mergeAttrs(
            ['class' => 'warp-request-form__legend'],
            $this->config['legendAttrs'] ?? [],
        ));

        foreach ($channels as $index => $channel) {
            $radioAttrs = $this->_mergeAttrs([
                'type' => 'radio',
                'name' => 'channel',
                'value' => $channel,
                'checked' => $index === 0,
                'data' => [
                    'warp-redirect' => isset($redirects[$channel])
                        ? $security->hashData((string)$redirects[$channel])
                        : null,
                ],
            ], $this->config['radioAttrs'] ?? []);

            $html .= Html::tag(
                'label',
                Html::tag('input', '', $radioAttrs)
                    . Html::tag('span', Html::encode($this->_channelLabel($channel))),
                $this->_mergeAttrs(['class' => 'warp-request-form__choice'], $this->config['choiceAttrs'] ?? []),
            );
        }

        return $html . Html::endTag('fieldset');
    }

    /**
     * Returns the label of one channel choice.
     *
     * @param string $channel
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _channelLabel(string $channel): string
    {
        if ($channel === Settings::CHANNEL_OTP) {
            return (string)($this->config['otpLabel'] ?? Craft::t('warp', 'Email me a sign-in code'));
        }

        return (string)($this->config['magicLinkLabel'] ?? Craft::t('warp', 'Email me a sign-in link'));
    }

    /**
     * Returns the channels to offer: the forced `channel` when one was given,
     * every enabled login method otherwise.
     *
     * A forced channel the site has disabled is refused at issuance, so the
     * mismatch is logged rather than silently rendered into a form that cannot
     * succeed.
     *
     * @return array<int, string>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _channels(): array
    {
        $enabled = $this->_settings()->loginMethods;

        if (!isset($this->config['channel'])) {
            return $enabled !== [] ? array_values($enabled) : [Settings::CHANNEL_MAGIC_LINK];
        }

        $channel = (string)$this->config['channel'];

        if (!in_array($channel, $enabled, true)) {
            Craft::warning(
                sprintf('A Warp request form was rendered with channel="%s", which is not an enabled login method. The endpoint will refuse it.', $channel),
                __METHOD__,
            );
        }

        return [$channel];
    }

    /**
     * Renders the labelled email input.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _emailFieldHtml(): string
    {
        $emailAttrs = $this->_mergeAttrs([
            'id' => 'warp-request-email',
            'class' => 'warp-request-form__email',
            'autocomplete' => 'email',
            'autocapitalize' => 'none',
            'spellcheck' => 'false',
            'required' => true,
            'autofocus' => true,
        ], $this->config['emailAttrs'] ?? []);

        return Html::beginTag('div', $this->_mergeAttrs(
            ['class' => 'warp-request-form__field'],
            $this->config['fieldAttrs'] ?? [],
        ))
            . Html::label(
                $this->config['emailLabel'] ?? Craft::t('warp', 'Email address'),
                is_string($emailAttrs['id'] ?? null) ? $emailAttrs['id'] : null,
                $this->_mergeAttrs(['class' => 'warp-request-form__label'], $this->config['labelAttrs'] ?? []),
            )
            . Html::input('email', 'email', $this->config['email'] ?? null, $emailAttrs)
            . Html::endTag('div');
    }

    /**
     * Returns the destination page per channel. A site that names only one of
     * the two pages gets it used for both, which keeps a channel from posting an
     * empty `redirect` hash the endpoint would reject as tampered.
     *
     * @return array<string, string|null>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _redirects(): array
    {
        return [
            Settings::CHANNEL_MAGIC_LINK => $this->config['linkSentUrl'] ?? $this->config['otpVerifyUrl'] ?? null,
            Settings::CHANNEL_OTP => $this->config['otpVerifyUrl'] ?? $this->config['linkSentUrl'] ?? null,
        ];
    }

    /**
     * Returns the submit button label: the explicit one, the single enabled
     * channel's own label, or a neutral "Continue" when the visitor is choosing.
     *
     * @param array<int, string> $channels the channels being offered
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _submitLabel(array $channels): string
    {
        if (isset($this->config['submitLabel'])) {
            return (string)$this->config['submitLabel'];
        }

        if (count($channels) === 1) {
            return $this->_channelLabel($channels[0]);
        }

        return Craft::t('warp', 'Continue');
    }
}
