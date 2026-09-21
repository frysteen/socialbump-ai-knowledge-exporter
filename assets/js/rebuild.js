/**
 * Rebuilding, a few posts at a time, with a progress bar.
 *
 * Each post has to be fetched and converted, so a site of any size cannot be
 * rebuilt in one request. Anything marked data-sbaike-job runs here instead:
 * the work is done in small batches and you can watch it happen.
 *
 * Without JavaScript the links still work as they always did, they just do it
 * all in one go.
 */
( function () {
	'use strict';

	var cfg = window.sbaikeRebuild || null;

	if ( ! cfg ) {
		return;
	}

	var panel = null;
	var bar = null;
	var count = null;
	var current = null;
	var heading = null;
	var timer = null;
	var ticking = null;

	function build() {
		if ( panel ) {
			return;
		}

		panel = document.createElement( 'div' );
		panel.className = 'sbaike-job';
		panel.setAttribute( 'hidden', 'hidden' );
		panel.innerHTML =
			'<div class=' + q( 'sbaike-job__box' ) + '>' +
			'<span class=' + q( 'sbaike-job__timer' ) + '></span>' +
			'<h2 class=' + q( 'sbaike-job__title' ) + '></h2>' +
			'<div class=' + q( 'sbaike-job__track' ) + '><div class=' + q( 'sbaike-job__bar' ) + '></div></div>' +
			'<p class=' + q( 'sbaike-job__count' ) + '></p>' +
			'<p class=' + q( 'sbaike-job__current' ) + '></p>' +
			'</div>';

		document.body.appendChild( panel );

		heading = panel.querySelector( '.sbaike-job__title' );
		bar = panel.querySelector( '.sbaike-job__bar' );
		count = panel.querySelector( '.sbaike-job__count' );
		current = panel.querySelector( '.sbaike-job__current' );
		timer = panel.querySelector( '.sbaike-job__timer' );
	}

	/** How long the job has been running, in the corner of the box. */
	function startClock() {
		var started = Date.now();

		stopClock();
		timer.textContent = '0s';

		ticking = window.setInterval( function () {
			var seconds = Math.floor( ( Date.now() - started ) / 1000 );

			timer.textContent = seconds < 60 ? seconds + 's' : Math.floor( seconds / 60 ) + 'm ' + ( seconds % 60 ) + 's';
		}, 1000 );
	}

	function stopClock() {
		if ( ticking ) {
			window.clearInterval( ticking );
			ticking = null;
		}
	}

	/**
	 * Whether the settings form has changes that have not been saved.
	 *
	 * A job reloads the page when it finishes, which would throw those changes
	 * away, so it is refused until they are saved. The save button is disabled
	 * while there is nothing to save, which is the signal read here.
	 */
	function unsaved() {
		var save = document.querySelector( 'form[data-sb-dirty] [data-sb-save], [data-sb-save]' );

		return !! ( save && ! save.disabled );
	}

	function q( text ) {
		return String.fromCharCode( 34 ) + text + String.fromCharCode( 34 );
	}

	function show( title ) {
		build();

		heading.textContent = title;
		bar.style.width = '0%';
		count.textContent = cfg.preparing;
		current.textContent = '';
		panel.classList.remove( 'is-failed' );
		panel.classList.remove( 'is-done' );
		Array.prototype.forEach.call( panel.querySelectorAll( '.sbaike-job__report, .sbaike-job__close' ), function ( node ) {
			node.parentNode.removeChild( node );
		} );
		panel.removeAttribute( 'hidden' );
		startClock();
	}

	function progress( done, total, names ) {
		var percent = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;

		bar.style.width = percent + '%';
		count.textContent = cfg.counting.replace( '%1$s', done ).replace( '%2$s', total );

		if ( names && names.length ) {
			name( names[ names.length - 1 ] );
		}
	}

	/** The post being worked on, with its post type in bold before the title. */
	function name( text ) {
		var at = text.indexOf( ': ' );
		var strong;

		current.textContent = '';

		if ( at === -1 ) {
			current.textContent = text;

			return;
		}

		strong = document.createElement( 'strong' );
		strong.textContent = text.slice( 0, at );
		current.appendChild( strong );
		current.appendChild( document.createTextNode( text.slice( at ) ) );
	}

	/**
	 * The report, in the box, with a button to close it.
	 *
	 * The page reloads on close so the counts and pills catch up. Reloading
	 * straight away used to leave the report for the page to show afterwards.
	 */
	function report( html ) {
		var box = panel.querySelector( '.sbaike-job__box' );
		var wrap = document.createElement( 'div' );
		var close = document.createElement( 'button' );

		wrap.className = 'sbaike-job__report';
		wrap.innerHTML = html;

		close.type = 'button';
		close.className = 'button button-primary sbaike-job__close';
		close.textContent = cfg.close || 'Close';
		close.addEventListener( 'click', function () {
			window.location.reload();
		} );

		panel.classList.add( 'is-done' );
		box.appendChild( wrap );
		box.appendChild( close );
		close.focus();
	}

	function post( action, data ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( result ) {
			if ( ! result || ! result.success ) {
				throw new Error( ( result && result.data && result.data.message ) || cfg.failed );
			}

			return result.data;
		} );
	}

	function run( link ) {
		var scope = link.getAttribute( 'data-sbaike-job' );
		var title = link.getAttribute( 'data-sbaike-title' ) || cfg.working;

		show( title );

		post( 'sbaike_job_start', {
			scope: scope,
			post_type: link.getAttribute( 'data-sbaike-type' ) || '',
			post_id: link.getAttribute( 'data-sbaike-id' ) || 0
		} ).then( function ( job ) {
			if ( ! job.total ) {
				return finish( job.job );
			}

			progress( 0, job.total );

			return work( job.job, job.total, job.batch || 3 );
		} ).catch( function ( error ) {
			stop( error.message );
		} );
	}

	/**
	 * Work through the list with a few requests in flight at once.
	 *
	 * Nearly all the time goes on fetching each page over HTTP, so the site spends
	 * most of a rebuild waiting rather than working. Several workers, each taking
	 * the next chunk of the list, cut that waiting down by roughly the number of
	 * them.
	 *
	 * Each request stands alone: the server is told which offset to render, so
	 * nothing depends on the order they come back in, and one that fails can be
	 * asked for again without disturbing the rest.
	 */
	function work( job, total, batch ) {
		var next    = 0;
		var done    = 0;
		var workers = Math.min( cfg.workers || 3, Math.ceil( total / batch ) );
		var names   = [];

		function claim() {
			if ( next >= total ) {
				return null;
			}

			var offset = next;

			next += batch;

			return offset;
		}

		function worker() {
			var offset = claim();

			if ( offset === null ) {
				return Promise.resolve();
			}

			return post( 'sbaike_job_step', { job: job, offset: offset } ).then( function ( data ) {
				done += ( data.done || [] ).length;
				names = data.done && data.done.length ? data.done : names;

				progress( done, total, names );

				return worker();
			} );
		}

		var running = [];

		for ( var i = 0; i < workers; i++ ) {
			running.push( worker() );
		}

		return Promise.all( running ).then( function () {
			return finish( job );
		} );
	}

	function finish( job ) {
		count.textContent = cfg.assembling;
		current.textContent = '';
		bar.style.width = '100%';

		return post( 'sbaike_job_finish', { job: job } ).then( function ( data ) {
			stopClock();
			count.textContent = cfg.done;
			current.textContent = '';

			if ( data && data.report ) {
				report( data.report );

				return;
			}

			window.location.reload();
		} );
	}

	function stop( message ) {
		build();
		stopClock();

		panel.classList.add( 'is-failed' );
		count.textContent = message || cfg.failed;
		current.textContent = cfg.retry;
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '[data-sbaike-job]' ) : null;

		if ( ! link ) {
			return;
		}

		event.preventDefault();

		if ( unsaved() ) {
			window.alert( cfg.unsaved || 'Save your changes first.' );

			return;
		}

		run( link );
	} );

	/**
	 * A save that left rendering to do comes back with cfg.autorun set, and
	 * the job starts itself, the same as pressing Update Files. The flag is
	 * taken out of the address first, so the reload behind the Close button
	 * does not start it all over again.
	 */
	if ( cfg.autorun ) {
		var auto = document.createElement( 'a' );

		auto.setAttribute( 'data-sbaike-job', cfg.autorun );
		auto.setAttribute( 'data-sbaike-title', cfg.autorunTitle || cfg.working );

		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.href.replace( /[?&]sbaike_autorun=[^&#]*/, '' ) );
		}

		run( auto );
	}
}() );
