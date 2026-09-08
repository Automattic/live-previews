/**
 * The Gmail-style "select all across pages" wiring on the Preview Links screen.
 *
 * The banner, the hidden lp_all field, and the table markup are all rendered by
 * PreviewLinksAdminPage; this script only connects them. It is enqueued on
 * every load of that screen, but the banner is only rendered when there is more
 * than one page of links to offer, so bail quietly when it is absent.
 */
document.addEventListener( 'DOMContentLoaded', function () {
	var form = document.getElementById( 'lp-links' );
	var all = document.getElementById( 'lp-all' );
	var banner = document.getElementById( 'lp-select-all' );

	if ( ! form || ! all || ! banner ) {
		return;
	}

	var offer = document.getElementById( 'lp-select-all-offer' );
	var active = document.getElementById( 'lp-select-all-active' );
	var masters = [ 'cb-select-all-1', 'cb-select-all-2' ]
		.map( function ( id ) {
			return document.getElementById( id );
		} )
		.filter( Boolean );

	function reset() {
		all.value = '';
		banner.hidden = true;
		offer.hidden = false;
		active.hidden = true;
	}

	masters.forEach( function ( cb ) {
		cb.addEventListener( 'change', function () {
			if ( cb.checked ) {
				banner.hidden = false;
			} else {
				reset();
			}
		} );
	} );

	// Unticking any row narrows the selection again.
	form.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( input.name === 'links[]' && ! input.checked ) {
			reset();
		}
	} );

	document
		.getElementById( 'lp-select-all-btn' )
		.addEventListener( 'click', function () {
			all.value = '1';
			offer.hidden = true;
			active.hidden = false;
		} );

	document
		.getElementById( 'lp-clear-selection-btn' )
		.addEventListener( 'click', function () {
			reset();
			form.querySelectorAll( '.check-column input[type=checkbox]' ).forEach(
				function ( cb ) {
					cb.checked = false;
				}
			);
		} );
} );
