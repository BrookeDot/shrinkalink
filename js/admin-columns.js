/**
 * Shrinkalink row actions on the Posts screen.
 *
 * Calls POST /shrinkalink/v1/posts/{id}/{link|refresh} and updates the row in place.
 */
( function () {
	const { __ } = wp.i18n;

	document.addEventListener( 'click', function ( event ) {
		const link = event.target.closest( '.shrinkalink-row-link' );

		if ( ! link ) {
			return;
		}

		event.preventDefault();

		// ignore extra clicks while a request is running
		if ( link.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}

		const label = link.textContent;
		const row   = link.closest( 'tr' );

		link.setAttribute( 'aria-disabled', 'true' );
		link.textContent = __( 'Working…', 'shrinkalink' );

		wp.apiFetch( {
			path: '/shrinkalink/v1/posts/' + link.dataset.postId + '/' + link.dataset.route,
			method: 'POST',
		} )
			.then( function ( response ) {

				// show the new count in the clicks column
				const clicks = row && row.querySelector( '.column-shrinkalink-clicks' );

				if ( clicks ) {
					clicks.textContent = response.clicks;
				}

				// show the short link, built with DOM methods so the URL can't inject markup
				const shortCell = row && row.querySelector( '.column-shrinkalink-url' );

				if ( shortCell && response.shortUrl ) {
					const anchor = document.createElement( 'a' );

					anchor.href        = response.shortUrl;
					anchor.target      = '_blank';
					anchor.rel         = 'noopener';
					anchor.textContent = response.shortUrl;

					shortCell.replaceChildren( anchor );
				}

				// a created link can now be refreshed
				link.dataset.route = 'refresh';
				link.textContent   = __( 'Refresh Shlink', 'shrinkalink' );
				link.title         = response.shortUrl;
			} )
			.catch( function ( error ) {
				// REST errors carry Shlink's or WordPress's message
				link.textContent = label;
				window.alert( error.message || __( 'Something went wrong.', 'shrinkalink' ) );
			} )
			.finally( function () {
				link.removeAttribute( 'aria-disabled' );
			} );
	} );
} )();
