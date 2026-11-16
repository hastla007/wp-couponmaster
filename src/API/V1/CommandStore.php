<?php
/**
 * Storage of the voucher commands: lock, idempotent replies, daily counters, protocol.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

defined( 'ABSPATH' ) || exit;

/**
 * What CommandService keeps between calls. The production store is OptionCommandStore (options of the site under a
 * named database lock); tests use an in-memory store.
 *
 * @since 1.2.0 (contract 1.3.0)
 */
interface CommandStore {
	/**
	 * Takes the lock under which a command is checked, counted and executed (R-8.3b). False when it is held too long.
	 *
	 * @return bool
	 */
	public function lock(): bool;

	/** Releases the lock of lock(). */
	public function unlock(): void;

	/**
	 * The stored reply for an idempotency key: {hash: request fingerprint, result: reply}, null when unknown.
	 *
	 * @param string $key Idempotency key.
	 * @return array{hash:string, result:array<string, mixed>}|null
	 */
	public function reply( string $key ): ?array;

	/**
	 * Stores the reply of a key (kept for at least 90 days).
	 *
	 * @param string               $key    Idempotency key.
	 * @param string               $hash   Request fingerprint.
	 * @param array<string, mixed> $result Reply.
	 * @param string               $day    Calendar day of the site (Y-m-d).
	 */
	public function save_reply( string $key, string $hash, array $result, string $day ): void;

	/**
	 * Count of a bucket ("voucher.issue", "voucher.issue:recipient:17" …) on a calendar day of the site.
	 *
	 * @param string $day    Y-m-d.
	 * @param string $bucket Bucket.
	 * @return int
	 */
	public function count( string $day, string $bucket ): int;

	/**
	 * Adds one to buckets of a day.
	 *
	 * @param string   $day     Y-m-d.
	 * @param string[] $buckets Buckets.
	 */
	public function increment( string $day, array $buckets ): void;

	/**
	 * Appends an entry to the protocol of the day (no codes, no personal data).
	 *
	 * @param string               $day   Y-m-d.
	 * @param array<string, mixed> $entry Entry.
	 */
	public function protocol( string $day, array $entry ): void;

	/**
	 * Protocol entries of a day, oldest first.
	 *
	 * @param string $day Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public function entries( string $day ): array;
}
