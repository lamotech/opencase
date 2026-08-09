<?php
declare(strict_types=1);

/**
 * SAML Logged Out page
 * Displayed after successful SAML Single Logout
 *
 * @var array $_
 */

$loginUrl = $_['loginUrl'] ?? '';
?>

<div class="body-login-container update" style="text-align: center; padding: 50px 20px;">
	<div class="icon-big icon-checkmark"></div>
	<h2><?php p($l->t('You are now logged out')); ?></h2>
	<p style="margin: 20px 0;">
		<?php p($l->t('You have been successfully signed out of your account.')); ?>
	</p>
	<p style="margin: 30px 0;">
		<a href="<?php p($loginUrl); ?>" class="button primary">
			<?php p($l->t('Sign in')); ?>
		</a>
	</p>
</div>

<style>
.body-login-container.update {
	max-width: 500px;
	margin: 0 auto;
}

.icon-big {
	width: 64px;
	height: 64px;
	margin: 0 auto 20px;
	background-size: 64px;
}

.icon-big.icon-checkmark {
	background-image: url('<?php print_unescaped(image_path('core', 'actions/checkmark.svg')); ?>');
	filter: invert(35%) sepia(96%) saturate(1000%) hue-rotate(90deg);
}

.button.primary {
	display: inline-block;
	padding: 12px 30px;
	background-color: var(--color-primary, #0082c9);
	color: var(--color-primary-text, #fff);
	border: none;
	border-radius: var(--border-radius-pill, 100px);
	font-weight: bold;
	text-decoration: none;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.button.primary:hover {
	background-color: var(--color-primary-hover, #0072b3);
}
</style>
