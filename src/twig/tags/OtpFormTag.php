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
use craftpulse\warp\controllers\AuthController;

/**
 * OtpFormTag is the fluent builder for the complete one-time-code verify form,
 * rendered via `craft.warp.otpForm({...}).render()` — the whole thing a
 * code-entry page needs: the post to `warp/auth/verify-code` with CSRF, the
 * session-carried email prefill (hidden field plus a "sent to" line) or a
 * visible email input when no address is held, the segmented code input
 * ([[OtpInputTag]]), its hint, and the submit button.
 *
 * The email prefill mirrors the example templates' behavior: it defaults to
 * the session-carried `craft.warp.requestedEmail`, so the member who just
 * requested a code never re-types their address.
 *
 * Every element it emits is addressable: there is an `*Attrs` option per
 * element and a copy option per string, so the form can be dressed in a site's
 * own design system without forking it, and both `renderCss` and `renderJs`
 * switch Warp's own assets off.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class OtpFormTag extends BaseTag
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
     * Merges attributes into each digit box the client script creates, by way of
     * the nested code input. Default: `{class: 'warp-otp__box'}`.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function boxAttrs(array $attrs): self
    {
        $this->config['boxAttrs'] = $attrs;
        return $this;
    }

    /**
     * Merges attributes into the box group the client script creates, by way of
     * the nested code input. Default: `{class: 'warp-otp__boxes', role: 'group'}`.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function boxesAttrs(array $attrs): self
    {
        $this->config['boxesAttrs'] = $attrs;
        return $this;
    }

    /**
     * Merges attributes into the "use a different address" link.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function changeAttrs(array $attrs): self
    {
        $this->config['changeAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the text of the link back to the request form. Default: "Use a
     * different address".
     *
     * @param string $label
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function changeLabel(string $label): self
    {
        $this->config['changeLabel'] = $label;
        return $this;
    }

    /**
     * Sets the number of digit boxes. Default: the resolved `otpDigits`
     * setting. A value that disagrees with `otpDigits` is logged, because the
     * server issues codes of the configured length regardless.
     *
     * @param int $digits
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function digits(int $digits): self
    {
        $this->config['digits'] = $digits;
        return $this;
    }

    /**
     * Sets the accessible label each digit box announces, by way of the nested
     * code input. `{n}` and `{count}` are replaced. Default: "Digit {n} of
     * {count}".
     *
     * @param string $label
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function digitLabel(string $label): self
    {
        $this->config['digitLabel'] = $label;
        return $this;
    }

    /**
     * Sets the email address the form submits alongside the code. Default:
     * the session-carried address of the just-requested code
     * (`craft.warp.requestedEmail`); when neither is present the form renders
     * a visible email input instead.
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
     * Merges attributes into the visible email input rendered on a direct visit.
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
     * Sets the label of the visible email input. Default: "Email address".
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
     * Sets the class the client script adds to the original code input once it
     * has been enhanced, by way of the nested code input. Default:
     * `warp-otp--enhanced`.
     *
     * @param string $class
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function enhancedClass(string $class): self
    {
        $this->config['enhancedClass'] = $class;
        return $this;
    }

    /**
     * Merges attributes into each field wrapper — the `<div>` around a label and
     * its control, rendered once for the email input and once for the code
     * input.
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
     * Sets the hint below the code input. `{digits}` is replaced with the number
     * of boxes. Default: "Enter the {digits}-digit code from your email."
     *
     * @param string $hint
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function hint(string $hint): self
    {
        $this->config['hint'] = $hint;
        return $this;
    }

    /**
     * Merges attributes into the hint below the code input. Its `id` is the
     * anchor the code input's `aria-describedby` points at, so replace it only
     * with an id you also point the input at.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function hintAttrs(array $attrs): self
    {
        $this->config['hintAttrs'] = $attrs;
        return $this;
    }

    /**
     * Merges attributes into the code `<input>`, by way of the nested code
     * input builder.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function inputAttrs(array $attrs): self
    {
        $this->config['inputAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the code input's label, used both for the visible `<label>` and for
     * the box group's accessible name. Default: "Sign-in code".
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
     * Merges attributes into each `<label>` the form renders.
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
     * Sets the URL of the request form, used by the "Use a different address"
     * link next to the prefilled address. Default: Craft's `loginPath`; with
     * none available the link is omitted.
     *
     * @param string $url
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function requestUrl(string $url): self
    {
        $this->config['requestUrl'] = $url;
        return $this;
    }

    /**
     * Sets where a verified code lands the member, posted as `returnUrl`. The
     * endpoint honours it only when it belongs to the site the request was made
     * against. Default: none, which lands on the site root.
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
     * Merges attributes into the "your code was sent to" line.
     *
     * @param array<string, mixed> $attrs
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function sentAttrs(array $attrs): self
    {
        $this->config['sentAttrs'] = $attrs;
        return $this;
    }

    /**
     * Sets the confirmation line above the code input. `{email}` is replaced
     * with the carried address. Default: "Your code was sent to {email}."
     *
     * @param string $text
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function sentText(string $text): self
    {
        $this->config['sentText'] = $text;
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
     * Sets the submit button label. Default: "Sign in".
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

        $prefillEmail = $this->config['email'] ?? $this->_requestedEmail();
        $requestUrl = $this->config['requestUrl'] ?? $this->_defaultRequestUrl();
        $label = $this->config['label'] ?? Craft::t('warp', 'Sign-in code');

        $input = $this->_input($label, $prefillEmail !== null);
        $inputId = $input->getId();
        $hintAttrs = $this->_mergeAttrs([
            'class' => 'warp-otp-form__hint',
            'id' => "{$inputId}-hint",
        ], $this->config['hintAttrs'] ?? []);

        $inputAttrs = $this->config['inputAttrs'] ?? [];

        if (!array_key_exists('aria-describedby', $inputAttrs) && isset($hintAttrs['id'])) {
            $inputAttrs['aria-describedby'] = $hintAttrs['id'];
        }

        $input->inputAttrs($inputAttrs);

        $formAttrs = $this->_mergeAttrs([
            'class' => 'warp-otp-form',
            'accept-charset' => 'UTF-8',
        ], $this->config['attrs'] ?? []);

        $html = Html::beginForm(UrlHelper::actionUrl('warp/auth/verify-code'), 'post', $formAttrs);

        if (!empty($this->config['returnUrl'])) {
            $html .= Html::hiddenInput('returnUrl', (string)$this->config['returnUrl']);
        }

        $html .= $prefillEmail !== null
            ? $this->_prefilledEmailHtml($prefillEmail, $requestUrl)
            : $this->_visibleEmailHtml($inputId);

        $html .= Html::beginTag('div', $this->_fieldAttrs());
        $html .= Html::label($label, $inputId, $this->_labelAttrs());
        $html .= (string)$input;
        $html .= Html::tag('span', $this->_hintText(), $hintAttrs);
        $html .= Html::endTag('div');

        $html .= Html::submitButton(
            $this->config['submitLabel'] ?? Craft::t('warp', 'Sign in'),
            $this->_mergeAttrs(['class' => 'warp-otp-form__submit'], $this->config['submitAttrs'] ?? []),
        );

        return $html . Html::endForm();
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the default "Use a different address" destination — Craft's
     * `loginPath` — or null when it is disabled, in which case the link is
     * omitted.
     *
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _defaultRequestUrl(): ?string
    {
        $loginPath = Craft::$app->getConfig()->getGeneral()->getLoginPath();

        if (!is_string($loginPath) || $loginPath === '') {
            return null;
        }

        return UrlHelper::siteUrl($loginPath);
    }

    /**
     * Returns the resolved attributes for a field wrapper.
     *
     * @return array<string, mixed>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _fieldAttrs(): array
    {
        return $this->_mergeAttrs(['class' => 'warp-otp-form__field'], $this->config['fieldAttrs'] ?? []);
    }

    /**
     * Returns the hint copy below the code input, with `{digits}` resolved to
     * the number of boxes actually rendered.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _hintText(): string
    {
        $digits = $this->config['digits'] ?? $this->_settings()->getOtpDigits();

        if (isset($this->config['hint'])) {
            return str_replace('{digits}', (string)$digits, (string)$this->config['hint']);
        }

        return Craft::t('warp', 'Enter the {digits}-digit code from your email.', ['digits' => $digits]);
    }

    /**
     * Builds the nested code input, forwarding every option the form exposes on
     * its behalf, `renderCss` included, so turning Warp's styling off on the
     * form turns it off for the input too.
     *
     * @param string $label the code input's label
     * @param bool $autofocus whether the code input leads the form
     * @return OtpInputTag
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _input(string $label, bool $autofocus): OtpInputTag
    {
        $config = [
            // The member's next action is typing the code, so focus it when
            // the address is already carried; the email field leads otherwise.
            'autofocus' => $autofocus,
            'label' => $label,
        ];

        foreach (['boxAttrs', 'boxesAttrs', 'digits', 'digitLabel', 'enhancedClass', 'renderCss'] as $key) {
            if (isset($this->config[$key])) {
                $config[$key] = $this->config[$key];
            }
        }

        return new OtpInputTag($config);
    }

    /**
     * Returns the resolved attributes for a `<label>`.
     *
     * @return array<string, mixed>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _labelAttrs(): array
    {
        return $this->_mergeAttrs(['class' => 'warp-otp-form__label'], $this->config['labelAttrs'] ?? []);
    }

    /**
     * Renders the prefilled-address branch: the hidden email field and the
     * "sent to" line with its change link.
     *
     * @param string $email the carried address
     * @param string|null $requestUrl the request form URL, or null to omit the link
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _prefilledEmailHtml(string $email, ?string $requestUrl): string
    {
        $sentTo = isset($this->config['sentText'])
            ? str_replace('{email}', $email, (string)$this->config['sentText'])
            : Craft::t('warp', 'Your code was sent to {email}.', ['email' => $email]);

        $sentTo = Html::encode($sentTo);

        if ($requestUrl !== null) {
            $sentTo .= ' ' . Html::a(
                $this->config['changeLabel'] ?? Craft::t('warp', 'Use a different address'),
                $requestUrl,
                $this->_mergeAttrs(['class' => 'warp-otp-form__change'], $this->config['changeAttrs'] ?? []),
            );
        }

        return Html::hiddenInput('email', $email)
            . Html::tag('p', $sentTo, $this->_mergeAttrs(
                ['class' => 'warp-otp-form__sent'],
                $this->config['sentAttrs'] ?? [],
            ));
    }

    /**
     * Returns the session-carried address of the just-requested code, or null
     * when none is held (a direct visit).
     *
     * @return string|null
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _requestedEmail(): ?string
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }

        /** @var \craft\web\Application $app */
        $app = Craft::$app;
        $email = $app->getSession()->get(AuthController::SESSION_REQUESTED_EMAIL);

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Renders the fallback branch for a direct visit: a visible, labelled
     * email input.
     *
     * @param string $inputId the code input id, used to derive the email input id
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _visibleEmailHtml(string $inputId): string
    {
        $emailAttrs = $this->_mergeAttrs([
            'id' => "{$inputId}-email",
            'class' => 'warp-otp-form__email',
            'autocomplete' => 'email',
            'autocapitalize' => 'none',
            'spellcheck' => 'false',
            'required' => true,
        ], $this->config['emailAttrs'] ?? []);

        return Html::beginTag('div', $this->_fieldAttrs())
            . Html::label(
                $this->config['emailLabel'] ?? Craft::t('warp', 'Email address'),
                is_string($emailAttrs['id'] ?? null) ? $emailAttrs['id'] : null,
                $this->_labelAttrs(),
            )
            . Html::input('email', 'email', null, $emailAttrs)
            . Html::endTag('div');
    }
}
