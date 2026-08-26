/**
 * KDNA Article Broadcast, block editor document panel.
 *
 * Provides the Article Broadcast controls as a document setting panel in the
 * sidebar, on the Posts post type only. Built with wp.element.createElement so
 * no compile step is needed.
 *
 * @package KDNA_Article_Broadcast
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editPost || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var CheckboxControl = wp.components.CheckboxControl;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Button = wp.components.Button;
	var dateI18n = wp.date.dateI18n;

	var M = window.kdnaAbMeta || {};
	var KEYS = M.keys || {};
	var LABELS = M.labels || {};
	var I18N = M.i18n || {};

	/**
	 * The panel component.
	 *
	 * @return {Object|null} The rendered panel, or null on other post types.
	 */
	function ArticleBroadcastPanel() {
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );

		var editor = useSelect( function ( select ) {
			var ed = select( 'core/editor' );
			return {
				id: ed.getCurrentPostId(),
				title: ed.getEditedPostAttribute( 'title' ) || '',
				meta: ed.getEditedPostAttribute( 'meta' ) || {}
			};
		}, [] );

		var dispatch = useDispatch( 'core/editor' );

		var testBusy = useState( false );
		var testMsg = useState( null );

		if ( 'post' !== postType ) {
			return null;
		}

		var meta = editor.meta;
		var status = meta[ KEYS.status ] || '';
		// A campaign exists once a campaign ID is stored, which locks the post
		// against creating a second campaign, whatever the exact status.
		var locked = '' !== ( meta[ KEYS.campaignId ] || '' );

		/**
		 * Merges one or more meta values into the post.
		 *
		 * @param {Object} values Meta values keyed by meta key.
		 * @return {void}
		 */
		function setMeta( values ) {
			dispatch.editPost( { meta: values } );
		}

		var children = [ renderStatus( meta, status ) ];

		if ( locked ) {
			children.push( renderLocked( meta, dispatch ) );
		} else {
			children.push( renderControls( meta, editor.title, setMeta ) );
		}

		children.push( renderTest( editor.id, testBusy, testMsg ) );

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'kdna-ab-panel',
				title: I18N.panelTitle,
				className: 'kdna-ab-panel'
			},
			children
		);
	}

	/**
	 * Renders the status readout.
	 *
	 * @param {Object} meta   The post meta.
	 * @param {string} status The status state.
	 * @return {Object}
	 */
	function renderStatus( meta, status ) {
		var label;
		var detail = '';

		if ( '' === status ) {
			label = LABELS.not_sent;
		} else if ( 'sent' === status ) {
			label = LABELS.sent;
			var time = parseInt( meta[ KEYS.statusTime ] || '0', 10 );
			if ( time ) {
				detail = I18N.sentPrefix + ' ' + dateI18n( M.dateFormat, new Date( time * 1000 ) );
			}
		} else if ( 'draft' === status ) {
			label = LABELS.draft;
			var drafted = parseInt( meta[ KEYS.statusTime ] || '0', 10 );
			if ( drafted ) {
				detail = I18N.draftPrefix + ' ' + dateI18n( M.dateFormat, new Date( drafted * 1000 ) );
			}
		} else if ( 'failed' === status ) {
			label = LABELS.failed;
			detail = meta[ KEYS.statusMessage ] || '';
		} else if ( 'held' === status ) {
			label = LABELS.held;
			var held = parseInt( meta[ KEYS.statusTime ] || '0', 10 );
			if ( held ) {
				detail = I18N.sendsPrefix + ' ' + dateI18n( M.dateFormat, new Date( held * 1000 ) ) + ' ' + I18N.sendsSuffix;
			}
		} else if ( 'queued' === status ) {
			label = LABELS.queued;
		} else {
			label = status;
		}

		var parts = [
			el( 'span', { key: 'label', className: 'kdna-ab-mb-status__label' }, label )
		];

		if ( detail ) {
			parts.push( el( 'span', { key: 'detail', className: 'kdna-ab-mb-status__detail' }, detail ) );
		}

		return el(
			'div',
			{
				key: 'status',
				className: 'kdna-ab-mb-status kdna-ab-mb-status--' + ( status || 'not_sent' )
			},
			parts
		);
	}

	/**
	 * Renders the locked view with the unlock button.
	 *
	 * @param {Object} meta     The post meta.
	 * @param {Object} dispatch The core/editor dispatch.
	 * @return {Object}
	 */
	function renderLocked( meta, dispatch ) {
		var campaignId = meta[ KEYS.campaignId ] || '';

		var rows = [
			el( 'p', { key: 'note', className: 'kdna-ab-mb-note' }, I18N.lockedNote )
		];

		if ( campaignId ) {
			rows.push(
				el( 'p', { key: 'cid', className: 'kdna-ab-mb-cid' },
					el( 'strong', null, I18N.campaignLabel + ': ' ),
					el( 'code', null, campaignId )
				)
			);
		}

		rows.push(
			el(
				Button,
				{
					key: 'unlock',
					variant: 'secondary',
					className: 'kdna-ab-mb-unlock',
					onClick: function () {
						if ( ! window.confirm( I18N.confirmUnlock ) ) {
							return;
						}

						var cleared = {};
						cleared[ KEYS.status ] = '';
						cleared[ KEYS.statusMessage ] = '';
						cleared[ KEYS.statusTime ] = '';
						cleared[ KEYS.campaignId ] = '';
						cleared[ KEYS.mode ] = '';
						cleared[ KEYS.send ] = '1';

						dispatch.editPost( { meta: cleared } );
						dispatch.savePost();
					}
				},
				I18N.unlockButton
			)
		);

		return el( Fragment, { key: 'locked' }, rows );
	}

	/**
	 * Renders the editable controls.
	 *
	 * @param {Object}   meta    The post meta.
	 * @param {string}   title   The current post title.
	 * @param {Function} setMeta Meta setter.
	 * @return {Object}
	 */
	function renderControls( meta, title, setMeta ) {
		var send = '1' === ( meta[ KEYS.send ] || '' );
		var auto = '0' !== ( meta[ KEYS.subjectAuto ] || '1' );
		var subjectValue = auto ? title : ( meta[ KEYS.subject ] || '' );
		var preview = meta[ KEYS.preview ] || '';
		var teaser = meta[ KEYS.teaser ] || '';
		var cta = meta[ KEYS.ctaOverride ] || '';

		var sendControl = el( CheckboxControl, {
			key: 'send',
			label: I18N.sendLabel,
			checked: send,
			onChange: function ( value ) {
				var v = {};
				v[ KEYS.send ] = value ? '1' : '';
				setMeta( v );
			}
		} );

		var subjectControl = el( TextControl, {
			key: 'subject',
			label: I18N.subjectLabel,
			help: I18N.subjectHelp,
			value: subjectValue,
			onChange: function ( value ) {
				var v = {};
				v[ KEYS.subject ] = value;
				v[ KEYS.subjectAuto ] = '0';
				setMeta( v );
			}
		} );

		var previewControl = el( TextControl, {
			key: 'preview',
			label: I18N.previewLabel,
			help: I18N.previewHelp,
			value: preview,
			onChange: function ( value ) {
				var v = {};
				v[ KEYS.preview ] = value;
				setMeta( v );
			}
		} );

		var teaserControl = el( TextareaControl, {
			key: 'teaser',
			label: I18N.teaserLabel,
			help: I18N.teaserHelp,
			rows: 3,
			value: teaser,
			onChange: function ( value ) {
				var v = {};
				v[ KEYS.teaser ] = value;
				setMeta( v );
			}
		} );

		var ctaControl = el( TextControl, {
			key: 'cta',
			label: I18N.ctaLabel,
			help: I18N.ctaHelp,
			value: cta,
			onChange: function ( value ) {
				var v = {};
				v[ KEYS.ctaOverride ] = value;
				setMeta( v );
			}
		} );

		return el( Fragment, { key: 'controls' }, [ sendControl, subjectControl, previewControl, teaserControl, ctaControl ] );
	}

	/**
	 * Renders the test send section.
	 *
	 * @param {number} postId   Current post ID.
	 * @param {Array}  testBusy useState pair for the busy flag.
	 * @param {Array}  testMsg  useState pair for the feedback message.
	 * @return {Object}
	 */
	function renderTest( postId, testBusy, testMsg ) {
		var busy = testBusy[ 0 ];
		var setBusy = testBusy[ 1 ];
		var message = testMsg[ 0 ];
		var setMessage = testMsg[ 1 ];

		function runTest() {
			setBusy( true );
			setMessage( { type: 'info', text: I18N.testSending } );

			var body = new FormData();
			body.append( 'action', 'kdna_ab_send_test' );
			body.append( 'nonce', M.testNonce );
			body.append( 'post_id', postId );

			window.fetch( M.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				setBusy( false );

				if ( payload && payload.success && payload.data ) {
					setMessage( { type: 'success', text: I18N.testSentTo + ' ' + ( payload.data.recipients || [] ).join( ', ' ) } );
				} else {
					setMessage( { type: 'error', text: ( payload && payload.data && payload.data.message ) ? payload.data.message : I18N.testError } );
				}
			} ).catch( function () {
				setBusy( false );
				setMessage( { type: 'error', text: I18N.testError } );
			} );
		}

		var rows = [
			el( Button, {
				key: 'testbtn',
				variant: 'secondary',
				isBusy: busy,
				disabled: busy,
				onClick: runTest
			}, busy ? I18N.testSending : I18N.testButton ),
			el( 'p', { key: 'testhelp', className: 'kdna-ab-mb-help' }, I18N.testHelp )
		];

		if ( message ) {
			rows.push( el( 'div', {
				key: 'testmsg',
				className: 'kdna-ab-mb-test-feedback kdna-ab-mb-test-feedback--' + message.type
			}, message.text ) );
		}

		return el( 'div', { key: 'test', className: 'kdna-ab-mb-test' }, rows );
	}

	registerPlugin( 'kdna-ab-article-broadcast', {
		render: ArticleBroadcastPanel,
		icon: 'email'
	} );
} )( window.wp );
