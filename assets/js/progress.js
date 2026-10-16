/**
 * The progress popup: a copy of SocialBUMP Tweaks' (assets/js/progress.js,
 * window.SBTweaksProgress) for SEO for AI, which runs without SocialBUMP
 * Tweaks. The same look and the same calls, as window.SBAIKEProgress. Publish
 * release uses it, posting in the background with busy() spinning; rebuilding
 * keeps its own popup in rebuild.js. The report list carries sbaike-job__list
 * so it never picks up the rebuild report's styles.
 *
 *   var job = SBAIKEProgress.open( 'Publishing' );
 *   job.busy();                          // a spinner in place of the bar
 *   job.step( 1, 3, 'Label: detail' );   // bar, "1 of 3", current item
 *   job.status( 'Building the zip' );    // replace the bold line
 *   job.log( 'Item', 'result' );         // a report line
 *   job.finish( 'Done' );                // report and Close (reloads the page)
 *   job.fail( 'Reason' );                // stop, bar red, Close
 */
( function () {
	'use strict';

	var panel = null;

	function q( text ) {
		return String.fromCharCode( 34 ) + text + String.fromCharCode( 34 );
	}

	function build() {
		if ( panel ) {
			return;
		}

		panel = document.createElement( 'div' );
		panel.className = 'sbaike-job';
		panel.setAttribute( 'hidden', 'hidden' );
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );
		panel.innerHTML =
			'<div class=' + q( 'sbaike-job__box' ) + '>' +
			'<span class=' + q( 'sbaike-job__timer' ) + '></span>' +
			'<h2 class=' + q( 'sbaike-job__title' ) + '></h2>' +
			'<span class=' + q( 'sbaike-job__spin' ) + ' aria-hidden=' + q( 'true' ) + '></span>' +
			'<div class=' + q( 'sbaike-job__track' ) + '><div class=' + q( 'sbaike-job__bar' ) + '></div></div>' +
			'<p class=' + q( 'sbaike-job__count' ) + '></p>' +
			'<p class=' + q( 'sbaike-job__current' ) + '></p>' +
			'</div>';

		document.body.appendChild( panel );
	}

	function part( name ) {
		return panel.querySelector( '.sbaike-job__' + name );
	}

	function clock( seconds ) {
		return seconds < 60 ? seconds + 's' : Math.floor( seconds / 60 ) + 'm ' + ( seconds % 60 ) + 's';
	}

	/** Seconds as the report says it: 2.8 seconds, or 1 minute 12 seconds. */
	function spoken( ms ) {
		var seconds = ms / 1000;

		if ( seconds < 60 ) {
			return ( Math.round( seconds * 10 ) / 10 ) + ' seconds';
		}

		var m = Math.floor( seconds / 60 );
		var s = Math.round( seconds % 60 );

		return m + ( m === 1 ? ' minute ' : ' minutes ' ) + s + ( s === 1 ? ' second' : ' seconds' );
	}

	/** "Label: detail", with the label in bold. */
	function labelled( node, text ) {
		var at = String( text ).indexOf( ': ' );

		node.textContent = '';

		if ( at === -1 ) {
			node.textContent = text;

			return;
		}

		var strong = document.createElement( 'strong' );

		strong.textContent = text.slice( 0, at );
		node.appendChild( strong );
		node.appendChild( document.createTextNode( text.slice( at ) ) );
	}

	function open( title ) {
		build();

		var started = Date.now();
		var lines   = [];
		var failed  = 0;
		var timer   = part( 'timer' );
		var ticking = window.setInterval( function () {
			timer.textContent = clock( Math.floor( ( Date.now() - started ) / 1000 ) );
		}, 1000 );

		part( 'title' ).textContent = title;
		part( 'bar' ).style.width = '0%';
		part( 'count' ).textContent = '';
		part( 'current' ).textContent = '';
		timer.textContent = '0s';
		panel.classList.remove( 'is-done', 'is-failed', 'is-busy' );
		Array.prototype.forEach.call( panel.querySelectorAll( '.sbaike-job__report, .sbaike-job__close' ), function ( node ) {
			node.parentNode.removeChild( node );
		} );
		panel.removeAttribute( 'hidden' );

		function stop() {
			panel.classList.remove( 'is-busy' );
			window.clearInterval( ticking );
			timer.textContent = clock( Math.floor( ( Date.now() - started ) / 1000 ) );
		}

		function close_button( then ) {
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'button button-primary sbaike-job__close';
			button.textContent = 'Close';
			button.addEventListener( 'click', function () {
				if ( typeof then === 'function' ) {
					panel.setAttribute( 'hidden', 'hidden' );
					then();

					return;
				}

				window.location.reload();
			} );

			part( 'box' ).appendChild( button );
			button.focus();
		}

		return {
			/** No count to show: a spinner in place of the bar until step(), finish() or fail(). */
			busy: function () {
				panel.classList.add( 'is-busy' );
			},

			/** Move the bar and say where it is up to, and what is being done now. */
			step: function ( done, total, current ) {
				var percent = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;

				panel.classList.remove( 'is-busy' );

				part( 'bar' ).style.width = percent + '%';
				part( 'count' ).textContent = done + ' of ' + total;

				if ( current !== undefined ) {
					labelled( part( 'current' ), current );
				}
			},

			/** Replace the bold status line. */
			status: function ( text ) {
				part( 'count' ).textContent = text;
			},

			/** Replace the grey current item line. */
			current: function ( text ) {
				labelled( part( 'current' ), text );
			},

			/** A line for the report: what, and what happened to it. */
			log: function ( what, result, isFailure ) {
				lines.push( { what: what, result: result, bad: !! isFailure } );

				if ( isFailure ) {
					failed++;
				}
			},

			/** The report and Close. The bar fills; failures turn it red. */
			finish: function ( summary, then ) {
				stop();

				var report = document.createElement( 'div' );
				var head   = document.createElement( 'p' );
				var strong = document.createElement( 'strong' );
				var list   = document.createElement( 'ul' );

				report.className = 'sbaike-job__report';
				list.className = 'sbaike-job__list';
				strong.textContent = summary;
				head.className = 'sbaike-job__summary';
				head.appendChild( strong );
				head.appendChild( document.createTextNode( ' in ' + spoken( Date.now() - started ) ) );
				report.appendChild( head );

				lines.forEach( function ( line ) {
					var item = document.createElement( 'li' );
					var what = document.createElement( 'strong' );

					if ( line.bad ) {
						item.className = 'is-failed';
					}

					what.textContent = line.what;
					item.appendChild( what );
					item.appendChild( document.createTextNode( ' ' + line.result ) );
					list.appendChild( item );
				} );

				if ( lines.length ) {
					report.appendChild( list );
				}

				part( 'bar' ).style.width = '100%';
				part( 'count' ).textContent = 'Finished';
				part( 'current' ).textContent = '';
				panel.classList.add( 'is-done' );

				if ( failed ) {
					panel.classList.add( 'is-failed' );
				}

				part( 'box' ).appendChild( report );
				close_button( then );
			},

			/** Stop where it is, with the reason, and let it be closed. */
			fail: function ( message, then ) {
				stop();
				panel.classList.add( 'is-failed' );
				part( 'count' ).textContent = message;
				part( 'current' ).textContent = '';
				close_button( then );
			}
		};
	}

	window.SBAIKEProgress = { open: open };
}() );
