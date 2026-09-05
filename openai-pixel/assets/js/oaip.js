/**
 * OpenAI Pixel — front-end runtime.
 *
 * PHP emits provider payloads as { provider: "openai", args: [...], event_id }.
 * Each provider registers a handler; the OpenAI handler simply forwards
 * args to window.oaiq (the official SDK queue). Payloads can arrive:
 *   - inline in the footer (window.oaipEvents),
 *   - inside WooCommerce AJAX fragments (#oaip-pending[data-oaip-events]).
 */
(function (window, document) {
	'use strict';

	var oaip = (window.oaip = window.oaip || {});
	var handlers = {};
	var queue = [];
	var seen = loadSeen();

	function loadSeen() {
		try {
			var raw = window.sessionStorage.getItem('oaip_seen');
			return raw ? JSON.parse(raw) : {};
		} catch (e) {
			return {};
		}
	}

	function remember(id) {
		if (!id) {
			return;
		}
		seen[id] = 1;
		try {
			var keys = Object.keys(seen);
			if (keys.length > 200) {
				keys.slice(0, keys.length - 200).forEach(function (k) {
					delete seen[k];
				});
			}
			window.sessionStorage.setItem('oaip_seen', JSON.stringify(seen));
		} catch (e) {
			/* storage unavailable — fine */
		}
	}

	oaip.register = function (providerId, handler) {
		handlers[providerId] = handler;
		oaip.flush();
	};

	oaip.push = function (payload) {
		if (payload && typeof payload === 'object') {
			queue.push(payload);
		}
		oaip.flush();
	};

	oaip.flush = function () {
		if (window.oaipEvents && window.oaipEvents.length) {
			queue = queue.concat(window.oaipEvents);
			window.oaipEvents = [];
		}

		var rest = [];
		queue.forEach(function (payload) {
			var handler = handlers[payload.provider];
			if (!handler) {
				rest.push(payload);
				return;
			}
			// WooCommerce replays cached fragments from sessionStorage on
			// every page load; don't re-send an event we already sent.
			if (payload.event_id && seen[payload.event_id]) {
				return;
			}
			try {
				handler(payload);
				remember(payload.event_id);
			} catch (e) {
				if (window.console && console.error) {
					console.error('[openai-pixel]', e);
				}
			}
		});
		queue = rest;
	};

	/* ------------------------------------------------------------------
	 * OpenAI provider
	 * ---------------------------------------------------------------- */

	oaip.register('openai', function (payload) {
		if (typeof window.oaiq !== 'function' || !payload.args) {
			return;
		}
		window.oaiq.apply(null, payload.args);
	});

	oaip.grantConsent = function () {
		if (typeof window.oaiq === 'function') {
			window.oaiq('consent', true);
		}
	};

	oaip.revokeConsent = function () {
		if (typeof window.oaiq === 'function') {
			window.oaiq('consent', false);
		}
	};

	function consentRequired() {
		var cfg = window.oaipConfig && window.oaipConfig.providers && window.oaipConfig.providers.openai;
		return !!(cfg && cfg.consentMode === 'require');
	}

	// WP Consent API (https://wordpress.org/plugins/wp-consent-api/) integration.
	if (consentRequired()) {
		if (typeof window.wp_has_consent === 'function' && window.wp_has_consent('marketing')) {
			oaip.grantConsent();
		}
		document.addEventListener('wp_listen_for_consent_change', function (e) {
			var detail = e && e.detail;
			if (!detail) {
				return;
			}
			if (detail.marketing === 'allow') {
				oaip.grantConsent();
			} else if (detail.marketing === 'deny') {
				oaip.revokeConsent();
			}
		});
	}

	/* ------------------------------------------------------------------
	 * WooCommerce fragments
	 * ---------------------------------------------------------------- */

	function readPendingFragment() {
		var el = document.getElementById('oaip-pending');
		if (!el) {
			return;
		}
		var raw = el.getAttribute('data-oaip-events');
		if (!raw) {
			return;
		}
		el.removeAttribute('data-oaip-events');
		try {
			var payloads = JSON.parse(raw);
			if (Array.isArray(payloads)) {
				payloads.forEach(oaip.push);
			}
		} catch (e) {
			/* ignore malformed fragment */
		}
	}

	if (window.jQuery) {
		window.jQuery(document.body).on(
			'added_to_cart wc_fragments_refreshed wc_fragments_loaded',
			readPendingFragment
		);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			readPendingFragment();
			oaip.flush();
		});
	} else {
		readPendingFragment();
		oaip.flush();
	}
})(window, document);
