<?php
declare(strict_types=1);

/**
 * Login Flow v2 helper page for 3rd-party clients (e.g. the Outlook
 * task pane add-in). Opens the Nextcloud login/grant flow in a popup,
 * polls for the resulting app password, and reports the credentials
 * back to whoever opened this page.
 *
 * @var array $_
 */

$loginV2InitUrl = $_['loginV2InitUrl'] ?? '';
?>

<div class="body-login-container update" style="text-align: center; padding: 50px 20px;">
	<div id="opencase-login-status">
		<h2><?php p($l->t('Connecting to OpenCase')); ?></h2>
		<p style="margin: 20px 0;">
			<?php p($l->t('A login window should open shortly. Please sign in there to continue.')); ?>
		</p>
	</div>
</div>

<style>
.body-login-container.update {
	max-width: 500px;
	margin: 0 auto;
}
</style>

<script nonce="<?php p(\OC::$server->getContentSecurityPolicyNonceManager()->getNonce()); ?>" src="https://appsforoffice.microsoft.com/lib/1.1/hosted/office.js"></script>
<script nonce="<?php p(\OC::$server->getContentSecurityPolicyNonceManager()->getNonce()); ?>">
(function () {
	'use strict';

	var statusEl = document.getElementById('opencase-login-status');

	function setStatus(title, message) {
		statusEl.innerHTML = '<h2>' + title + '</h2><p style="margin: 20px 0;">' + message + '</p>';
	}

	var returnRelayUrl = new URLSearchParams(window.location.search).get('returnRelayUrl');

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

		// Generic popup opened via window.open()
		if (window.opener) {
			window.opener.postMessage({ source: 'opencase-login', result: result }, '*');
			window.close();
			return;
		}
	}

	function poll(pollUrl, pollToken, popup, attempt) {
		attempt = attempt || 0;

		if (attempt > 0 && attempt % 30 === 0 && popup && popup.closed) {
			setStatus(
				<?php print_unescaped(json_encode($l->t('Login cancelled'))); ?>,
				<?php print_unescaped(json_encode($l->t('The login window was closed before sign-in completed.'))); ?>
			);
			reportResult({ status: 'cancelled' });
			return;
		}

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
					if (popup && !popup.closed) {
						popup.close();
					}
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
				poll(pollUrl, pollToken, popup, attempt + 1);
			}, 1500);
		}).catch(function () {
			setTimeout(function () {
				poll(pollUrl, pollToken, popup, attempt + 1);
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
			var popup = window.open(data.login, 'opencase-login', 'width=500,height=700');
			if (!popup) {
				setStatus(
					<?php print_unescaped(json_encode($l->t('Popup blocked'))); ?>,
					<?php print_unescaped(json_encode($l->t('Please allow popups for this site and reload the page.'))); ?>
				);
				reportResult({ status: 'error', message: 'popup_blocked' });
				return;
			}
			poll(data.poll.endpoint, data.poll.token, popup);
		}).catch(function (err) {
			setStatus(
				<?php print_unescaped(json_encode($l->t('Connection error'))); ?>,
				<?php print_unescaped(json_encode($l->t('Could not start the login flow. Please try again.'))); ?>
			);
			reportResult({ status: 'error', message: String(err) });
		});
	}

	if (window.Office && typeof window.Office.onReady === 'function') {
		window.Office.onReady(start);
	} else {
		start();
	}
})();
</script>
