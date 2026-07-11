<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * An audit-sink test double: registered on Auth Kit's `audit` service via
 * Audit::setSinks(), it captures every AuthEvent Warp records so emission tests
 * can assert on the name, emitter, userId, and details of each path without
 * persisting anything.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\tests\Support;

use craftpulse\authkit\audit\AuditSinkInterface;
use craftpulse\authkit\audit\AuthEvent;

final class CollectingAuditSink implements AuditSinkInterface
{
    /**
     * @var AuthEvent[] The events record() handed this sink, in order.
     */
    public array $events = [];

    /**
     * @inheritdoc
     */
    public function handle(AuthEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Returns the first captured event with the given name, or null.
     */
    public function firstOfName(string $name): ?AuthEvent
    {
        foreach ($this->events as $event) {
            if ($event->name === $name) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Returns every captured event with the given name.
     *
     * @return AuthEvent[]
     */
    public function ofName(string $name): array
    {
        return array_values(array_filter(
            $this->events,
            static fn(AuthEvent $event): bool => $event->name === $name,
        ));
    }
}
