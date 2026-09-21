/**
 * Unsaved changes on a settings page.
 *
 * A save button that is always live gives no clue whether anything has
 * actually changed, so this watches the form and says so: the button sits
 * disabled and reading Nothing to save until something is edited, and a
 * reminder follows you down the page while changes are pending. Put a change
 * back the way it was and everything goes quiet again.
 *
 * Any form marked data-sb-dirty is watched. A button marked data-sb-save is
 * treated as a save button even when it is not a submit, and one marked
 * data-sb-always-on is left alone. Shared by the SocialBUMP plugins.
 */
( function () {
	'use strict';

	var LABEL_CLEAN = 'Nothing to save';
	var WARNING     = 'You have unsaved changes.';
	var pending     = 0;

	function snapshot( form ) {
		var data = [];

		Array.prototype.forEach.call( form.elements, function ( field ) {
			if ( ! field.name || field.type === 'submit' || field.type === 'button' || field.type === 'file' ) {
				return;
			}

			if ( field.type === 'checkbox' || field.type === 'radio' ) {
				data.push( field.name + '=' + ( field.checked ? '1' : '0' ) );

				return;
			}

			data.push( field.name + '=' + field.value );
		} );

		return data.join( '&' );
	}

	/** The buttons that should follow the state of the form. */
	function buttons( form ) {
		var found = Array.prototype.slice.call( form.querySelectorAll( 'input[type=submit], button[type=submit], [data-sb-save]' ) );

		// A save button can sit outside the form it belongs to.
		if ( form.id ) {
			Array.prototype.forEach.call( document.querySelectorAll( '[form=' + form.id + '], [data-sb-save]' ), function ( button ) {
				if ( found.indexOf( button ) === -1 ) {
					found.push( button );
				}
			} );
		}

		return found.filter( function ( button ) {
			return ! button.hasAttribute( 'data-sb-always-on' );
		} );
	}

	/** Where a button keeps its wording. */
	function wording( button ) {
		return button.querySelector( '[data-sb-label]' ) || button;
	}

	function label( button, text ) {
		var target = wording( button );

		if ( target === button && button.tagName === 'INPUT' ) {
			button.value = text;

			return;
		}

		target.textContent = text;
	}

	/**
	 * What a button says when there is something to save.
	 *
	 * A button that starts out saying there is nothing to save cannot use its own
	 * wording for the other state, so it carries the active wording in an
	 * attribute instead.
	 */
	function active( button ) {
		return button.getAttribute( 'data-sb-label-dirty' ) || original( button );
	}

	function original( button ) {
		var target = wording( button );

		if ( target === button && button.tagName === 'INPUT' ) {
			return button.value;
		}

		return target.textContent.trim();
	}

	/** The reminder that follows you down the page. */
	function reminder( form ) {
		var note = document.createElement( 'button' );

		note.type = 'button';
		note.className = 'sb-unsaved';
		note.setAttribute( 'hidden', 'hidden' );
		note.innerHTML = '<span class=' + String.fromCharCode( 34 ) + 'sb-unsaved__dot' + String.fromCharCode( 34 ) + '></span><span>Unsaved changes, click to save</span>';

		note.addEventListener( 'click', function () {
			submit( form );
		} );

		document.body.appendChild( note );

		return note;
	}

	/**
	 * Save the form, using the button that actually saves it.
	 *
	 * A form can hold more than one submit, and not all of them save: the image
	 * sizes form has Reset to defaults sitting above Save changes. Submitting with
	 * whichever came first would send the wrong one, which is exactly what it did.
	 * So the save button is looked for by name, then by being the primary one, and
	 * only then does it fall back to the first submit in the form.
	 */
	function submit( form ) {
		var live = function ( list ) {
			return Array.prototype.filter.call( list, function ( button ) {
				return ! button.disabled;
			} )[0];
		};

		var first = live( form.querySelectorAll( '[data-sb-save]' ) )
			|| live( form.querySelectorAll( 'input[type=submit].button-primary, button[type=submit].button-primary' ) )
			|| live( form.querySelectorAll( 'input[type=submit], button[type=submit]' ) );

		if ( typeof form.requestSubmit === 'function' ) {
			// requestSubmit only accepts a real submit button as the one that sent
			// the form. Hand it a button that only looks like one, type=button with
			// data-sb-save, and the browser throws and nothing is saved, while the
			// button and the reminder both look exactly as they should.
			if ( first && first.type === 'submit' ) {
				form.requestSubmit( first );
			} else {
				form.requestSubmit();
			}

			return;
		}

		form.submit();
	}

	function watch( form ) {
		var saved = snapshot( form );
		var keys  = buttons( form );

		if ( ! keys.length ) {
			return;
		}

		var names  = keys.map( active );

		// Buttons that do work rather than save, and can sit idle when there is none.
		var idle = Array.prototype.slice.call( document.querySelectorAll( '[data-sb-idle]' ) );
		var note   = reminder( form );
		var dirty  = null;
		var saving = false;

		// A save button that only looks like one still needs to save.
		keys.forEach( function ( button ) {
			if ( button.type !== 'button' ) {
				return;
			}

			button.addEventListener( 'click', function () {
				if ( ! button.disabled ) {
					submit( form );
				}
			} );
		} );

		function paint() {
			var changed = snapshot( form ) !== saved;

			if ( changed === dirty ) {
				return;
			}

			pending += changed ? 1 : ( dirty === null ? 0 : -1 );
			dirty    = changed;

			idle.forEach( function ( button ) {
				// Nothing changed and nothing outstanding, so there is nothing to run.
				button.disabled = ! changed && button.getAttribute( 'data-sb-idle' ) === '1';
			} );

			keys.forEach( function ( button, i ) {
				button.disabled = ! changed;
				button.classList.toggle( 'sb-save--clean', ! changed );
				button.classList.toggle( 'sb-save--dirty', changed );
				button.title = changed ? 'Save your changes' : 'No unsaved changes';
				label( button, changed ? names[ i ] : LABEL_CLEAN );
			} );

			if ( changed ) {
				note.removeAttribute( 'hidden' );

				return;
			}

			note.setAttribute( 'hidden', 'hidden' );
		}

		form.addEventListener( 'input', paint );
		form.addEventListener( 'change', paint );

		/**
		 * Saved by something other than the form itself.
		 *
		 * A page can save these settings as part of another action, and then the
		 * form is no longer dirty even though nobody pressed Save. Dispatch
		 * sb:saved on the form to say so, and the button and the reminder settle
		 * down as if it had been.
		 */
		form.addEventListener( 'sb:saved', function () {
			saved = snapshot( form );
			paint();
		} );

		// Leaving to save should not warn, or flash the reminder on the way out.
		form.addEventListener( 'submit', function () {
			saving = true;
			pending = 0;
			note.setAttribute( 'hidden', 'hidden' );
		} );

		// Sorting and other scripted changes do not fire input events.
		if ( window.MutationObserver ) {
			new MutationObserver( paint ).observe( form, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'value', 'checked' ] } );
		}

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! pending || saving ) {
				return;
			}

			event.preventDefault();
			event.returnValue = WARNING;

			return WARNING;
		} );

		paint();
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-sb-dirty]' ), watch );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
