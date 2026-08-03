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
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craftpulse\warp\assetbundles\warpotp\WarpOtpAsset;

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
 * Both JS-created elements are addressable from here: [[boxesAttrs()]] and
 * [[boxAttrs()]] are resolved server-side and handed to the script as data
 * attributes, so the box markup carries whatever classes and attributes the
 * site wants rather than only Warp's own.
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
     * Merges attributes into each digit box the client script creates. Default:
     * `{class: 'warp-otp__box'}`, plus the type, input mode, autocomplete,
     * maxlength and per-box `aria-label` the widget needs to work.
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
     * Merges attributes into the box group the client script creates around the
     * digit boxes. Default: `{class: 'warp-otp__boxes', role: 'group'}` plus the
     * group's `aria-label`.
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
     * Sets the number of digit boxes. Default: the resolved `otpDigits`
     * setting, so the input tracks the configured code length automatically.
     *
     * Setting this to anything other than `otpDigits` makes the input reject
     * part of the code the server issues, so a mismatch is logged as a warning.
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
     * Sets the accessible label each digit box announces. `{n}` is replaced with
     * the box's position and `{count}` with the number of boxes. Default:
     * "Digit {n} of {count}".
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
     * Sets the class the client script adds to the original input once it has
     * been enhanced, which is what hides it. Default: `warp-otp--enhanced`.
     * Change it when you style the input away yourself, and keep hiding it
     * somehow: the boxes are the visible control.
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
     * Merges attributes into the `<input>` element. Your classes accumulate onto
     * Warp's rather than replacing them (pass `resetClass: true` to replace),
     * and a `data` array merges key by key, so adding a data attribute cannot
     * take `data-warp-otp` with it and silently disable the segmented widget.
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
     * Returns the generated (or configured) input id, so a composing tag or
     * template can point a `<label for>` at it. Reading it is what fixes the id
     * for the rest of the render, so call it before `render()` if you need it.
     *
     * `{{ input.id }}` does not work: Twig resolves `id` to the one-argument
     * setter. Call `{{ input.getId() }}`.
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
        $this->_registerStyleAsset();
        $this->_registerAsset(WarpOtpAsset::class);

        $digits = $this->_resolveDigits();
        $label = $this->config['label'] ?? Craft::t('warp', 'Sign-in code');
        $digitLabel = $this->config['digitLabel']
            ?? Craft::t('warp', 'Digit {n} of {count}', ['n' => '{n}', 'count' => '{count}']);

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
                'warp-otp-digit-label' => $digitLabel,
                'warp-otp-enhanced-class' => $this->config['enhancedClass'] ?? 'warp-otp--enhanced',
                'warp-otp-boxes' => Json::encode($this->_boxesAttrs()),
                'warp-otp-box' => Json::encode($this->_boxAttrs()),
            ],
        ];

        return Html::tag('input', '', $this->_mergeAttrs($attributes, $this->config['inputAttrs'] ?? []));
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the resolved attributes for one digit box, flattened for the
     * client script. The `type`, `inputmode` and `maxlength` defaults are what
     * make the box behave as a numeric single-character field; the script sets
     * the per-box `aria-label` and the first box's `autocomplete` itself,
     * because both vary by position.
     *
     * @return array<string, string>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _boxAttrs(): array
    {
        return $this->_flattenAttrs($this->_mergeAttrs([
            'type' => 'text',
            'class' => 'warp-otp__box',
            'inputmode' => 'numeric',
        ], $this->config['boxAttrs'] ?? []));
    }

    /**
     * Returns the resolved attributes for the box group, flattened for the
     * client script. The group's `aria-describedby` is copied from the source
     * input by the script, since only it knows what the input was pointed at.
     *
     * @return array<string, string>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _boxesAttrs(): array
    {
        return $this->_flattenAttrs($this->_mergeAttrs([
            'class' => 'warp-otp__boxes',
            'role' => 'group',
        ], $this->config['boxesAttrs'] ?? []));
    }

    /**
     * Flattens a Craft-style attribute array into the plain name-to-string map
     * the client script applies with `setAttribute()`: nested `data` and `aria`
     * arrays are expanded to their prefixed names, a `class` array is joined,
     * `true` becomes an empty value, and `null` or `false` drops the attribute.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, string>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _flattenAttrs(array $attrs): array
    {
        $flat = [];

        foreach ($attrs as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($name === 'class') {
                $flat['class'] = implode(' ', Html::explodeClass($value));

                continue;
            }

            if (is_array($value) && ($name === 'data' || $name === 'aria')) {
                foreach ($value as $key => $nested) {
                    if ($nested === null || $nested === false) {
                        continue;
                    }

                    $flat["{$name}-{$key}"] = $nested === true ? '' : (string)$nested;
                }

                continue;
            }

            $flat[$name] = $value === true ? '' : (string)$value;
        }

        return $flat;
    }

    /**
     * Returns the number of digit boxes to render: the `digits` option when one
     * was given, the resolved `otpDigits` setting otherwise.
     *
     * A `digits` value that disagrees with `otpDigits` caps the input below (or
     * pads it above) the length of the code the server actually issues, which
     * makes signing in impossible, so the mismatch is logged rather than
     * silently honoured.
     *
     * @return int
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _resolveDigits(): int
    {
        $configured = $this->_settings()->getOtpDigits();
        $digits = $this->config['digits'] ?? null;

        if ($digits === null) {
            return $configured;
        }

        if ($digits !== $configured) {
            Craft::warning(
                sprintf(
                    'A Warp code input was rendered with digits=%d while the otpDigits setting resolves to %d. Issued codes are %d digits long, so the input will not accept a valid code.',
                    $digits,
                    $configured,
                    $configured,
                ),
                __METHOD__,
            );
        }

        return (int)$digits;
    }
}
