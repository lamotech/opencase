<?php
declare(strict_types=1);

use OC\Security\CSP\ContentSecurityPolicyNonceManager;

/**
 * Login Flow v2 helper page for 3rd-party clients (e.g. the Outlook
 * task pane add-in). Shows a real, user-clickable link to the Nextcloud
 * login/grant flow, polls for the resulting app password, and reports the
 * credentials back to whoever opened this page.
 *
 * This page is itself usually shown inside an Office dialog
 * (Office.context.ui.displayDialogAsync). On Outlook desktop that dialog is
 * an embedded WebView2, which:
 *  - blocks window.open() calls that aren't triggered by a direct user
 *    click (e.g. one fired from a fetch().then() callback, as this page
 *    used to do), reported to the user as "popup blocked";
 *  - also refuses to display the login/grant flow in a same-origin
 *    <iframe>, reported as "This content is blocked" even though Nextcloud's
 *    own CSP/X-Frame-Options headers permit same-origin framing.
 * A plain <a target="_blank"> the user clicks themselves sidesteps both:
 * native link navigation is respected even by hosts that block scripted
 * popups and embedded frames.
 *
 * @var array $_
 */

$loginV2InitUrl = $_['loginV2InitUrl'] ?? '';
$relayUrl = $_['relayUrl'] ?? null;
$relayRejected = (bool)($_['relayRejected'] ?? false);
$ownOrigin = $_['ownOrigin'] ?? '';
$cspNonceManager = \OCP\Server::get(ContentSecurityPolicyNonceManager::class);
?>

<div class="body-login-container update" style="text-align: center; padding: 50px 20px;">
	<div id="opencase-login-status">
		<h2><?php p($l->t('Connecting to OpenCase')); ?></h2>
		<p style="margin: 20px 0;">
			<?php p($l->t('Preparing the sign-in flow…')); ?>
		</p>
	</div>
	<p style="margin: 20px 0;">
		<a id="opencase-login-link" href="#" target="_blank" rel="noopener" hidden
			style="display: inline-block; padding: 10px 24px; background: #0082c9; color: #fff; text-decoration: none; border-radius: 4px;">
			<?php p($l->t('Sign in to OpenCase')); ?>
		</a>
	</p>
</div>

<style>
.body-login-container.update {
	max-width: 500px;
	margin: 0 auto;
}
</style>

<script nonce="<?php p($cspNonceManager->getNonce()); ?>" src="https://appsforoffice.microsoft.com/lib/1.1/hosted/office.js"></script>
<script nonce="<?php p($cspNonceManager->getNonce()); ?>">
(function () {
	'use strict';

	var statusEl = document.getElementById('opencase-login-status');
	var linkEl = document.getElementById('opencase-login-link');

	function setStatus(title, message) {
		statusEl.innerHTML = '<h2>' + title + '</h2><p style="margin: 20px 0;">' + message + '</p>';
	}

	// SECURITY: the relay URL is resolved server-side (AuthController) against
	// the configured add-in origins and injected here already approved. Never
	// read it back out of location.search — the payload below contains a
	// working Nextcloud app password, and an attacker-supplied relay URL would
	// hand it straight to them from a page on the genuine Nextcloud origin.
	var returnRelayUrl = <?php print_unescaped(json_encode($relayUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>;
	var ownOrigin = <?php print_unescaped(json_encode($ownOrigin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>;
	var relayRejected = <?php print_unescaped(json_encode($relayRejected)); ?>;

	function reportResult(result) {
		var payload = JSON.stringify(result);

		// The Outlook add-in runs on a different origin (and usually a
		// different port) than this Nextcloud-hosted page, so
		// Office.context.ui.messageParent() cannot post directly to it
		// (postMessage requires the target origin to match exactly).
		// Instead, hand off to a same-origin relay page in the add-in
		// that can call messageParent() itself. The add-in tells us its
		// exact URL (returnRelayUrl) rather than us guessing a path under
		// its origin, since where login-relay.html lives is an add-in
		// deployment detail this page shouldn't need to know.
		if (returnRelayUrl) {
			window.location.href = returnRelayUrl + '#' + encodeURIComponent(payload);
			return;
		}

		// Office.js task pane dialog (same-origin fallback)
		if (window.Office && window.Office.context && window.Office.context.ui
			&& typeof window.Office.context.ui.messageParent === 'function') {
			window.Office.context.ui.messageParent(payload);
			return;
		}

		// Generic popup opened via window.open(). Targeted at this server's
		// own origin, never '*' — the message carries an app password, and a
		// wildcard target would deliver it to whatever page opened us.
		if (window.opener && ownOrigin) {
			window.opener.postMessage({ source: 'opencase-login', result: result }, ownOrigin);
			window.close();
			return;
		}
	}

	function poll(pollUrl, pollToken, attempt) {
		attempt = attempt || 0;

		var body = 'token=' + encodeURIComponent(pollToken);

		fetch(pollUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'OCS-APIREQUEST': 'true',
			},
			body: body,
		}).then(function (response) {
			if (response.status === 200) {
				return response.json().then(function (creds) {
					linkEl.hidden = true;
					setStatus(
						<?php print_unescaped(json_encode($l->t('Connected'))); ?>,
						<?php print_unescaped(json_encode($l->t('You are now signed in. You can close this window.'))); ?>
					);
					reportResult({
						status: 'success',
						server: creds.server,
						loginName: creds.loginName,
						appPassword: creds.appPassword,
					});
				});
			}

			// 404 = not ready yet, keep polling
			setTimeout(function () {
				poll(pollUrl, pollToken, attempt + 1);
			}, 1500);
		}).catch(function () {
			setTimeout(function () {
				poll(pollUrl, pollToken, attempt + 1);
			}, 1500);
		});
	}

	function start() {
		fetch(<?php print_unescaped(json_encode($loginV2InitUrl)); ?>, {
			method: 'POST',
			headers: { 'OCS-APIREQUEST': 'true' },
		}).then(function (response) {
			return response.json();
		}).then(function (data) {
			setStatus(
				<?php print_unescaped(json_encode($l->t('Sign-in required'))); ?>,
				<?php print_unescaped(json_encode($l->t('Click the button below to sign in. This page will continue automatically once you\'re done.'))); ?>
			);
			linkEl.href = data.login;
			linkEl.hidden = false;
			linkEl.addEventListener('click', function () {
				setStatus(
					<?php print_unescaped(json_encode($l->t('Waiting for sign-in'))); ?>,
					<?php print_unescaped(json_encode($l->t('Complete sign-in in the window that just opened, then come back here.'))); ?>
				);
			}, { once: true });
			poll(data.poll.endpoint, data.poll.token);
		}).catch(function (err) {
			setStatus(
				<?php print_unescaped(json_encode($l->t('Connection error'))); ?>,
				<?php print_unescaped(json_encode($l->t('Could not start the login flow. Please try again.'))); ?>
			);
			reportResult({ status: 'error', message: String(err) });
		});
	}

	function refuse() {
		setStatus(
			<?php print_unescaped(json_encode($l->t('Sign-in blocked'))); ?>,
			<?php print_unescaped(json_encode($l->t('This sign-in request came from an address that is not configured as an OpenCase add-in. Contact your administrator.'))); ?>
		);
	}

	// A returnRelayUrl was supplied but is not an allowed add-in origin. Do
	// not start the login flow at all — there would be nowhere safe to send
	// the resulting credentials.
	if (relayRejected) {
		refuse();
	} else if (window.Office && typeof window.Office.onReady === 'function') {
		window.Office.onReady(start);
	} else {
		start();
	}
})();
</script>
