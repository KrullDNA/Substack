<?php
/**
 * Settings page view template.
 *
 * Rendered by KDNA_AB_Settings::render_page(). All dynamic data reaches the
 * browser through the localised kdnaAb object, so this template stays static
 * and every echoed value is escaped. The interactive behaviour is an Alpine
 * component, kdnaAbSettings, defined in admin/admin-script.js.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap kdna-ab-wrap" x-data="kdnaAbSettings()">

	<h1 class="kdna-ab-title"><?php esc_html_e( 'KDNA Article Broadcast', 'kdna-article-broadcast' ); ?></h1>
	<p class="kdna-ab-subtitle">
		<?php esc_html_e( 'Campaign Monitor edition. Connect your account below, then move through the build stages.', 'kdna-article-broadcast' ); ?>
	</p>

	<div class="kdna-ab-card">
		<h2 class="kdna-ab-card__heading"><?php esc_html_e( 'Campaign Monitor connection', 'kdna-article-broadcast' ); ?></h2>
		<p class="kdna-ab-card__intro">
			<?php esc_html_e( 'Enter the API key for your Campaign Monitor account. Use Test connection to check it against the live API, then Save.', 'kdna-article-broadcast' ); ?>
		</p>

		<form class="kdna-ab-form" @submit.prevent="save()">

			<div class="kdna-ab-field">
				<label class="kdna-ab-field__label" for="kdna-ab-api-key">
					<?php esc_html_e( 'API key', 'kdna-article-broadcast' ); ?>
				</label>

				<div class="kdna-ab-input-group">
					<input
						id="kdna-ab-api-key"
						class="regular-text kdna-ab-input"
						name="api_key"
						autocomplete="off"
						spellcheck="false"
						x-model="apiKey"
						:type="showKey ? 'text' : 'password'"
						:placeholder="hasKey ? maskedKey : ''"
					/>
					<button
						type="button"
						class="button kdna-ab-input-group__toggle"
						@click="showKey = ! showKey"
						:aria-pressed="showKey.toString()"
						x-text="showKey ? '<?php echo esc_js( __( 'Hide', 'kdna-article-broadcast' ) ); ?>' : '<?php echo esc_js( __( 'Show', 'kdna-article-broadcast' ) ); ?>'"
					></button>
				</div>

				<p class="kdna-ab-field__hint" x-show="hasKey" x-cloak>
					<?php esc_html_e( 'A key is already saved. Leave this blank to keep it, or enter a new key to replace it.', 'kdna-article-broadcast' ); ?>
				</p>
			</div>

			<div class="kdna-ab-actions">
				<button
					type="button"
					class="button button-secondary"
					@click="test()"
					:disabled="busy"
				>
					<span x-show="! testing"><?php esc_html_e( 'Test connection', 'kdna-article-broadcast' ); ?></span>
					<span x-show="testing" x-cloak><?php esc_html_e( 'Testing...', 'kdna-article-broadcast' ); ?></span>
				</button>

				<button
					type="submit"
					class="button button-primary"
					:disabled="busy"
				>
					<span x-show="! saving"><?php esc_html_e( 'Save settings', 'kdna-article-broadcast' ); ?></span>
					<span x-show="saving" x-cloak><?php esc_html_e( 'Saving...', 'kdna-article-broadcast' ); ?></span>
				</button>
			</div>

			<div
				class="kdna-ab-notice"
				:class="'kdna-ab-notice--' + (result ? result.type : 'info')"
				x-show="result"
				x-cloak
				role="status"
				aria-live="polite"
			>
				<span x-text="result ? result.message : ''"></span>
			</div>

		</form>

		<div class="kdna-ab-connection" x-show="connection && connection.verified" x-cloak>
			<span class="kdna-ab-connection__badge"><?php esc_html_e( 'Connected', 'kdna-article-broadcast' ); ?></span>
			<span class="kdna-ab-connection__meta" x-text="connectionSummary()"></span>
		</div>
	</div>

	<p class="kdna-ab-footnote">
		<?php
		printf(
			/* translators: %s: plugin version number. */
			esc_html__( 'KDNA Article Broadcast version %s. Stage 1, plugin foundation and API connection.', 'kdna-article-broadcast' ),
			esc_html( KDNA_AB_VERSION )
		);
		?>
	</p>

</div>
