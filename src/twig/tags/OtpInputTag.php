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
use craft\helpers\StringHelper;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;

/**
 * OtpInputTag is the fluent builder for the segmented one-time-code input —
 * one square per digit, sized to the `otpDigits` setting, rendered via
 * `craft.warp.otpInput({...}).render()`.
 *
 * The server renders a single, fully functional `<input name="code">`; the
 * bundled client JS enhances it into one box per digit (auto-advance,
 * backspace, arrow keys, and paste distributing a full code across the boxes)
 * while the original input keeps carrying the submitted value. With no
 * JavaScript the plain input simply stays, so the form always submits.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class OtpInputTag extends BaseTag
{
    // Public Methods
    // =========================================================================

    /**
     * Sets whether the input autofocuses on page load. Default: false.
     *
     * @param bool $on
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function autofocus(bool $on): self
    {
        $this->config['autofocus'] = $on;
        return $this;
    }

    /**
     * Sets the number of digit boxes. Default: the resolved `otpDigits`
     * setting, so the input tracks the configured code length automatically.
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
     * Sets the input `id` attribute. When omitted, a stable per-render id is
     * generated so a `<label for>` and `aria-describedby` linkage still work.
     *
     * @param string $id
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function id(string $id): self
    {
        $this->config['id'] = $id;
        return $this;
    }

    /**
     * Merges extra attributes into the `<input>` element (for example an
     * `aria-describedby` pointing at a hint, or your own classes).
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
     * Sets the accessible label the enhanced box group announces. Default:
     * "Sign-in code".
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
     * Sets the input `name` attribute. Default: `code`, which is what
     * `warp/auth/verify-code` reads.
     *
     * @param string $name
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function name(string $name): self
    {
        $this->config['name'] = $name;
        return $this;
    }

    /**
     * Returns the generated (or configured) input id, so a composing tag can
     * point a `<label for>` at it.
     *
     * @return string
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getId(): string
    {
        if (!isset($this->config['id'])) {
            $this->config['id'] = 'warp-otp-' . StringHelper::randomString(8);
        }

        return $this->config['id'];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function _renderHtml(): string
    {
        $this->_registerClientAsset();

        $digits = $this->config['digits'] ?? $this->_defaultDigits();
        $label = $this->config['label'] ?? Craft::t('warp', 'Sign-in code');

        $attributes = [
            'type' => 'text',
            'id' => $this->getId(),
            'name' => $this->config['name'] ?? 'code',
            'class' => 'warp-otp',
            'inputmode' => 'numeric',
            'autocomplete' => 'one-time-code',
            'pattern' => '[0-9]*',
            'maxlength' => $digits,
            'required' => true,
            'autofocus' => (bool)($this->config['autofocus'] ?? false),
            'data' => [
                'warp-otp' => $digits,
                'warp-otp-label' => $label,
                'warp-otp-digit-label' => Craft::t('warp', 'Digit {n} of {count}', ['n' => '{n}', 'count' => '{count}']),
            ],
        ];

        $attributes = array_merge($attributes, $this->config['inputAttrs'] ?? []);

        return Html::tag('input', '', $attributes);
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
}
