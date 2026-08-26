/**
 * KDNA Article Broadcast, admin settings controller.
 *
 * Defines the Alpine component that backs the settings page. Registration
 * happens on the alpine:init event, so this file can load before or after
 * Alpine itself without any ordering problem.
 *
 * Data such as the AJAX URL, nonce and stored connection arrives on the global
 * kdnaAb object, localised from PHP.
 *
 * @package KDNA_Article_Broadcast
 */
( function () {
	'use strict';

	/**
	 * Sends a POST request to admin-ajax.php.
	 *
	 * @param {string} action WordPress AJAX action name.
	 * @param {string} apiKey API key entered by the user, may be blank.
	 * @return {Promise<Object>} Resolves with the parsed JSON response.
	 */
	function postAction( action, apiKey ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', kdnaAb.nonce );
		body.append( 'api_key', apiKey );

		return fetch( kdnaAb.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * The Alpine component factory.
	 *
	 * @return {Object} Alpine component definition.
	 */
	function kdnaAbSettings() {
		return {
			apiKey: '',
			showKey: false,
			hasKey: !! kdnaAb.hasKey,
			maskedKey: kdnaAb.maskedKey || '',
			testing: false,
			saving: false,
			result: null,
			connection: kdnaAb.connection || { verified: false },

			/**
			 * True while either request is in flight.
			 *
			 * @return {boolean}
			 */
			get busy() {
				return this.testing || this.saving;
			},

			/**
			 * Runs a live connection test without saving.
			 *
			 * @return {void}
			 */
			test: function () {
				if ( this.busy ) {
					return;
				}

				if ( ! this.apiKey && ! this.hasKey ) {
					this.result = { type: 'error', message: kdnaAb.i18n.enterKey };
					return;
				}

				this.testing = true;
				this.result = { type: 'info', message: kdnaAb.i18n.testing };

				postAction( 'kdna_ab_test_connection', this.apiKey )
					.then( this.handleResponse.bind( this ) )
					.catch( this.handleFailure.bind( this ) )
					.finally( function () {
						this.testing = false;
					}.bind( this ) );
			},

			/**
			 * Validates and saves the settings.
			 *
			 * @return {void}
			 */
			save: function () {
				if ( this.busy ) {
					return;
				}

				if ( ! this.apiKey && ! this.hasKey ) {
					this.result = { type: 'error', message: kdnaAb.i18n.enterKey };
					return;
				}

				this.saving = true;
				this.result = { type: 'info', message: kdnaAb.i18n.saving };

				postAction( 'kdna_ab_save_settings', this.apiKey )
					.then( this.handleResponse.bind( this ) )
					.catch( this.handleFailure.bind( this ) )
					.finally( function () {
						this.saving = false;
					}.bind( this ) );
			},

			/**
			 * Handles a parsed AJAX response.
			 *
			 * @param {Object} payload Response from admin-ajax.php.
			 * @return {void}
			 */
			handleResponse: function ( payload ) {
				if ( payload && payload.success && payload.data ) {
					this.result = { type: 'success', message: payload.data.message };

					if ( payload.data.connection ) {
						this.connection = payload.data.connection;
						this.hasKey = true;
						// Field is cleared after a successful save, the key is stored.
						this.apiKey = '';
					}

					// Fire a namespaced event so other scripts can react.
					window.dispatchEvent( new CustomEvent( 'kdna:connection-verified', {
						detail: payload.data
					} ) );
					return;
				}

				var message = ( payload && payload.data && payload.data.message )
					? payload.data.message
					: kdnaAb.i18n.networkError;

				this.result = { type: 'error', message: message };
			},

			/**
			 * Handles a network or parsing failure.
			 *
			 * @return {void}
			 */
			handleFailure: function () {
				this.result = { type: 'error', message: kdnaAb.i18n.networkError };
			},

			/**
			 * Builds the human readable connection summary line.
			 *
			 * @return {string}
			 */
			connectionSummary: function () {
				if ( ! this.connection || ! this.connection.verified ) {
					return '';
				}

				var parts = [];

				if ( this.connection.clients && this.connection.clients.length ) {
					parts.push( this.connection.clients.join( ', ' ) );
				}

				if ( this.connection.checkedText ) {
					parts.push( this.connection.checkedText );
				}

				return parts.join( ' • ' );
			}
		};
	}

	// Expose the factory for the x-data attribute.
	window.kdnaAbSettings = kdnaAbSettings;

	// Register with Alpine when it initialises.
	document.addEventListener( 'alpine:init', function () {
		if ( window.Alpine && typeof window.Alpine.data === 'function' ) {
			window.Alpine.data( 'kdnaAbSettings', kdnaAbSettings );
		}
	} );
} )();
