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
<div class="wrap kdna-ab-wrap" x-data="kdnaAbSettings()" x-init="init()">

	<h1 class="kdna-ab-title"><?php esc_html_e( 'KDNA Article Broadcast', 'kdna-article-broadcast' ); ?></h1>
	<p class="kdna-ab-subtitle">
		<?php esc_html_e( 'Campaign Monitor edition. Connect your account below, then bind this site to a client, list and templates.', 'kdna-article-broadcast' ); ?>
	</p>

	<?php /* ------------------------------------------------------------------ */ ?>
	<?php /* Stage 1, Campaign Monitor connection.                              */ ?>
	<?php /* ------------------------------------------------------------------ */ ?>
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
				<button type="button" class="button button-secondary" @click="test()" :disabled="busy">
					<span x-show="! testing"><?php esc_html_e( 'Test connection', 'kdna-article-broadcast' ); ?></span>
					<span x-show="testing" x-cloak><?php esc_html_e( 'Testing...', 'kdna-article-broadcast' ); ?></span>
				</button>

				<button type="submit" class="button button-primary" :disabled="busy">
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

	<?php /* ------------------------------------------------------------------ */ ?>
	<?php /* Stage 2, client, list and template selection.                     */ ?>
	<?php /* ------------------------------------------------------------------ */ ?>

	<div class="kdna-ab-card" x-show="! connection.verified" x-cloak>
		<p class="kdna-ab-card__intro">
			<?php esc_html_e( 'Verify the connection above to choose a client, list and templates.', 'kdna-article-broadcast' ); ?>
		</p>
	</div>

	<div class="kdna-ab-card" x-show="connection.verified" x-cloak>
		<div class="kdna-ab-card__header">
			<h2 class="kdna-ab-card__heading"><?php esc_html_e( 'Client, list and templates', 'kdna-article-broadcast' ); ?></h2>
			<button type="button" class="button button-secondary kdna-ab-refresh" @click="refresh()" :disabled="busy2">
				<span x-show="! refreshing"><?php esc_html_e( 'Refresh from Campaign Monitor', 'kdna-article-broadcast' ); ?></span>
				<span x-show="refreshing" x-cloak><?php esc_html_e( 'Refreshing...', 'kdna-article-broadcast' ); ?></span>
			</button>
		</div>
		<p class="kdna-ab-card__intro">
			<?php esc_html_e( 'This is an agency account, so pick which client this site sends from, then its list and the two templates. Data is cached for one hour. Use Refresh to fetch it again.', 'kdna-article-broadcast' ); ?>
		</p>

		<form class="kdna-ab-form" @submit.prevent="saveSelection()">

			<div class="kdna-ab-grid">

				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-client">
						<?php esc_html_e( 'Client', 'kdna-article-broadcast' ); ?>
					</label>
					<select id="kdna-ab-client" class="kdna-ab-select" x-model="selection.clientId" @change="onClientChange()" :disabled="loadingClients">
						<option value=""><?php esc_html_e( 'Select a client', 'kdna-article-broadcast' ); ?></option>
						<template x-for="c in clients" :key="c.id">
							<option :value="c.id" x-text="c.name"></option>
						</template>
					</select>
					<p class="kdna-ab-field__hint" x-show="loadingClients" x-cloak><?php esc_html_e( 'Loading clients...', 'kdna-article-broadcast' ); ?></p>
				</div>

				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-list">
						<?php esc_html_e( 'Subscriber list', 'kdna-article-broadcast' ); ?>
					</label>
					<select id="kdna-ab-list" class="kdna-ab-select" x-model="selection.listId" :disabled="! selection.clientId || loadingClientData">
						<option value=""><?php esc_html_e( 'Select a list', 'kdna-article-broadcast' ); ?></option>
						<template x-for="l in lists" :key="l.id">
							<option :value="l.id" x-text="l.name"></option>
						</template>
					</select>
					<p class="kdna-ab-field__hint" x-show="loadingClientData" x-cloak><?php esc_html_e( 'Loading lists and templates...', 'kdna-article-broadcast' ); ?></p>
				</div>

			</div>

			<div class="kdna-ab-grid">

				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-template-single">
						<?php esc_html_e( 'Single article template', 'kdna-article-broadcast' ); ?>
					</label>
					<select id="kdna-ab-template-single" class="kdna-ab-select" x-model="selection.templateSingle" :disabled="! selection.clientId || loadingClientData">
						<option value=""><?php esc_html_e( 'Select a template', 'kdna-article-broadcast' ); ?></option>
						<template x-for="t in templates" :key="t.id">
							<option :value="t.id" x-text="t.name"></option>
						</template>
					</select>
					<div class="kdna-ab-template-preview" x-show="templatePreview('single')" x-cloak>
						<img :src="templatePreview('single')" alt="<?php esc_attr_e( 'Template preview', 'kdna-article-broadcast' ); ?>" loading="lazy" />
					</div>
				</div>

				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-template-digest">
						<?php esc_html_e( 'Weekly digest template', 'kdna-article-broadcast' ); ?>
					</label>
					<select id="kdna-ab-template-digest" class="kdna-ab-select" x-model="selection.templateDigest" :disabled="! selection.clientId || loadingClientData">
						<option value=""><?php esc_html_e( 'Select a template', 'kdna-article-broadcast' ); ?></option>
						<template x-for="t in templates" :key="t.id">
							<option :value="t.id" x-text="t.name"></option>
						</template>
					</select>
					<div class="kdna-ab-template-preview" x-show="templatePreview('digest')" x-cloak>
						<img :src="templatePreview('digest')" alt="<?php esc_attr_e( 'Template preview', 'kdna-article-broadcast' ); ?>" loading="lazy" />
					</div>
				</div>

			</div>

			<h3 class="kdna-ab-subheading"><?php esc_html_e( 'From and reply to', 'kdna-article-broadcast' ); ?></h3>
			<p class="kdna-ab-card__intro">
				<?php esc_html_e( 'Campaign Monitor verifies sender addresses at send time and does not list them through the API, so these are checked by format here. Use an address that is verified for this client.', 'kdna-article-broadcast' ); ?>
			</p>

			<div class="kdna-ab-grid kdna-ab-grid--three">
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-from-name"><?php esc_html_e( 'From name', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-from-name" type="text" class="kdna-ab-input" x-model="selection.fromName" />
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-from-email"><?php esc_html_e( 'From email', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-from-email" type="email" class="kdna-ab-input" x-model="selection.fromEmail" />
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-reply-to"><?php esc_html_e( 'Reply to', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-reply-to" type="email" class="kdna-ab-input" x-model="selection.replyTo" />
				</div>
			</div>

			<?php /* Template region mapping. */ ?>
			<h3 class="kdna-ab-subheading"><?php esc_html_e( 'Template region mapping', 'kdna-article-broadcast' ); ?></h3>
			<div class="kdna-ab-notice kdna-ab-notice--info">
				<?php esc_html_e( 'Campaign Monitor does not list a template\'s editable regions through its API, and content is matched to the template by position. For each field, tick Include and set the position it occupies among regions of the same type in your template. The order preview below each table shows the result. Changing a template later means adjusting these numbers, not the code.', 'kdna-article-broadcast' ); ?>
			</div>

			<?php /* Single article mapping. */ ?>
			<h4 class="kdna-ab-mapping-title"><?php esc_html_e( 'Single article template', 'kdna-article-broadcast' ); ?></h4>
			<table class="kdna-ab-mapping widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Field', 'kdna-article-broadcast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Region type', 'kdna-article-broadcast' ); ?></th>
						<th scope="col" class="kdna-ab-col-narrow"><?php esc_html_e( 'Include', 'kdna-article-broadcast' ); ?></th>
						<th scope="col" class="kdna-ab-col-narrow"><?php esc_html_e( 'Position', 'kdna-article-broadcast' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="f in fields.single" :key="f.key">
						<tr>
							<td x-text="f.label"></td>
							<td><span class="kdna-ab-type" x-text="typeLabels[f.type]"></span></td>
							<td class="kdna-ab-col-narrow">
								<input type="checkbox" x-model="selection.mappingSingle[f.key].include" />
							</td>
							<td class="kdna-ab-col-narrow">
								<input type="number" min="1" step="1" class="small-text" x-model.number="selection.mappingSingle[f.key].position" :disabled="! selection.mappingSingle[f.key].include" />
							</td>
						</tr>
					</template>
				</tbody>
			</table>
			<div class="kdna-ab-order">
				<span class="kdna-ab-order__label"><?php esc_html_e( 'Region order:', 'kdna-article-broadcast' ); ?></span>
				<template x-for="group in orderPreview('single')" :key="group.type">
					<span class="kdna-ab-order__group"><strong x-text="group.typeLabel + ':'"></strong> <span x-text="group.items.length ? group.items.join(', ') : '<?php echo esc_js( __( 'none', 'kdna-article-broadcast' ) ); ?>'"></span></span>
				</template>
			</div>

			<?php /* Digest top level mapping. */ ?>
			<h4 class="kdna-ab-mapping-title"><?php esc_html_e( 'Weekly digest, top level regions', 'kdna-article-broadcast' ); ?></h4>
			<table class="kdna-ab-mapping widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Field', 'kdna-article-broadcast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Region type', 'kdna-article-broadcast' ); ?></th>
						<th scope="col" class="kdna-ab-col-narrow"><?php esc_html_e( 'Include', 'kdna-article-broadcast' ); ?></th>
						<th scope="col" class="kdna-ab-col-narrow"><?php esc_html_e( 'Position', 'kdna-article-broadcast' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="f in fields.digestTop" :key="f.key">
						<tr>
							<td x-text="f.label"></td>
							<td><span class="kdna-ab-type" x-text="typeLabels[f.type]"></span></td>
							<td class="kdna-ab-col-narrow">
								<input type="checkbox" x-model="selection.mappingDigestTop[f.key].include" />
							</td>
							<td class="kdna-ab-col-narrow">
								<input type="number" min="1" step="1" class="small-text" x-model.number="selection.mappingDigestTop[f.key].position" :disabled="! selection.mappingDigestTop[f.key].include" />
							</td>
						</tr>
					</template>
				</tbody>
			</table>
			<div class="kdna-ab-order">
				<span class="kdna-ab-order__label"><?php esc_html_e( 'Region order:', 'kdna-article-broadcast' ); ?></span>
				<template x-for="group in orderPreview('digestTop')" :key="group.type">
					<span class="kdna-ab-order__group"><strong x-text="group.typeLabel + ':'"></strong> <span x-text="group.items.length ? group.items.join(', ') : '<?php echo esc_js( __( 'none', 'kdna-article-broadcast' ) ); ?>'"></span></span>
				</template>
			</div>

			<?php /* Digest repeater mapping. */ ?>
			<h4 class="kdna-ab-mapping-title"><?php esc_html_e( 'Weekly digest, repeater regions, one block per article', 'kdna-article-broadcast' ); ?></h4>
			<table class="kdna-ab-mapping widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Field', 'kdna-article-broadcast' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Region type', 'kdna-article-broadcast' ); ?></th>
						<th scope="col" class="kdna-ab-col-narrow"><?php esc_html_e( 'Include', 'kdna-article-broadcast' ); ?></th>
						<th scope="col" class="kdna-ab-col-narrow"><?php esc_html_e( 'Position', 'kdna-article-broadcast' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<template x-for="f in fields.digestRepeater" :key="f.key">
						<tr>
							<td x-text="f.label"></td>
							<td><span class="kdna-ab-type" x-text="typeLabels[f.type]"></span></td>
							<td class="kdna-ab-col-narrow">
								<input type="checkbox" x-model="selection.mappingDigestRepeater[f.key].include" />
							</td>
							<td class="kdna-ab-col-narrow">
								<input type="number" min="1" step="1" class="small-text" x-model.number="selection.mappingDigestRepeater[f.key].position" :disabled="! selection.mappingDigestRepeater[f.key].include" />
							</td>
						</tr>
					</template>
				</tbody>
			</table>
			<div class="kdna-ab-order">
				<span class="kdna-ab-order__label"><?php esc_html_e( 'Region order:', 'kdna-article-broadcast' ); ?></span>
				<template x-for="group in orderPreview('digestRepeater')" :key="group.type">
					<span class="kdna-ab-order__group"><strong x-text="group.typeLabel + ':'"></strong> <span x-text="group.items.length ? group.items.join(', ') : '<?php echo esc_js( __( 'none', 'kdna-article-broadcast' ) ); ?>'"></span></span>
				</template>
			</div>

			<div class="kdna-ab-actions">
				<button type="submit" class="button button-primary" :disabled="busy2">
					<span x-show="! savingSelection"><?php esc_html_e( 'Save selection and mapping', 'kdna-article-broadcast' ); ?></span>
					<span x-show="savingSelection" x-cloak><?php esc_html_e( 'Saving...', 'kdna-article-broadcast' ); ?></span>
				</button>
			</div>

			<div
				class="kdna-ab-notice"
				:class="'kdna-ab-notice--' + (selectionResult ? selectionResult.type : 'info')"
				x-show="selectionResult"
				x-cloak
				role="status"
				aria-live="polite"
			>
				<span x-text="selectionResult ? selectionResult.message : ''"></span>
			</div>

		</form>
	</div>

	<p class="kdna-ab-footnote">
		<?php
		printf(
			/* translators: %s: plugin version number. */
			esc_html__( 'KDNA Article Broadcast version %s. Stages 1 and 2 complete.', 'kdna-article-broadcast' ),
			esc_html( KDNA_AB_VERSION )
		);
		?>
	</p>

</div>
