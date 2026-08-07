<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Service tests for the device registry behind front-end session management.
 * The matrix pins the security-load-bearing behaviour: capture hashes the live
 * token, revocation is ownership-scoped in the query (a user can never reach
 * another's session), revoking deletes the authoritative core row, "everywhere
 * else" spares the current session, listing flags the current one and falls back
 * for unknown devices, and garbage collection clears orphaned registry rows.
 *
 * Core sessions are inserted directly — the same shape
 * `craft\web\User::generateToken()` writes on login — so the service can be
 * exercised without a full browser session, and the current request's token is
 * simulated on the session component the service reads through.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craftpulse\authkit\audit\AuthEvent;
use craftpulse\authkit\AuthKit;
use craftpulse\warp\db\Table;
use craftpulse\warp\records\Session as SessionRecord;
use craftpulse\warp\tests\Support\CollectingAuditSink;
use craftpulse\warp\Warp;

function sessionsUser(): User
{
    $unique = str_replace('-', '', StringHelper::UUID());
    $user = new User();
    $user->username = "sess-{$unique}@warp-test.example";
    $user->email = "sess-{$unique}@warp-test.example";

    if (!Craft::$app->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save sessions test user.');
    }

    Craft::$app->getUsers()->activateUser($user);

    return Craft::$app->getUsers()->getUserById((int)$user->id);
}

function insertCoreSession(int $userId): string
{
    $token = 'tok-' . bin2hex(random_bytes(32));
    Db::insert(CraftTable::SESSIONS, ['userId' => $userId, 'token' => $token]);

    return $token;
}

function insertRegistryRow(int $userId, string $token, ?string $userAgent = null, ?string $ip = null, ?bool $isNewLocation = null): string
{
    $record = new SessionRecord();
    $record->userId = $userId;
    $record->tokenHash = hash('sha256', $token);
    $record->userAgent = $userAgent;
    $record->ip = $ip;
    $record->isNewLocation = $isNewLocation;
    $record->save(false);

    return $record->uid;
}

function setCurrentToken(string $token): void
{
    Craft::$app->getSession()->set(Craft::$app->getUser()->tokenParam, $token);
}

function coreSessionExists(string $token): bool
{
    return (new Query())->from(CraftTable::SESSIONS)->where(['token' => $token])->exists();
}

function registryExistsForToken(string $token): bool
{
    return (new Query())->from(Table::SESSIONS)->where(['tokenHash' => hash('sha256', $token)])->exists();
}

beforeEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Craft::$app->getUser()->tokenParam);
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);
    Craft::$app->getSession()->remove(Craft::$app->getUser()->tokenParam);

    foreach (User::find()->email('*@warp-test.example')->status(null)->all() as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    AuthKit::$plugin->getAudit()->setSinks([]);
});

// =============================================================================
// capture
// =============================================================================

it('records the current session against its device on login', function() {
    $user = sessionsUser();
    $token = insertCoreSession((int)$user->id);
    Craft::$app->getUser()->setIdentity($user);
    setCurrentToken($token);
    Craft::$app->getRequest()->getHeaders()->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0.0.0 Safari/537.36');

    try {
        Warp::$plugin->getSessions()->record();
    } finally {
        Craft::$app->getRequest()->getHeaders()->remove('User-Agent');
    }

    $record = SessionRecord::findOne(['tokenHash' => hash('sha256', $token)]);

    expect($record)->not->toBeNull()
        ->and((int)$record->userId)->toBe((int)$user->id)
        ->and($record->userAgent)->toContain('Chrome');
});

it('anonymizes the stored IP when the setting is on', function() {
    $user = sessionsUser();
    $token = insertCoreSession((int)$user->id);
    Craft::$app->getUser()->setIdentity($user);
    setCurrentToken($token);
    $originalAnonymize = Warp::$plugin->getSettings()->anonymizeIp;
    Warp::$plugin->getSettings()->anonymizeIp = true;

    // craft-pest identifies the fake request via an X-Forwarded-For header,
    // and getUserIP() memoizes its first answer — plant a known address in
    // that header and reset the memo on both sides.
    $request = Craft::$app->getRequest();
    $ipProperty = new ReflectionProperty(craft\web\Request::class, '_ipAddress');
    $ipProperty->setValue($request, null);
    $originalForwardedFor = $request->getHeaders()->get('X-Forwarded-For');
    $request->getHeaders()->set('X-Forwarded-For', '203.0.113.45');

    try {
        Warp::$plugin->getSessions()->record();
    } finally {
        Warp::$plugin->getSettings()->anonymizeIp = $originalAnonymize;
        $request->getHeaders()->set('X-Forwarded-For', $originalForwardedFor);
        $ipProperty->setValue($request, null);
    }

    $record = SessionRecord::findOne(['tokenHash' => hash('sha256', $token)]);

    expect($record->ip)->toBe('203.0.113.0');
});

