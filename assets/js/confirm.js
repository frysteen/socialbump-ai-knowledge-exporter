/**
 * The confirm popup: a copy of SocialBUMP Tweaks' (assets/js/confirm.js,
 * window.SBTweaksConfirm) for SEO for AI, which runs without SocialBUMP
 * Tweaks. The same look and the same calls, as window.SBAIKEConfirm, and in
 * markup data-sbaike-confirm, -field, -title and -action on a button or link:
 * %s in the question stands for the value of the field named in -field.
 *
 *   SBAIKEConfirm.ask( element, { title, message, action, check, before } );
 *   SBAIKEConfirm.tell( { title, message } );
 *   SBAIKEConfirm.open( options, onAction );
 */
( function ( $ ) {
	'use strict';

	var $current = null;

	function close() {
		if ( $current ) {
			$current.remove();
			$current = null;
		}

		$( document ).off( 'keydown.sbaikeConfirm' );
	}

	function open( options, onAction ) {
		close();

		options = options || {};

		var $modal  = $( '<div class="sbaike-modal"><div class="sbaike-dialog" role="dialog" aria-modal="true"></div></div>' );
		var $dialog = $modal.children();
		var $row    = $( '<p class="sbaike-dialog__buttons"></p>' );
		var $go     = $( '<button type="button" class="button button-primary sbaike-dialog__go"></button>' ).text( options.action || 'OK' );
		var $box    = null;

		$dialog.append( '<button type="button" class="sbaike-dialog__x" aria-label="Close">&times;</button>' );
		$dialog.append( $( '<h2></h2>' ).text( options.title || '' ) );

		if ( options.message ) {
			$dialog.append( $( '<p class="sbaike-dialog__message"></p>' ).text( options.message ) );
		}

		if ( options.body ) {
			$dialog.append( options.body );
		}

		if ( options.check ) {
			var check  = 'string' === typeof options.check ? { label: options.check } : options.check;
			var $label = $( '<label class="sbaike-dialog__check"><input type="checkbox"><span></span></label>' );
			var $words = $label.find( 'span' );

			$words.text( check.label || '' );

			if ( check.list && check.list.length ) {
				var $list = $( '<ul class="sbaike-dialog__list"></ul>' );

				check.list.forEach( function ( line ) {
					var text  = String( line );
					var at    = text.indexOf( ' ' );
					var $item = $( '<li></li>' );

					if ( at > 0 ) {
						$item.append( $( '<strong></strong>' ).text( text.slice( 0, at ) ), document.createTextNode( text.slice( at ) ) );
					} else {
						$item.text( text );
					}

					$list.append( $item );
				} );

				$words.append( $list );
			}

			$box = $label.find( 'input' );
			$dialog.append( $label );
		}

		if ( ! options.alert ) {
			$row.append( $( '<button type="button" class="button sbaike-dialog__cancel"></button>' ).text( options.cancel || 'Cancel' ) );
		}

		$row.append( $go );
		$dialog.append( $row );

		// The dark area, the X, Cancel and Escape all close it.
		$modal.on( 'click', function ( e ) {
			if ( e.target === this ) {
				close();
			}
		} );

		$dialog.on( 'click', '.sbaike-dialog__x, .sbaike-dialog__cancel', close );

		$go.on( 'click', function () {
			var checked = $box ? $box.prop( 'checked' ) : false;

			close();

			if ( onAction ) {
				onAction( checked );
			}
		} );

		$( document ).on( 'keydown.sbaikeConfirm', function ( e ) {
			if ( e.key === 'Escape' ) {
				close();
			}
		} );

		$( document.body ).append( $modal );
		$current = $modal;
		$go.trigger( 'focus' );

		return $modal;
	}

	/** Spends the one shot pass ask() leaves on an element. */
	function confirmed( element ) {
		if ( element && element.sbaikePass ) {
			element.sbaikePass = false;

			return true;
		}

		return false;
	}

	/**
	 * Ask, and on the action click the element again with a pass. A native
	 * click, so a link follows its address and a submit button submits its
	 * form, which a jQuery trigger does not do for links.
	 */
	function ask( element, options ) {
		open( options, function ( checked ) {
			if ( options.before ) {
				options.before( checked );
			}

			element.sbaikePass = true;
			element.click();
		} );
	}

	function tell( options ) {
		return open( $.extend( { action: 'OK', alert: true }, options ) );
	}

	window.SBAIKEConfirm = { open: open, close: close, ask: ask, confirmed: confirmed, tell: tell };

	$( document ).on( 'click', '[data-sbaike-confirm]', function ( e ) {
		if ( confirmed( this ) ) {
			return;
		}

		e.preventDefault();

		var $el   = $( this );
		var form  = this.form || $el.closest( 'form' )[0];
		var field = $el.attr( 'data-sbaike-confirm-field' );
		var value = field && form && form.elements[ field ] ? form.elements[ field ].value : '';
		var words = $.trim( $el.text() );

		ask( this, {
			title: $el.attr( 'data-sbaike-confirm-title' ) || words,
			message: String( $el.attr( 'data-sbaike-confirm' ) ).split( '%s' ).join( value ),
			action: $el.attr( 'data-sbaike-confirm-action' ) || words
		} );
	} );
}( jQuery ) );
