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
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;

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
 * @author CraftPulse
 * @since 5.0.0
 */
class OtpFormTag extends BaseTag
{
    // Public Methods
    // =========================================================================

    /**
     * Merges extra attributes into the `<form>` element.
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
     * Sets the number of digit boxes. Default: the resolved `otpDigits`
     * setting.
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
     * Sets where a verified code lands the member, posted as `returnUrl`.
     * The endpoint validates it as same-site before honouring it. Default:
     * none, which lands on the site root.
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
        $digits = $this->config['digits'] ?? $this->_defaultDigits();
        $prefillEmail = $this->config['email'] ?? $this->_requestedEmail();
        $requestUrl = $this->config['requestUrl'] ?? $this->_defaultRequestUrl();

        $input = new OtpInputTag([
            'digits' => $digits,
            // The member's next action is typing the code, so focus it when
            // the address is already carried; the email field leads otherwise.
            'autofocus' => $prefillEmail !== null,
        ]);
        $inputId = $input->getId();
        $hintId = "{$inputId}-hint";
        $input->inputAttrs(['aria-describedby' => $hintId]);

        $formAttrs = array_merge([
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

        $html .= Html::beginTag('div', ['class' => 'warp-otp-form__field']);
        $html .= Html::label(Craft::t('warp', 'Sign-in code'), $inputId, ['class' => 'warp-otp-form__label']);
        $html .= (string)$input;
        $html .= Html::tag('span', Craft::t('warp', 'Enter the {digits}-digit code from your email.', ['digits' => $digits]), [
            'class' => 'warp-otp-form__hint',
            'id' => $hintId,
        ]);
        $html .= Html::endTag('div');

        $html .= Html::submitButton($this->config['submitLabel'] ?? Craft::t('warp', 'Sign in'), [
            'class' => 'warp-otp-form__submit',
        ]);

        return $html . Html::endForm();
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the resolved `otpDigits` setting.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _defaultDigits(): int
    {
        $settings = Warp::$plugin->getSettings();
        assert($settings instanceof Settings);

        return $settings->getOtpDigits();
    }

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
        $sentTo = Html::encode(Craft::t('warp', 'Your code was sent to {email}.', ['email' => $email]));

        if ($requestUrl !== null) {
            $sentTo .= ' ' . Html::a(Craft::t('warp', 'Use a different address'), $requestUrl, [
                'class' => 'warp-otp-form__change',
            ]);
        }

        return Html::hiddenInput('email', $email)
            . Html::tag('p', $sentTo, ['class' => 'warp-otp-form__sent']);
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
        $emailId = "{$inputId}-email";

        return Html::beginTag('div', ['class' => 'warp-otp-form__field'])
            . Html::label(Craft::t('warp', 'Email address'), $emailId, ['class' => 'warp-otp-form__label'])
            . Html::input('email', 'email', null, [
                'id' => $emailId,
                'class' => 'warp-otp-form__email',
                'autocomplete' => 'email',
                'autocapitalize' => 'none',
                'spellcheck' => 'false',
                'required' => true,
            ])
            . Html::endTag('div');
    }
}
