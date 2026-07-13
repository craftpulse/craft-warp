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
use craftpulse\warp\assetbundles\warpotp\WarpOtpAsset;
use InvalidArgumentException;
use Throwable;
use Twig\Markup;

/**
 * BaseTag is the base class for the fluent Twig render builders shipped under
 * `craft.warp.*` — the same builder shape Password Policy ships for its
 * password forms. It provides the chainable-setter / config-array dual-mode
 * constructor, the Markup-wrapped render path, and the lazy registration of
 * the front-end client asset the builders' JS affordances need.
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
     * Registers the front-end client asset (the segmented-input enhancement JS
     * and its neutral baseline CSS) on the current view. Defensive and a no-op
     * outside web requests — asset registration must never break a render.
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    protected function _registerClientAsset(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        try {
            Craft::$app->getView()->registerAssetBundle(WarpOtpAsset::class);
        } catch (Throwable) {
            // Defensive — never let asset registration break Twig rendering.
        }
    }
}
