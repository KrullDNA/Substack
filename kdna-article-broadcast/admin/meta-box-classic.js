/**
 * KDNA Article Broadcast, Classic editor meta box behaviour.
 *
 * Handles two things in the Classic editor: mirroring the post title into the
 * subject field until the subject is edited, and the unlock and resend button.
 *
 * @package KDNA_Article_Broadcast
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var box = document.querySelector( '.kdna-ab-metabox' );

		if ( ! box ) {
			return;
		}

		setupSubjectMirror( box );
		setupUnlock( box );
		setupTest( box );
	} );

	/**
	 * Mirrors the post title into the subject field while auto tracking is on.
	 *
	 * @param {HTMLElement} box The meta box container.
	 * @return {void}
	 */
	function setupSubjectMirror( box ) {
		var titleField = document.getElementById( 'title' );
		var subject = box.querySelector( '.kdna-ab-mb-subject' );
		var autoField = box.querySelector( '.kdna-ab-mb-subject-auto' );

		if ( ! titleField || ! subject || ! autoField ) {
			return;
		}

		var auto = '0' !== autoField.value;

		// Seed the subject from the title when auto and currently empty.
		if ( auto && ! subject.value ) {
			subject.value = titleField.value;
		}

		// Live mirror. Setting value programmatically does not fire input, so the
		// subject listener below only reacts to genuine user typing.
		titleField.addEventListener( 'input', function () {
			if ( auto ) {
				subject.value = titleField.value;
			}
		} );

		// The first real edit stops auto tracking.
		subject.addEventListener( 'input', function () {
			if ( auto ) {
				auto = false;
				autoField.value = '0';
			}
		} );
	}

	/**
	 * Wires the unlock and resend button.
	 *
	 * @param {HTMLElement} box The meta box container.
	 * @return {void}
	 */
	function setupUnlock( box ) {
		var button = box.querySelector( '.kdna-ab-mb-unlock' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			if ( ! window.confirm( kdnaAbMeta.i18n.confirmUnlock ) ) {
				return;
			}

			button.disabled = true;

			var body = new FormData();
			body.append( 'action', 'kdna_ab_unlock_resend' );
			body.append( 'nonce', kdnaAbMeta.unlockNonce );
			body.append( 'post_id', button.getAttribute( 'data-post' ) );

			fetch( kdnaAbMeta.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( payload && payload.success ) {
					// Reload so the editable form replaces the locked view.
					window.location.reload();
				} else {
					button.disabled = false;
					window.alert( ( payload && payload.data && payload.data.message ) ? payload.data.message : kdnaAbMeta.i18n.unlockError );
				}
			} ).catch( function () {
				button.disabled = false;
				window.alert( kdnaAbMeta.i18n.unlockError );
			} );
		} );
	}

	/**
	 * Wires the Send test button.
	 *
	 * @param {HTMLElement} box The meta box container.
	 * @return {void}
	 */
	function setupTest( box ) {
		var button = box.querySelector( '.kdna-ab-mb-test-button' );
		var feedback = box.querySelector( '.kdna-ab-mb-test-feedback' );

		if ( ! button || ! feedback ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			feedback.className = 'kdna-ab-mb-test-feedback kdna-ab-mb-test-feedback--info';
			feedback.textContent = kdnaAbMeta.i18n.testSending;

			var body = new FormData();
			body.append( 'action', 'kdna_ab_send_test' );
			body.append( 'nonce', kdnaAbMeta.testNonce );
			body.append( 'post_id', button.getAttribute( 'data-post' ) );

			fetch( kdnaAbMeta.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				button.disabled = false;

				if ( payload && payload.success && payload.data ) {
					feedback.className = 'kdna-ab-mb-test-feedback kdna-ab-mb-test-feedback--success';
					feedback.textContent = kdnaAbMeta.i18n.testSentTo + ' ' + ( payload.data.recipients || [] ).join( ', ' );
				} else {
					feedback.className = 'kdna-ab-mb-test-feedback kdna-ab-mb-test-feedback--error';
					feedback.textContent = ( payload && payload.data && payload.data.message ) ? payload.data.message : kdnaAbMeta.i18n.testError;
				}
			} ).catch( function () {
				button.disabled = false;
				feedback.className = 'kdna-ab-mb-test-feedback kdna-ab-mb-test-feedback--error';
				feedback.textContent = kdnaAbMeta.i18n.testError;
			} );
		} );
	}
} )();
