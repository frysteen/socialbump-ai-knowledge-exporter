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

	function build() {
		if ( panel ) {
			return;
		}

		panel = document.createElement( 'div' );
		panel.className = 'sbaike-job';
		panel.setAttribute( 'hidden', 'hidden' );
		panel.innerHTML =
			'<div class=' + q( 'sbaike-job__box' ) + '>' +
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
		panel.removeAttribute( 'hidden' );
	}

	function progress( done, total, names ) {
		var percent = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;

		bar.style.width = percent + '%';
		count.textContent = cfg.counting.replace( '%1$s', done ).replace( '%2$s', total );

		if ( names && names.length ) {
			current.textContent = names[ names.length - 1 ];
		}
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

			return walk( job.job, 0, job.total );
		} ).catch( function ( error ) {
			stop( error.message );
		} );
	}

	function walk( job, offset, total ) {
		return post( 'sbaike_job_step', { job: job, offset: offset } ).then( function ( data ) {
			progress( data.offset, data.total, data.done );

			if ( data.more ) {
				return walk( job, data.offset, data.total );
			}

			return finish( job );
		} );
	}

	function finish( job ) {
		count.textContent = cfg.assembling;
		current.textContent = '';
		bar.style.width = '100%';

		return post( 'sbaike_job_finish', { job: job } ).then( function () {
			count.textContent = cfg.done;
			window.location.reload();
		} );
	}

	function stop( message ) {
		build();

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
		run( link );
	} );
}() );
