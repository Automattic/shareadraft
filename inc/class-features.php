<?php

namespace Automattic\ShareADraft;

/**
 * Code-level switches for the optional restriction features.
 *
 * A site that never wants IP allowlists, recipient-bound links, or author-chosen
 * viewer caps can turn the feature off with one filter, and every input surface — the Generate modal,
 * the Manage modal, the admin table, REST and ability schemas — hides it, so
 * authors are not offered controls the site has decided against.
 *
 * Deliberately gates minting and UI only, never enforcement: a link that
 * already carries ranges or recipients stays enforced even after the filter is
 * switched off, because disabling a feature must not quietly downgrade an
 * existing restricted link into a plain bearer link. Links minted while a
 * feature is off simply store nothing for it, which the domain already treats
 * as "no restriction".
 */
final class Features {
	/**
	 * Whether per-link IP allowlists can be set and are offered in the UI.
	 * Central ranges from the VIP Dashboard are separate: configuring them is
	 * itself the opt-in, so they are not gated here.
	 */
	public static function ip_allowlist_enabled(): bool {
		/**
		 * Filters whether per-link IP allowlists are available. Return false to
		 * remove the option from every minting surface and screen. Existing
		 * links that already carry ranges remain enforced.
		 *
		 * @param bool $enabled Whether the feature is available. Default true.
		 */
		return (bool) apply_filters( 'shareadraft_ip_allowlist_enabled', true );
	}

	/**
	 * Whether links can be bound to named recipients who verify their email
	 * with a one-time code.
	 */
	public static function recipients_enabled(): bool {
		/**
		 * Filters whether recipient-bound preview links are available. Return
		 * false to remove the option from every minting surface and screen.
		 * Existing links that already carry recipients remain enforced.
		 *
		 * @param bool $enabled Whether the feature is available. Default true.
		 */
		return (bool) apply_filters( 'shareadraft_recipients_enabled', true );
	}

	/**
	 * Whether authors can choose how many people may open a link. Switched
	 * off, every new link gets the site's ceiling
	 * ({@see PreviewRestController::max_uses_limit()}), or no cap when the site
	 * has none, so the two filters compose: a ceiling of 1 with this off makes
	 * every link single-use.
	 */
	public static function max_uses_enabled(): bool {
		/**
		 * Filters whether authors can set a link's maximum uses. Return false
		 * to remove the option from every minting surface; new links then get
		 * the `shareadraft_max_uses_limit` ceiling. Existing links keep the
		 * cap they were created with.
		 *
		 * @param bool $enabled Whether the feature is available. Default true.
		 */
		return (bool) apply_filters( 'shareadraft_max_uses_enabled', true );
	}
}
