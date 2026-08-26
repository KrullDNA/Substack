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

	<?php /* ------------------------------------------------------------------ */ ?>
	<?php /* Stage 5, sending.                                                 */ ?>
	<?php /* ------------------------------------------------------------------ */ ?>

	<div class="kdna-ab-card">
		<h2 class="kdna-ab-card__heading"><?php esc_html_e( 'Sending', 'kdna-article-broadcast' ); ?></h2>
		<p class="kdna-ab-card__intro">
			<?php esc_html_e( 'How a broadcast behaves when a flagged post is published. Publishing never sends without the Send to subscribers box ticked on the post.', 'kdna-article-broadcast' ); ?>
		</p>

		<form class="kdna-ab-form" @submit.prevent="saveSending()">

			<fieldset class="kdna-ab-modes">
				<legend class="kdna-ab-field__label"><?php esc_html_e( 'Behaviour on publish', 'kdna-article-broadcast' ); ?></legend>

				<label class="kdna-ab-mode">
					<input type="radio" value="draft" x-model="sending.sendMode" />
					<span>
						<strong><?php esc_html_e( 'Draft only', 'kdna-article-broadcast' ); ?></strong>
						<em><?php esc_html_e( 'Create the campaign as a draft and email you a link. You send it in Campaign Monitor. This is the default.', 'kdna-article-broadcast' ); ?></em>
					</span>
				</label>

				<label class="kdna-ab-mode">
					<input type="radio" value="hold" x-model="sending.sendMode" />
					<span>
						<strong><?php esc_html_e( 'Auto-send after a hold window', 'kdna-article-broadcast' ); ?></strong>
						<em><?php esc_html_e( 'Create the draft, then send automatically after the hold window unless you cancel it.', 'kdna-article-broadcast' ); ?></em>
					</span>
				</label>

				<label class="kdna-ab-mode">
					<input type="radio" value="auto" x-model="sending.sendMode" />
					<span>
						<strong><?php esc_html_e( 'Auto-send immediately', 'kdna-article-broadcast' ); ?></strong>
						<em><?php esc_html_e( 'Create the campaign and send it to subscribers straight away.', 'kdna-article-broadcast' ); ?></em>
					</span>
				</label>
			</fieldset>

			<div class="kdna-ab-grid">
				<div class="kdna-ab-field" x-show="sending.sendMode === 'hold'" x-cloak>
					<label class="kdna-ab-field__label" for="kdna-ab-hold"><?php esc_html_e( 'Hold window (minutes)', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-hold" type="number" min="1" step="1" class="small-text" x-model.number="sending.holdWindow" />
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'Default 30. The send can be cancelled from the notification email during this window.', 'kdna-article-broadcast' ); ?></span>
				</div>

				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-notify"><?php esc_html_e( 'Notification email', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-notify" type="email" class="kdna-ab-input" x-model="sending.notifyEmail" :placeholder="sending.adminEmail" />
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'Where campaign notifications are sent. Leave blank to use the site admin email.', 'kdna-article-broadcast' ); ?></span>
				</div>
			</div>

			<div class="kdna-ab-actions">
				<button type="submit" class="button button-primary" :disabled="savingSending">
					<span x-show="! savingSending"><?php esc_html_e( 'Save sending settings', 'kdna-article-broadcast' ); ?></span>
					<span x-show="savingSending" x-cloak><?php esc_html_e( 'Saving...', 'kdna-article-broadcast' ); ?></span>
				</button>
			</div>

			<div class="kdna-ab-notice" :class="'kdna-ab-notice--' + (sendingResult ? sendingResult.type : 'info')" x-show="sendingResult" x-cloak role="status" aria-live="polite">
				<span x-text="sendingResult ? sendingResult.message : ''"></span>
			</div>
		</form>
	</div>

	<?php /* ------------------------------------------------------------------ */ ?>
	<?php /* Stage 4, content assembly.                                        */ ?>
	<?php /* ------------------------------------------------------------------ */ ?>

	<div class="kdna-ab-card">
		<h2 class="kdna-ab-card__heading"><?php esc_html_e( 'Content assembly', 'kdna-article-broadcast' ); ?></h2>
		<p class="kdna-ab-card__intro">
			<?php esc_html_e( 'Article content on this site lives in JetEngine fields, not the post body. Map the fields below, then use the preview to check the assembled values before anything is sent.', 'kdna-article-broadcast' ); ?>
		</p>

		<form class="kdna-ab-form" @submit.prevent="saveContent()">

			<h3 class="kdna-ab-subheading"><?php esc_html_e( 'JetEngine field mapping', 'kdna-article-broadcast' ); ?></h3>
			<div class="kdna-ab-grid">
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-intro"><?php esc_html_e( 'Intro field', 'kdna-article-broadcast' ); ?></label>
					<select id="kdna-ab-intro" class="kdna-ab-select" x-model="content.introField">
						<option value=""><?php esc_html_e( 'Not set', 'kdna-article-broadcast' ); ?></option>
						<template x-for="key in metaFields" :key="'intro-' + key">
							<option :value="key" x-text="key"></option>
						</template>
					</select>
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'The primary teaser source.', 'kdna-article-broadcast' ); ?></span>
				</div>

				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-repeater"><?php esc_html_e( 'Article sections repeater', 'kdna-article-broadcast' ); ?></label>
					<select id="kdna-ab-repeater" class="kdna-ab-select" x-model="content.repeaterField" @change="loadSubfields()">
						<option value=""><?php esc_html_e( 'Not set', 'kdna-article-broadcast' ); ?></option>
						<template x-for="key in metaFields" :key="'rep-' + key">
							<option :value="key" x-text="key"></option>
						</template>
					</select>
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'The repeater holding the article body, one row per section.', 'kdna-article-broadcast' ); ?></span>
				</div>
			</div>

			<div class="kdna-ab-grid kdna-ab-grid--three">
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-body"><?php esc_html_e( 'Body copy sub-field', 'kdna-article-broadcast' ); ?></label>
					<select id="kdna-ab-body" class="kdna-ab-select" x-model="content.repeaterBody" :disabled="loadingSubfields">
						<option value=""><?php esc_html_e( 'Not set', 'kdna-article-broadcast' ); ?></option>
						<template x-for="key in subfields" :key="'body-' + key">
							<option :value="key" x-text="key"></option>
						</template>
					</select>
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-heading"><?php esc_html_e( 'Heading sub-field', 'kdna-article-broadcast' ); ?></label>
					<select id="kdna-ab-heading" class="kdna-ab-select" x-model="content.repeaterHeading" :disabled="loadingSubfields">
						<option value=""><?php esc_html_e( 'Not set', 'kdna-article-broadcast' ); ?></option>
						<template x-for="key in subfields" :key="'head-' + key">
							<option :value="key" x-text="key"></option>
						</template>
					</select>
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-image"><?php esc_html_e( 'Section image sub-field', 'kdna-article-broadcast' ); ?></label>
					<select id="kdna-ab-image" class="kdna-ab-select" x-model="content.repeaterImage" :disabled="loadingSubfields">
						<option value=""><?php esc_html_e( 'Not set', 'kdna-article-broadcast' ); ?></option>
						<template x-for="key in subfields" :key="'img-' + key">
							<option :value="key" x-text="key"></option>
						</template>
					</select>
				</div>
			</div>
			<p class="kdna-ab-field__hint">
				<button type="button" class="button-link" @click="refreshFields()"><?php esc_html_e( 'Refresh field list', 'kdna-article-broadcast' ); ?></button>
				<span x-show="loadingSubfields" x-cloak><?php esc_html_e( 'Loading sub-fields...', 'kdna-article-broadcast' ); ?></span>
			</p>

			<h3 class="kdna-ab-subheading"><?php esc_html_e( 'Teaser', 'kdna-article-broadcast' ); ?></h3>
			<div class="kdna-ab-grid kdna-ab-grid--three">
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-words"><?php esc_html_e( 'Teaser word count', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-words" type="number" min="1" step="1" class="small-text" x-model.number="content.teaserWordCount" />
				</div>
				<div class="kdna-ab-field kdna-ab-field--check">
					<label><input type="checkbox" x-model="content.teaserTrimSentence" /> <?php esc_html_e( 'Trim to the nearest full sentence', 'kdna-article-broadcast' ); ?></label>
				</div>
				<div class="kdna-ab-field kdna-ab-field--check">
					<label><input type="checkbox" x-model="content.previewUseHeading" /> <?php esc_html_e( 'Use the first section heading as preview text', 'kdna-article-broadcast' ); ?></label>
				</div>
			</div>

			<h3 class="kdna-ab-subheading"><?php esc_html_e( 'Image', 'kdna-article-broadcast' ); ?></h3>
			<div class="kdna-ab-grid">
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-placeholder"><?php esc_html_e( 'Placeholder image', 'kdna-article-broadcast' ); ?></label>
					<div class="kdna-ab-input-group">
						<input id="kdna-ab-placeholder" type="text" class="kdna-ab-input" x-model="content.placeholderImage" placeholder="<?php esc_attr_e( 'Attachment ID or image URL', 'kdna-article-broadcast' ); ?>" />
						<button type="button" class="button" @click="chooseImage()"><?php esc_html_e( 'Choose', 'kdna-article-broadcast' ); ?></button>
					</div>
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'Used when a post has no featured image and no section image.', 'kdna-article-broadcast' ); ?></span>
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label"><?php esc_html_e( 'Email image size', 'kdna-article-broadcast' ); ?></label>
					<div class="kdna-ab-dimension">
						<input type="number" min="1" step="1" class="small-text" x-model.number="content.emailImageW" aria-label="<?php esc_attr_e( 'Width', 'kdna-article-broadcast' ); ?>" />
						<span class="kdna-ab-dimension__x">&times;</span>
						<input type="number" min="1" step="1" class="small-text" x-model.number="content.emailImageH" aria-label="<?php esc_attr_e( 'Height', 'kdna-article-broadcast' ); ?>" />
						<span><?php esc_html_e( 'px', 'kdna-article-broadcast' ); ?></span>
					</div>
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'Default 1200 by 630. Regenerate thumbnails after changing this.', 'kdna-article-broadcast' ); ?></span>
				</div>
			</div>

			<h3 class="kdna-ab-subheading"><?php esc_html_e( 'Meta, date and CTA', 'kdna-article-broadcast' ); ?></h3>
			<div class="kdna-ab-grid kdna-ab-grid--three">
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-dateformat"><?php esc_html_e( 'Date format', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-dateformat" type="text" class="kdna-ab-input" x-model="content.dateFormat" placeholder="<?php echo esc_attr( get_option( 'date_format' ) ); ?>" />
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'Leave blank to use the site date format.', 'kdna-article-broadcast' ); ?></span>
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-cta"><?php esc_html_e( 'Global CTA label', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-cta" type="text" class="kdna-ab-input" x-model="content.ctaLabel" />
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'Per-post overrides are set in the Article Broadcast panel.', 'kdna-article-broadcast' ); ?></span>
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-readtime"><?php esc_html_e( 'Read time meta key', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-readtime" type="text" class="kdna-ab-input" x-model="content.readTimeMetaKey" />
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'The KDNA Reading Time meta key. Leave blank to auto-detect. Omitted if inactive.', 'kdna-article-broadcast' ); ?></span>
				</div>
			</div>

			<h3 class="kdna-ab-subheading"><?php esc_html_e( 'UTM tracking', 'kdna-article-broadcast' ); ?></h3>
			<div class="kdna-ab-grid kdna-ab-grid--three">
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-utm-source"><?php esc_html_e( 'utm_source', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-utm-source" type="text" class="kdna-ab-input" x-model="content.utmSource" />
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-utm-medium"><?php esc_html_e( 'utm_medium', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-utm-medium" type="text" class="kdna-ab-input" x-model="content.utmMedium" />
				</div>
				<div class="kdna-ab-field">
					<label class="kdna-ab-field__label" for="kdna-ab-utm-campaign"><?php esc_html_e( 'utm_campaign', 'kdna-article-broadcast' ); ?></label>
					<input id="kdna-ab-utm-campaign" type="text" class="kdna-ab-input" x-model="content.utmCampaign" />
					<span class="kdna-ab-field__hint"><?php esc_html_e( 'Tokens: {slug} and {date} are replaced per send.', 'kdna-article-broadcast' ); ?></span>
				</div>
			</div>

			<div class="kdna-ab-actions">
				<button type="submit" class="button button-primary" :disabled="savingContent">
					<span x-show="! savingContent"><?php esc_html_e( 'Save content settings', 'kdna-article-broadcast' ); ?></span>
					<span x-show="savingContent" x-cloak><?php esc_html_e( 'Saving...', 'kdna-article-broadcast' ); ?></span>
				</button>
			</div>

			<div class="kdna-ab-notice" :class="'kdna-ab-notice--' + (contentResult ? contentResult.type : 'info')" x-show="contentResult" x-cloak role="status" aria-live="polite">
				<span x-text="contentResult ? contentResult.message : ''"></span>
			</div>
		</form>

		<?php /* Preview panel. */ ?>
		<h3 class="kdna-ab-subheading"><?php esc_html_e( 'Preview', 'kdna-article-broadcast' ); ?></h3>
		<p class="kdna-ab-card__intro"><?php esc_html_e( 'Assembles the values for a post so the mapping can be checked without sending anything.', 'kdna-article-broadcast' ); ?></p>
		<div class="kdna-ab-actions">
			<input type="number" min="1" step="1" class="small-text" x-model.number="previewPostId" placeholder="<?php esc_attr_e( 'Post ID', 'kdna-article-broadcast' ); ?>" aria-label="<?php esc_attr_e( 'Post ID to preview', 'kdna-article-broadcast' ); ?>" />
			<button type="button" class="button button-secondary" @click="preview()" :disabled="previewing">
				<span x-show="! previewing"><?php esc_html_e( 'Preview most recent or entered post', 'kdna-article-broadcast' ); ?></span>
				<span x-show="previewing" x-cloak><?php esc_html_e( 'Assembling...', 'kdna-article-broadcast' ); ?></span>
			</button>
		</div>

		<div class="kdna-ab-notice kdna-ab-notice--error" x-show="previewData && previewData.blocked" x-cloak>
			<strong><?php esc_html_e( 'Send would be blocked:', 'kdna-article-broadcast' ); ?></strong>
			<span x-text="previewData ? previewData.message : ''"></span>
		</div>

		<div class="kdna-ab-preview" x-show="previewData && ! previewData.blocked" x-cloak>
			<p class="kdna-ab-preview__post">
				<strong x-text="previewData ? previewData.postTitle : ''"></strong>
				<span x-text="previewData ? ('#' + previewData.postId) : ''"></span>
			</p>
			<div class="kdna-ab-preview__image" x-show="previewData && previewData.assembled && previewData.assembled.image_url">
				<img :src="previewData && previewData.assembled ? previewData.assembled.image_url : ''" alt="" />
				<span class="kdna-ab-preview__imagesource" x-text="previewData && previewData.assembled ? ('<?php echo esc_js( __( 'Image source:', 'kdna-article-broadcast' ) ); ?> ' + previewData.assembled.image_source) : ''"></span>
			</div>
			<table class="kdna-ab-preview__table widefat striped" x-show="previewData && previewData.assembled">
				<tbody>
					<tr><th><?php esc_html_e( 'Subject', 'kdna-article-broadcast' ); ?></th><td x-html="previewData && previewData.assembled ? previewData.assembled.subject : ''"></td></tr>
					<tr><th><?php esc_html_e( 'Preview text', 'kdna-article-broadcast' ); ?></th><td x-html="previewData && previewData.assembled ? previewData.assembled.preview_text : ''"></td></tr>
					<tr><th><?php esc_html_e( 'Title', 'kdna-article-broadcast' ); ?></th><td x-html="previewData && previewData.assembled ? previewData.assembled.title : ''"></td></tr>
					<tr><th><?php esc_html_e( 'Teaser', 'kdna-article-broadcast' ); ?></th><td x-html="previewData && previewData.assembled ? previewData.assembled.teaser : ''"></td></tr>
					<tr><th><?php esc_html_e( 'Category', 'kdna-article-broadcast' ); ?></th><td x-html="previewData && previewData.assembled ? previewData.assembled.category : ''"></td></tr>
					<tr><th><?php esc_html_e( 'Author', 'kdna-article-broadcast' ); ?></th><td x-html="previewData && previewData.assembled ? previewData.assembled.author : ''"></td></tr>
					<tr><th><?php esc_html_e( 'Date', 'kdna-article-broadcast' ); ?></th><td x-text="previewData && previewData.assembled ? previewData.assembled.date : ''"></td></tr>
					<tr><th><?php esc_html_e( 'Read time', 'kdna-article-broadcast' ); ?></th><td x-html="previewData && previewData.assembled ? (previewData.assembled.has_read_time ? previewData.assembled.read_time : '<?php echo esc_js( __( 'omitted, KDNA Reading Time not found', 'kdna-article-broadcast' ) ); ?>') : ''"></td></tr>
					<tr><th><?php esc_html_e( 'CTA label', 'kdna-article-broadcast' ); ?></th><td x-html="previewData && previewData.assembled ? previewData.assembled.cta_label : ''"></td></tr>
					<tr><th><?php esc_html_e( 'Article link', 'kdna-article-broadcast' ); ?></th><td class="kdna-ab-preview__url" x-text="previewData && previewData.assembled ? previewData.assembled.article_link : ''"></td></tr>
					<tr><th><?php esc_html_e( 'CTA link', 'kdna-article-broadcast' ); ?></th><td class="kdna-ab-preview__url" x-text="previewData && previewData.assembled ? previewData.assembled.cta_link : ''"></td></tr>
				</tbody>
			</table>
		</div>
	</div>

	<p class="kdna-ab-footnote">
		<?php
		printf(
			/* translators: %s: plugin version number. */
			esc_html__( 'KDNA Article Broadcast version %s. Stages 1 to 5 complete.', 'kdna-article-broadcast' ),
			esc_html( KDNA_AB_VERSION )
		);
		?>
	</p>

</div>