it('does not record twice for the same session token', function() {
    $user = sessionsUser();
    $token = insertCoreSession((int)$user->id);
    Craft::$app->getUser()->setIdentity($user);
    setCurrentToken($token);

    Warp::$plugin->getSessions()->record();
    Warp::$plugin->getSessions()->record();

    $count = (new Query())->from(Table::SESSIONS)->where(['tokenHash' => hash('sha256', $token)])->count();

    expect((int)$count)->toBe(1);
});

// =============================================================================
// revoke — ownership scoped
// =============================================================================

it('refuses to revoke a session that belongs to another user', function() {
    $alice = sessionsUser();
    $bob = sessionsUser();
    $bobToken = insertCoreSession((int)$bob->id);
    $bobUid = insertRegistryRow((int)$bob->id, $bobToken);

    $revoked = Warp::$plugin->getSessions()->revoke($alice, $bobUid);

    expect($revoked)->toBeFalse()
        ->and(coreSessionExists($bobToken))->toBeTrue()
        ->and(registryExistsForToken($bobToken))->toBeTrue();
});

it('revokes an owned session, deleting the core row so the browser is a guest next request', function() {
    $user = sessionsUser();
    $token = insertCoreSession((int)$user->id);
    $uid = insertRegistryRow((int)$user->id, $token);

    $revoked = Warp::$plugin->getSessions()->revoke($user, $uid);

    // The core {{%sessions}} row gone is the authoritative kill: Craft validates
    // the token against that table on every request, so the browser is a guest.
    expect($revoked)->toBeTrue()
        ->and(coreSessionExists($token))->toBeFalse()
        ->and(registryExistsForToken($token))->toBeFalse();
});

it('returns false revoking an unknown uid', function() {
    $user = sessionsUser();

    expect(Warp::$plugin->getSessions()->revoke($user, StringHelper::UUID()))->toBeFalse();
});

// =============================================================================
// revoke others — spares current
// =============================================================================

it('signs out every session except the current one', function() {
    $user = sessionsUser();
    $current = insertCoreSession((int)$user->id);
    $other1 = insertCoreSession((int)$user->id);
    $other2 = insertCoreSession((int)$user->id);
    insertRegistryRow((int)$user->id, $current);
    insertRegistryRow((int)$user->id, $other1);
    insertRegistryRow((int)$user->id, $other2);
    Craft::$app->getUser()->setIdentity($user);
    setCurrentToken($current);

    $count = Warp::$plugin->getSessions()->revokeOthers($user);

    expect($count)->toBe(2)
        ->and(coreSessionExists($current))->toBeTrue()
        ->and(coreSessionExists($other1))->toBeFalse()
        ->and(coreSessionExists($other2))->toBeFalse()
        ->and(registryExistsForToken($current))->toBeTrue()
        ->and(registryExistsForToken($other1))->toBeFalse();
});

it('signs out an unknown device with no registry row through revoke others', function() {
    $user = sessionsUser();
    $current = insertCoreSession((int)$user->id);
    $unknown = insertCoreSession((int)$user->id);
    insertRegistryRow((int)$user->id, $current);
    Craft::$app->getUser()->setIdentity($user);
    setCurrentToken($current);

    $count = Warp::$plugin->getSessions()->revokeOthers($user);

    expect($count)->toBe(1)
        ->and(coreSessionExists($unknown))->toBeFalse();
});

// =============================================================================
// audit emission — a genuine revocation records session.revoked
// =============================================================================

it('records a session.revoked audit event with scope single on revoke', function() {
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);

    $user = sessionsUser();
    $token = insertCoreSession((int)$user->id);
    $uid = insertRegistryRow((int)$user->id, $token);

    Warp::$plugin->getSessions()->revoke($user, $uid);

    $event = $sink->firstOfName(AuthEvent::SESSION_REVOKED);

    expect($event)->not->toBeNull()
        ->and($event->emitter)->toBe('warp')
        ->and($event->outcome)->toBe(AuthEvent::OUTCOME_SUCCESS)
        ->and($event->userId)->toBe((int)$user->id)
        ->and($event->details)->toBe(['scope' => 'single']);
});

it('records no audit event revoking an unknown uid', function() {
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);

    Warp::$plugin->getSessions()->revoke(sessionsUser(), StringHelper::UUID());

    expect($sink->events)->toBe([]);
});

