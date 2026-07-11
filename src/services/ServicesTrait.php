<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\services;

/**
 * ServicesTrait owns Warp's service component registration and typed accessors.
 *
 * Components are declared in [[config()]], which Craft merges into the plugin's
 * Yii config during construction. Each service gains a typed `getX(): X`
 * accessor that narrows Yii's `?object` return for static analysis, and a
 * `@property-read` tag on this trait's docblock for property-style access —
 * never duplicated on the main plugin class.
 *
 * @property-read Logins $logins
 * @property-read Passwordless $passwordless
 * @property-read Registration $registration
 * @property-read Sessions $sessions
 *
 * @author CraftPulse
 * @since 5.0.0
 */
trait ServicesTrait
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the component config Craft merges into the plugin's application config.
     *
     * @return array<string, mixed>
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'logins' => ['class' => Logins::class],
                'passwordless' => ['class' => Passwordless::class],
                'registration' => ['class' => Registration::class],
                'sessions' => ['class' => Sessions::class],
            ],
        ];
    }

    /**
     * Returns the logins service.
     *
     * @return Logins
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getLogins(): Logins
    {
        $component = $this->get('logins');
        assert($component instanceof Logins);

        return $component;
    }

    /**
     * Returns the passwordless service.
     *
     * @return Passwordless
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getPasswordless(): Passwordless
    {
        $component = $this->get('passwordless');
        assert($component instanceof Passwordless);

        return $component;
    }

    /**
     * Returns the registration service.
     *
     * @return Registration
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getRegistration(): Registration
    {
        $component = $this->get('registration');
        assert($component instanceof Registration);

        return $component;
    }

    /**
     * Returns the sessions service.
     *
     * @return Sessions
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function getSessions(): Sessions
    {
        $component = $this->get('sessions');
        assert($component instanceof Sessions);

        return $component;
    }
}
