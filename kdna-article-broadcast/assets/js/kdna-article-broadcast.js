/**
 * KDNA Article Broadcast, front end script.
 *
 * Handles the newsletter subscribe widget: AJAX submission, an invisible
 * honeypot, and Google reCAPTCHA v3 that fails open if it cannot run.
 * JavaScript custom events use the kdna: prefix. This file is only enqueued
 * where a widget is present.
 *
 * @package KDNA_Article_Broadcast
 */
( function () {
	'use strict';

	var config = window.kdnaAbSubscribe || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		var widgets = document.querySelectorAll( '.kdna-ab-subscribe' );
		Array.prototype.forEach.call( widgets, setup );

		var moreButtons = document.querySelectorAll( '.kdna-ab-archive__more' );
		Array.prototype.forEach.call( moreButtons, setupLoadMore );
	} );

	/**
	 * Wires an archive Load more button.
	 *
	 * @param {HTMLElement} button The Load more button.
	 * @return {void}
	 */
	function setupLoadMore( button ) {
		var archive = button.closest( '.kdna-ab-archive' );
		var container = archive ? archive.querySelector( '.kdna-ab-archive__items' ) : null;

		if ( ! container ) {
			return;
		}

		button.addEventListener( 'click', function () {
			if ( button.disabled ) {
				return;
			}

			button.disabled = true;
			var nextPage = ( parseInt( button.getAttribute( 'data-page' ), 10 ) || 1 ) + 1;

			var body = new FormData();
			body.append( 'action', 'kdna_ab_archive_more' );
			body.append( 'nonce', config.archiveNonce );
			body.append( 'source', button.getAttribute( 'data-source' ) );
			body.append( 'number', button.getAttribute( 'data-number' ) );
			body.append( 'page', String( nextPage ) );

			fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				button.disabled = false;

				if ( payload && payload.success && payload.data ) {
					container.insertAdjacentHTML( 'beforeend', payload.data.html || '' );
					button.setAttribute( 'data-page', String( nextPage ) );

					if ( ! payload.data.hasMore ) {
						button.parentNode.removeChild( button );
					}
				}
			} ).catch( function () {
				button.disabled = false;
			} );
		} );
	}

	/**
	 * Wires a single widget instance.
	 *
	 * @param {HTMLElement} widget The widget wrapper.
	 * @return {void}
	 */
	function setup( widget ) {
		var form = widget.querySelector( '.kdna-ab-subscribe__form' );

		if ( ! form || form.dataset.kdnaReady === '1' ) {
			return;
		}

		form.dataset.kdnaReady = '1';

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submit( widget, form );
		} );
	}

	/**
	 * Runs reCAPTCHA if available, then submits.
	 *
	 * @param {HTMLElement} widget The widget wrapper.
	 * @param {HTMLFormElement} form The form.
	 * @return {void}
	 */
	function submit( widget, form ) {
		if ( isBusy( form ) ) {
			return;
		}

		clearMessages( widget );
		setBusy( form, true );

		withToken( function ( token ) {
			send( widget, form, token );
		} );
	}

	/**
	 * Obtains a reCAPTCHA token, or an empty string on any problem, fail open.
	 *
	 * @param {Function} done Callback receiving the token.
	 * @return {void}
	 */
	function withToken( done ) {
		if ( ! config.recaptchaSiteKey || ! window.grecaptcha || ! window.grecaptcha.execute ) {
			done( '' );
			return;
		}

		try {
			window.grecaptcha.ready( function () {
				window.grecaptcha.execute( config.recaptchaSiteKey, { action: 'subscribe' } )
					.then( function ( token ) {
						done( token || '' );
					} )
					.catch( function () {
						done( '' );
					} );
			} );
		} catch ( e ) {
			done( '' );
		}
	}

	/**
	 * Sends the submission.
	 *
	 * @param {HTMLElement} widget The widget wrapper.
	 * @param {HTMLFormElement} form The form.
	 * @param {string} token reCAPTCHA token, may be empty.
	 * @return {void}
	 */
	function send( widget, form, token ) {
		var body = new FormData( form );
		body.append( 'action', 'kdna_ab_subscribe' );
		body.append( 'nonce', config.nonce );
		body.set( 'recaptcha_token', token );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			setBusy( form, false );

			if ( payload && payload.success ) {
				onSuccess( widget, form, payload.data );
			} else {
				showError( widget, ( payload && payload.data && payload.data.message ) ? payload.data.message : fallbackError() );
			}
		} ).catch( function () {
			setBusy( form, false );
			showError( widget, fallbackError() );
		} );
	}

	/**
	 * Handles a successful submission.
	 *
	 * @param {HTMLElement} widget The widget wrapper.
	 * @param {HTMLFormElement} form The form.
	 * @param {Object} data Response data.
	 * @return {void}
	 */
	function onSuccess( widget, form, data ) {
		window.dispatchEvent( new CustomEvent( 'kdna:subscribed', { detail: { widget: widget } } ) );

		if ( 'redirect' === widget.getAttribute( 'data-success' ) ) {
			var url = widget.getAttribute( 'data-redirect' );
			if ( url ) {
				window.location.assign( url );
				return;
			}
		}

		var success = widget.querySelector( '.kdna-ab-subscribe__message--success' );
		var message = ( success && success.getAttribute( 'data-message' ) ) || ( data && data.message ) || '';

		// Fade the form out and show the message.
		form.classList.add( 'kdna-ab-subscribe__form--done' );

		if ( success ) {
			setMessageText( success, message );
			success.hidden = false;
		}
	}

	/**
	 * Shows an inline error.
	 *
	 * @param {HTMLElement} widget The widget wrapper.
	 * @param {string} message The message.
	 * @return {void}
	 */
	function showError( widget, message ) {
		var error = widget.querySelector( '.kdna-ab-subscribe__message--error' );

		if ( error ) {
			setMessageText( error, message );
			error.hidden = false;
		}
	}

	/**
	 * Sets the text of a message, into its text span so the icon is preserved.
	 *
	 * @param {HTMLElement} node The message container.
	 * @param {string} message The text.
	 * @return {void}
	 */
	function setMessageText( node, message ) {
		var text = node.querySelector( '.kdna-ab-subscribe__message-text' );
		if ( text ) {
			text.textContent = message;
		} else {
			node.textContent = message;
		}
	}

	/**
	 * Clears any shown messages.
	 *
	 * @param {HTMLElement} widget The widget wrapper.
	 * @return {void}
	 */
	function clearMessages( widget ) {
		var messages = widget.querySelectorAll( '.kdna-ab-subscribe__message' );
		Array.prototype.forEach.call( messages, function ( node ) {
			node.hidden = true;
			setMessageText( node, '' );
		} );
	}

	/**
	 * Sets or clears the busy state.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @param {boolean} busy Whether busy.
	 * @return {void}
	 */
	function setBusy( form, busy ) {
		form.classList.toggle( 'kdna-ab-subscribe__form--loading', busy );
		var button = form.querySelector( '.kdna-ab-subscribe__button' );
		if ( button ) {
			button.disabled = busy;
		}
	}

	/**
	 * Whether the form is currently submitting.
	 *
	 * @param {HTMLFormElement} form The form.
	 * @return {boolean}
	 */
	function isBusy( form ) {
		return form.classList.contains( 'kdna-ab-subscribe__form--loading' );
	}

	/**
	 * A fallback network error message.
	 *
	 * @return {string}
	 */
	function fallbackError() {
		return ( config.i18n && config.i18n.network ) ? config.i18n.network : 'Something went wrong. Please try again.';
	}
} )();