it('records a session.revoked audit event with scope others when it signs out others', function() {
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);

    $user = sessionsUser();
    $current = insertCoreSession((int)$user->id);
    $other = insertCoreSession((int)$user->id);
    insertRegistryRow((int)$user->id, $current);
    insertRegistryRow((int)$user->id, $other);
    Craft::$app->getUser()->setIdentity($user);
    setCurrentToken($current);

    Warp::$plugin->getSessions()->revokeOthers($user);

    $event = $sink->firstOfName(AuthEvent::SESSION_REVOKED);

    expect($event)->not->toBeNull()
        ->and($event->userId)->toBe((int)$user->id)
        ->and($event->details)->toBe(['scope' => 'others']);
});

it('records no audit event when revoke others finds only the current session', function() {
    $sink = new CollectingAuditSink();
    AuthKit::$plugin->getAudit()->setSinks([$sink]);

    $user = sessionsUser();
    $current = insertCoreSession((int)$user->id);
    insertRegistryRow((int)$user->id, $current);
    Craft::$app->getUser()->setIdentity($user);
    setCurrentToken($current);

    expect(Warp::$plugin->getSessions()->revokeOthers($user))->toBe(0)
        ->and($sink->events)->toBe([]);
});

// =============================================================================
// listing
// =============================================================================

it('lists sessions, flagging the current one and labelling unknown devices', function() {
    $user = sessionsUser();
    $current = insertCoreSession((int)$user->id);
    $known = insertCoreSession((int)$user->id);
    insertCoreSession((int)$user->id); // no registry row -> unknown device
    $currentUid = insertRegistryRow((int)$user->id, $current, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0.0.0 Safari/537.36', '203.0.113.4');
    insertRegistryRow((int)$user->id, $known, 'Mozilla/5.0 (Windows NT 10.0; rv:121.0) Gecko/20100101 Firefox/121.0');
    Craft::$app->getUser()->setIdentity($user);
    setCurrentToken($current);

    $sessions = Warp::$plugin->getSessions()->getSessionsForUser($user);

    expect($sessions)->toHaveCount(3)
        ->and($sessions[0]->isCurrent)->toBeTrue()
        ->and($sessions[0]->uid)->toBe($currentUid)
        ->and($sessions[0]->deviceLabel)->toBe('Chrome on macOS')
        ->and($sessions[0]->ip)->toBe('203.0.113.4');

    $unknown = array_values(array_filter($sessions, static fn($info): bool => $info->uid === null));

    expect($unknown)->toHaveCount(1)
        ->and($unknown[0]->deviceLabel)->toBe('Unknown device');
});

it('carries the registry new-location flag through in all three states', function() {
    $user = sessionsUser();
    $new = insertCoreSession((int)$user->id);
    $familiar = insertCoreSession((int)$user->id);
    $unassessed = insertCoreSession((int)$user->id);
    $newUid = insertRegistryRow((int)$user->id, $new, isNewLocation: true);
    $familiarUid = insertRegistryRow((int)$user->id, $familiar, isNewLocation: false);
    // A row written before Auth Kit 1.11.0 began storing the answer.
    $unassessedUid = insertRegistryRow((int)$user->id, $unassessed, isNewLocation: null);
    Craft::$app->getUser()->setIdentity($user);

    $byUid = [];

    foreach (Warp::$plugin->getSessions()->getSessionsForUser($user) as $info) {
        $byUid[(string)$info->uid] = $info->isNewLocation;
    }

    // Null is the third state, not a synonym for false: nobody asked.
    expect($byUid[$newUid])->toBeTrue()
        ->and($byUid[$familiarUid])->toBeFalse()
        ->and($byUid[$unassessedUid])->toBeNull();
});

// =============================================================================
// pruning
// =============================================================================

it('forgets a registry row for a token on logout', function() {
    $user = sessionsUser();
    $token = insertCoreSession((int)$user->id);
    insertRegistryRow((int)$user->id, $token);

    Warp::$plugin->getSessions()->pruneToken($token);

    expect(registryExistsForToken($token))->toBeFalse();
});

it('prunes registry rows whose core session no longer exists on garbage collection', function() {
    $user = sessionsUser();
    $live = insertCoreSession((int)$user->id);
    insertRegistryRow((int)$user->id, $live);

    // An orphan: a registry row pointing at a core session that never existed.
    $orphanToken = 'tok-' . bin2hex(random_bytes(32));
    insertRegistryRow((int)$user->id, $orphanToken);

    Warp::$plugin->getSessions()->pruneOrphans();

    expect(registryExistsForToken($live))->toBeTrue()
        ->and(registryExistsForToken($orphanToken))->toBeFalse();
});
