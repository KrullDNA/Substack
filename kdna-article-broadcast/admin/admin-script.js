/**
 * KDNA Article Broadcast, admin settings controller.
 *
 * Defines the Alpine component that backs the settings page. Registration
 * happens on the alpine:init event, so this file can load before or after
 * Alpine itself without any ordering problem.
 *
 * Stage 1 covers the connection test and key storage. Stage 2 adds the client,
 * list and template selection, cached against the live API, and the positional
 * template region mapping.
 *
 * Data such as the AJAX URL, nonce, saved selection and field definitions
 * arrives on the global kdnaAb object, localised from PHP.
 *
 * @package KDNA_Article_Broadcast
 */
( function () {
	'use strict';

	/**
	 * Sends a POST request to admin-ajax.php.
	 *
	 * @param {string} action WordPress AJAX action name.
	 * @param {Object} params Extra fields to send.
	 * @return {Promise<Object>} Resolves with the parsed JSON response.
	 */
	function request( action, params ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', kdnaAb.nonce );

		params = params || {};
		Object.keys( params ).forEach( function ( name ) {
			body.append( name, params[ name ] );
		} );

		return fetch( kdnaAb.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Finds an item by id in a list of { id, name } objects.
	 *
	 * @param {Array}  list List to search.
	 * @param {string} id   Id to find.
	 * @return {Object|null}
	 */
	function findById( list, id ) {
		var found = null;
		( list || [] ).forEach( function ( item ) {
			if ( item.id === id ) {
				found = item;
			}
		} );
		return found;
	}

	/**
	 * The Alpine component factory.
	 *
	 * @return {Object} Alpine component definition.
	 */
	function kdnaAbSettings() {
		return {
			// Stage 1 state.
			apiKey: '',
			showKey: false,
			hasKey: !! kdnaAb.hasKey,
			maskedKey: kdnaAb.maskedKey || '',
			testing: false,
			saving: false,
			result: null,
			connection: kdnaAb.connection || { verified: false },

			// Stage 2 state.
			clients: [],
			lists: [],
			templates: [],
			fields: kdnaAb.fields || { single: [], digestTop: [], digestRepeater: [] },
			typeLabels: kdnaAb.typeLabels || {},
			selection: JSON.parse( JSON.stringify( kdnaAb.selection || {} ) ),
			loadingClients: false,
			loadingClientData: false,
			savingSelection: false,
			refreshing: false,
			selectionResult: null,

			// Stage 4 state.
			content: JSON.parse( JSON.stringify( kdnaAb.content || {} ) ),
			metaFields: kdnaAb.metaFields || [],
			subfields: [],
			loadingSubfields: false,
			savingContent: false,
			contentResult: null,
			previewing: false,
			previewData: null,
			previewPostId: null,

			// Stage 5 state.
			sending: JSON.parse( JSON.stringify( kdnaAb.sending || {} ) ),
			savingSending: false,
			sendingResult: null,

			/**
			 * True while a Stage 1 request is in flight.
			 *
			 * @return {boolean}
			 */
			get busy() {
				return this.testing || this.saving;
			},

			/**
			 * True while a Stage 2 request is in flight.
			 *
			 * @return {boolean}
			 */
			get busy2() {
				return this.loadingClients || this.loadingClientData || this.savingSelection || this.refreshing;
			},

			/**
			 * Component entry point, called from x-init.
			 *
			 * @return {void}
			 */
			init: function () {
				if ( this.connection && this.connection.verified ) {
					this.loadClients();
				}

				if ( this.content && this.content.repeaterField ) {
					this.loadSubfields();
				}
			},

			/*
			 * -----------------------------------------------------------------
			 * Stage 1
			 * -----------------------------------------------------------------
			 */

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

				request( 'kdna_ab_test_connection', { api_key: this.apiKey } )
					.then( this.handleConnection.bind( this ) )
					.catch( this.handleConnectionFailure.bind( this ) )
					.finally( function () {
						this.testing = false;
					}.bind( this ) );
			},

			/**
			 * Validates and saves the API key.
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

				request( 'kdna_ab_save_settings', { api_key: this.apiKey } )
					.then( function ( payload ) {
						this.handleConnection( payload );

						// A verified save unlocks Stage 2, so load the clients.
						if ( payload && payload.success && this.connection.verified && ! this.clients.length ) {
							this.loadClients();
						}
					}.bind( this ) )
					.catch( this.handleConnectionFailure.bind( this ) )
					.finally( function () {
						this.saving = false;
					}.bind( this ) );
			},

			/**
			 * Handles a Stage 1 AJAX response.
			 *
			 * @param {Object} payload Response from admin-ajax.php.
			 * @return {void}
			 */
			handleConnection: function ( payload ) {
				if ( payload && payload.success && payload.data ) {
					this.result = { type: 'success', message: payload.data.message };

					if ( payload.data.connection ) {
						this.connection = payload.data.connection;
						this.hasKey = true;
						this.apiKey = '';
					}

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
			 * Handles a Stage 1 network failure.
			 *
			 * @return {void}
			 */
			handleConnectionFailure: function () {
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
			},

			/*
			 * -----------------------------------------------------------------
			 * Stage 2
			 * -----------------------------------------------------------------
			 */

			/**
			 * Loads the account clients, then the saved client data if any.
			 *
			 * @return {void}
			 */
			loadClients: function () {
				this.loadingClients = true;

				request( 'kdna_ab_get_clients', {} )
					.then( function ( payload ) {
						if ( payload && payload.success && payload.data ) {
							this.clients = payload.data.clients || [];

							if ( this.selection.clientId ) {
								this.loadClientData();
							}
						} else {
							this.selectionResult = { type: 'error', message: this.messageFrom( payload ) };
						}
					}.bind( this ) )
					.catch( function () {
						this.selectionResult = { type: 'error', message: kdnaAb.i18n.networkError };
					}.bind( this ) )
					.finally( function () {
						this.loadingClients = false;
					}.bind( this ) );
			},

			/**
			 * Handles a user changing the client dropdown.
			 *
			 * The old list and templates belong to the previous client, so they
			 * are cleared before the new client data loads.
			 *
			 * @return {void}
			 */
			onClientChange: function () {
				var client = findById( this.clients, this.selection.clientId );
				this.selection.clientName = client ? client.name : '';

				this.selection.listId = '';
				this.selection.templateSingle = '';
				this.selection.templateDigest = '';
				this.lists = [];
				this.templates = [];

				if ( this.selection.clientId ) {
					this.loadClientData();
				}
			},

			/**
			 * Loads the lists and templates for the selected client.
			 *
			 * @return {void}
			 */
			loadClientData: function () {
				if ( ! this.selection.clientId ) {
					return;
				}

				this.loadingClientData = true;

				request( 'kdna_ab_get_client_data', { client_id: this.selection.clientId } )
					.then( function ( payload ) {
						if ( payload && payload.success && payload.data ) {
							this.lists = payload.data.lists || [];
							this.templates = payload.data.templates || [];
						} else {
							this.selectionResult = { type: 'error', message: this.messageFrom( payload ) };
						}
					}.bind( this ) )
					.catch( function () {
						this.selectionResult = { type: 'error', message: kdnaAb.i18n.networkError };
					}.bind( this ) )
					.finally( function () {
						this.loadingClientData = false;
					}.bind( this ) );
			},

			/**
			 * Clears the cache and refetches everything from Campaign Monitor.
			 *
			 * @return {void}
			 */
			refresh: function () {
				if ( this.busy2 ) {
					return;
				}

				this.refreshing = true;
				this.selectionResult = { type: 'info', message: kdnaAb.i18n.refreshing };

				request( 'kdna_ab_refresh_cache', {} )
					.then( function ( payload ) {
						if ( payload && payload.success && payload.data ) {
							this.clients = payload.data.clients || [];
							this.selectionResult = { type: 'success', message: payload.data.message };

							if ( this.selection.clientId ) {
								this.loadClientData();
							}
						} else {
							this.selectionResult = { type: 'error', message: this.messageFrom( payload ) };
						}
					}.bind( this ) )
					.catch( function () {
						this.selectionResult = { type: 'error', message: kdnaAb.i18n.networkError };
					}.bind( this ) )
					.finally( function () {
						this.refreshing = false;
					}.bind( this ) );
			},

			/**
			 * Returns the screenshot URL for the chosen single or digest template.
			 *
			 * @param {string} which Either "single" or "digest".
			 * @return {string}
			 */
			templatePreview: function ( which ) {
				var id = ( 'single' === which ) ? this.selection.templateSingle : this.selection.templateDigest;

				if ( ! id ) {
					return '';
				}

				var template = findById( this.templates, id );

				return ( template && template.screenshot ) ? template.screenshot : '';
			},

			/**
			 * Returns the field list for a mapping table.
			 *
			 * @param {string} which single, digestTop or digestRepeater.
			 * @return {Array}
			 */
			fieldListFor: function ( which ) {
				if ( 'single' === which ) {
					return this.fields.single || [];
				}
				if ( 'digestTop' === which ) {
					return this.fields.digestTop || [];
				}
				return this.fields.digestRepeater || [];
			},

			/**
			 * Returns the mapping object for a mapping table.
			 *
			 * @param {string} which single, digestTop or digestRepeater.
			 * @return {Object}
			 */
			mappingFor: function ( which ) {
				if ( 'single' === which ) {
					return this.selection.mappingSingle || {};
				}
				if ( 'digestTop' === which ) {
					return this.selection.mappingDigestTop || {};
				}
				return this.selection.mappingDigestRepeater || {};
			},

			/**
			 * Builds the positional region order preview, grouped by type.
			 *
			 * @param {string} which single, digestTop or digestRepeater.
			 * @return {Array} List of { type, typeLabel, items }.
			 */
			orderPreview: function ( which ) {
				var fields = this.fieldListFor( which );
				var mapping = this.mappingFor( which );
				var types = [ 'singleline', 'multiline', 'image' ];
				var groups = [];

				types.forEach( function ( type ) {
					var inType = fields.filter( function ( f ) {
						return f.type === type;
					} );

					if ( ! inType.length ) {
						return;
					}

					var included = inType.filter( function ( f ) {
						return mapping[ f.key ] && mapping[ f.key ].include;
					} ).sort( function ( a, b ) {
						return ( mapping[ a.key ].position || 0 ) - ( mapping[ b.key ].position || 0 );
					} );

					groups.push( {
						type: type,
						typeLabel: this.typeLabels[ type ] || type,
						items: included.map( function ( f ) {
							return f.label;
						} )
					} );
				}.bind( this ) );

				return groups;
			},

			/**
			 * Validates then saves the selection and mapping.
			 *
			 * @return {void}
			 */
			saveSelection: function () {
				if ( this.busy2 ) {
					return;
				}

				if ( this.selection.fromEmail && ! this.looksLikeEmail( this.selection.fromEmail ) ) {
					this.selectionResult = { type: 'error', message: kdnaAb.i18n.invalidEmail };
					return;
				}

				this.savingSelection = true;
				this.selectionResult = { type: 'info', message: kdnaAb.i18n.saving };

				var single = findById( this.templates, this.selection.templateSingle );
				var digest = findById( this.templates, this.selection.templateDigest );
				var list = findById( this.lists, this.selection.listId );
				var client = findById( this.clients, this.selection.clientId );

				var payload = {
					clientId: this.selection.clientId || '',
					clientName: client ? client.name : ( this.selection.clientName || '' ),
					listId: this.selection.listId || '',
					listName: list ? list.name : ( this.selection.listName || '' ),
					templateSingle: this.selection.templateSingle || '',
					templateSingleName: single ? single.name : '',
					templateDigest: this.selection.templateDigest || '',
					templateDigestName: digest ? digest.name : '',
					fromName: this.selection.fromName || '',
					fromEmail: this.selection.fromEmail || '',
					replyTo: this.selection.replyTo || '',
					mappingSingle: this.selection.mappingSingle || {},
					mappingDigestTop: this.selection.mappingDigestTop || {},
					mappingDigestRepeater: this.selection.mappingDigestRepeater || {}
				};

				request( 'kdna_ab_save_selection', { payload: JSON.stringify( payload ) } )
					.then( function ( response ) {
						if ( response && response.success && response.data ) {
							this.selectionResult = { type: 'success', message: response.data.message };

							if ( response.data.selection ) {
								// Merge saved names back, keep the reactive object.
								this.selection = Object.assign( {}, this.selection, response.data.selection );
							}
						} else {
							this.selectionResult = { type: 'error', message: this.messageFrom( response ) };
						}
					}.bind( this ) )
					.catch( function () {
						this.selectionResult = { type: 'error', message: kdnaAb.i18n.networkError };
					}.bind( this ) )
					.finally( function () {
						this.savingSelection = false;
					}.bind( this ) );
			},

			/*
			 * -----------------------------------------------------------------
			 * Stage 4, content assembly
			 * -----------------------------------------------------------------
			 */

			/**
			 * Loads the repeater sub-field keys from the site data.
			 *
			 * @return {void}
			 */
			loadSubfields: function () {
				if ( ! this.content.repeaterField ) {
					this.subfields = [];
					return;
				}

				this.loadingSubfields = true;

				request( 'kdna_ab_get_subfields', { repeater: this.content.repeaterField } )
					.then( function ( payload ) {
						if ( payload && payload.success && payload.data ) {
							this.subfields = payload.data.subfields || [];
						} else {
							this.contentResult = { type: 'error', message: this.messageFrom( payload ) };
						}
					}.bind( this ) )
					.catch( function () {
						this.contentResult = { type: 'error', message: kdnaAb.i18n.networkError };
					}.bind( this ) )
					.finally( function () {
						this.loadingSubfields = false;
					}.bind( this ) );
			},

			/**
			 * Refreshes the meta field list and sub-fields.
			 *
			 * @return {void}
			 */
			refreshFields: function () {
				request( 'kdna_ab_get_meta_fields', {} )
					.then( function ( payload ) {
						if ( payload && payload.success && payload.data ) {
							this.metaFields = payload.data.metaFields || [];
						}
					}.bind( this ) )
					.catch( function () {} );

				if ( this.content.repeaterField ) {
					this.loadSubfields();
				}
			},

			/**
			 * Saves the content settings.
			 *
			 * @return {void}
			 */
			saveContent: function () {
				if ( this.savingContent ) {
					return;
				}

				this.savingContent = true;
				this.contentResult = { type: 'info', message: kdnaAb.i18n.saving };

				request( 'kdna_ab_save_content', { payload: JSON.stringify( this.content ) } )
					.then( function ( payload ) {
						if ( payload && payload.success && payload.data ) {
							this.contentResult = { type: 'success', message: payload.data.message };
							if ( payload.data.content ) {
								this.content = Object.assign( {}, this.content, payload.data.content );
							}
						} else {
							this.contentResult = { type: 'error', message: this.messageFrom( payload ) };
						}
					}.bind( this ) )
					.catch( function () {
						this.contentResult = { type: 'error', message: kdnaAb.i18n.networkError };
					}.bind( this ) )
					.finally( function () {
						this.savingContent = false;
					}.bind( this ) );
			},

			/**
			 * Assembles a preview for the most recent, or entered, post.
			 *
			 * @return {void}
			 */
			preview: function () {
				if ( this.previewing ) {
					return;
				}

				this.previewing = true;
				this.previewData = null;

				var params = this.previewPostId ? { post_id: this.previewPostId } : {};

				request( 'kdna_ab_preview_content', params )
					.then( function ( payload ) {
						if ( payload && payload.success && payload.data ) {
							this.previewData = payload.data;
						} else {
							this.contentResult = { type: 'error', message: this.messageFrom( payload ) };
						}
					}.bind( this ) )
					.catch( function () {
						this.contentResult = { type: 'error', message: kdnaAb.i18n.networkError };
					}.bind( this ) )
					.finally( function () {
						this.previewing = false;
					}.bind( this ) );
			},

			/**
			 * Opens the media library to choose a placeholder image.
			 *
			 * @return {void}
			 */
			chooseImage: function () {
				if ( ! window.wp || ! window.wp.media ) {
					return;
				}

				var self = this;
				var frame = window.wp.media( {
					title: kdnaAb.i18n.mediaTitle,
					button: { text: kdnaAb.i18n.mediaButton },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					self.content.placeholderImage = String( attachment.id );
				} );

				frame.open();
			},

			/*
			 * -----------------------------------------------------------------
			 * Stage 5, sending
			 * -----------------------------------------------------------------
			 */

			/**
			 * Saves the sending settings.
			 *
			 * @return {void}
			 */
			saveSending: function () {
				if ( this.savingSending ) {
					return;
				}

				this.savingSending = true;
				this.sendingResult = { type: 'info', message: kdnaAb.i18n.saving };

				request( 'kdna_ab_save_sending', { payload: JSON.stringify( this.sending ) } )
					.then( function ( payload ) {
						if ( payload && payload.success && payload.data ) {
							this.sendingResult = { type: 'success', message: payload.data.message };
							if ( payload.data.sending ) {
								this.sending = Object.assign( {}, this.sending, payload.data.sending );
							}
						} else {
							this.sendingResult = { type: 'error', message: this.messageFrom( payload ) };
						}
					}.bind( this ) )
					.catch( function () {
						this.sendingResult = { type: 'error', message: kdnaAb.i18n.networkError };
					}.bind( this ) )
					.finally( function () {
						this.savingSending = false;
					}.bind( this ) );
			},

			/*
			 * -----------------------------------------------------------------
			 * Small helpers
			 * -----------------------------------------------------------------
			 */

			/**
			 * Extracts a message from a failed response, or the default.
			 *
			 * @param {Object} payload Response object.
			 * @return {string}
			 */
			messageFrom: function ( payload ) {
				return ( payload && payload.data && payload.data.message )
					? payload.data.message
					: kdnaAb.i18n.networkError;
			},

			/**
			 * Lightweight email shape check for inline feedback only.
			 *
			 * @param {string} value Candidate address.
			 * @return {boolean}
			 */
			looksLikeEmail: function ( value ) {
				return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
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
