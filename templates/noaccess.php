<?php
declare(strict_types=1);

/**
 * No Access page
 * Displayed when user does not have access to the system
 *
 * @var array $_
 */

$loginUrl = $_['loginUrl'] ?? '';
?>

<div class="body-login-container update" style="text-align: center; padding: 50px 20px;">
	<div class="icon-big icon-error"></div>
	<h2><?php p($l->t('You do not have access')); ?></h2>
	<p style="margin: 20px 0;">
		<?php p($l->t('Your account does not have permission to access this system.')); ?>
	</p>
	<p style="margin: 10px 0; color: var(--color-text-lighter);">
		<?php p($l->t('If you believe this is an error, please contact your administrator.')); ?>
	</p>
	<p style="margin: 30px 0;">
		<a href="<?php p($loginUrl); ?>" class="button">
			<?php p($l->t('Try again')); ?>
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

.icon-big.icon-error {
	background-image: url('<?php print_unescaped(image_path('core', 'actions/error.svg')); ?>');
}

.button {
	display: inline-block;
	padding: 12px 30px;
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
	border: none;
	border-radius: var(--border-radius-pill, 100px);
	font-weight: bold;
	text-decoration: none;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.button:hover {
	background-color: var(--color-background-darker, #dbdbdb);
}
</style>
