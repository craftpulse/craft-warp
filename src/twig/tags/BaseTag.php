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
use craftpulse\warp\assetbundles\warpforms\WarpFormsStyleAsset;
use craftpulse\warp\models\Settings;
use craftpulse\warp\Warp;
use InvalidArgumentException;
use Throwable;
use Twig\Markup;

/**
 * BaseTag is the base class for the fluent Twig render builders shipped under
 * `craft.warp.*` — the same builder shape Password Policy ships for its
 * password forms. It provides the chainable-setter / config-array dual-mode
 * constructor, the Markup-wrapped render path, the per-element attribute merge
 * every builder option goes through, and the opt-out-able registration of the
 * front-end assets the builders' affordances need.
 *
 * Concrete tags implement [[_renderHtml()]] returning a plain HTML string.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
abstract class BaseTag
{
    // Protected Properties
    // =========================================================================

    /**
     * @var array<string, mixed> Raw config array — internal storage for the
     * fluent setters.
     */
    protected array $config = [];

    // Public Methods
    // =========================================================================

    /**
     * Accepts an initial configuration array. Each key must match a chainable
     * setter on the concrete subclass — an unknown key throws so consumers
     * learn about typos at render time instead of silently losing an option.
     *
     * @param array<string, mixed> $config the initial configuration
     * @throws InvalidArgumentException if a key matches no setter
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (!method_exists($this, $key)) {
                throw new InvalidArgumentException(
                    sprintf('Unknown option "%s" for %s.', $key, static::class),
                );
            }

            $this->$key($value);
        }
    }

    /**
     * Renders the configured tag to HTML, returned as a `Markup` instance so
     * Twig does not double-escape it. Always call `{{ tag.render() }}` from
     * Twig — `{{ tag }}` routes through [[__toString()]], whose plain-string
     * return contract makes Twig re-escape the markup.
     *
     * @return Markup
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function render(): Markup
    {
        return new Markup($this->_renderHtml(), Craft::$app->getView()->getTwig()->getCharset());
    }

    /**
     * Sets whether this render registers Warp's baseline stylesheet. Default:
     * the `renderCss` setting, which is on. Turn it off to own the styling of
     * the rendered markup outright; nothing else about the output changes.
     *
     * @param bool $on
     * @return $this
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function renderCss(bool $on): self
    {
        $this->config['renderCss'] = $on;
        return $this;
    }

    /**
     * Stringifies the rendered HTML for PHP-context composition — composite
     * tags assembling children into one output string. Twig consumers must use
     * [[render()]] instead (see there).
     *
     * @return string raw HTML; the consumer is responsible for not re-escaping
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function __toString(): string
    {
        return $this->_renderHtml();
    }

    // Protected Methods
    // =========================================================================

    /**
     * Concrete subclasses implement this; [[render()]] wraps the result in a
     * Twig `Markup` so consumers never need `|raw`.
     *
     * @return string raw HTML
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    abstract protected function _renderHtml(): string;

    /**
     * Merges a caller-supplied attribute array over an element's defaults, the
     * way a theme override should behave rather than the way `array_merge()`
     * does:
     *
     * - `class` accumulates, so Warp's own class survives alongside yours. Pass
     *   `resetClass: true` in the same array to drop Warp's classes first and
     *   own the element's `class` outright.
     * - `data` merges key by key, so adding one data attribute cannot take the
     *   builder's own with it — which for the code input would silently disable
     *   the segmented widget.
     * - every other attribute is replaced, and `null` or `false` removes it.
     *
     * @param array<string, mixed> $defaults the element's own attributes
     * @param array<string, mixed> $overrides the caller's attributes
     * @return array<string, mixed>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    protected function _mergeAttrs(array $defaults, array $overrides): array
    {
        $resetClass = (bool)($overrides['resetClass'] ?? false);
        unset($overrides['resetClass']);

        $merged = $defaults;

        if ($resetClass) {
            unset($merged['class']);
        }

        foreach ($overrides as $name => $value) {
            if ($name === 'class') {
                $merged['class'] = array_values(array_unique(array_merge(
                    Html::explodeClass($merged['class'] ?? null),
                    Html::explodeClass($value),
                )));

                continue;
            }

            if ($name === 'data' && is_array($value) && is_array($merged['data'] ?? null)) {
                $merged['data'] = array_merge($merged['data'], $value);

                continue;
            }

            $merged[$name] = $value;
        }

        return $merged;
    }

    /**
     * Registers one of Warp's front-end scripts on the current view. Always
     * registered: the scripts are behavior rather than decoration, so there is
     * no switch for them. A site that wants different markup writes its own
     * template against the DOM contract the script documents, rather than
     * turning the script off and losing the affordance.
     *
     * @param class-string<\craft\web\AssetBundle> $bundle the asset bundle to register
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    protected function _registerScriptAsset(string $bundle): void
    {
        $this->_registerAsset($bundle);
    }

    /**
     * Registers Warp's baseline stylesheet on the current view, unless the
     * render or the install has CSS switched off.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    protected function _registerStyleAsset(): void
    {
        if (!$this->_rendersCss()) {
            return;
        }

        $this->_registerAsset(WarpFormsStyleAsset::class);
    }

    /**
     * Returns Warp's settings model.
     *
     * @return Settings
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    protected function _settings(): Settings
    {
        $settings = Warp::$plugin->getSettings();
        assert($settings instanceof Settings);

        return $settings;
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers an asset bundle on the current view, swallowing any failure —
     * a published-asset problem must never take a page down with it.
     *
     * @param class-string<\craft\web\AssetBundle> $bundle
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _registerAsset(string $bundle): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        try {
            Craft::$app->getView()->registerAssetBundle($bundle);
        } catch (Throwable) {
            // Defensive — never let asset registration break Twig rendering.
        }
    }

    /**
     * Returns whether this render should register the baseline stylesheet: the
     * per-render option when one was given, the `renderCss` setting otherwise.
     *
     * @return bool
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _rendersCss(): bool
    {
        return (bool)($this->config['renderCss'] ?? $this->_settings()->renderCss);
    }
}
