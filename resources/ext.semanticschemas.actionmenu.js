/**
 * SemanticSchemas action menu.
 *
 * Extracts SemanticSchemas action items (ss-*) from the generic "More" dropdown
 * and places them in a dedicated "SemanticSchemas" dropdown in the page toolbar.
 */
( function () {
	'use strict';

	function init() {
		// Find all SemanticSchemas items in the More/actions menu
		var cactions = document.getElementById( 'p-cactions' );
		if ( !cactions ) {
			return;
		}

		var actionList = cactions.querySelector( 'ul' );
		if ( !actionList ) {
			return;
		}

		var ssItems = actionList.querySelectorAll( 'li[id^="ca-ss-"]' );
		if ( ssItems.length === 0 ) {
			return;
		}

		// Clone the More dropdown structure for our new menu
		var ssMenu = cactions.cloneNode( false );
		ssMenu.id = 'p-ss-actions';
		ssMenu.className = cactions.className;

		// Build the inner structure — mirror Vector's dropdown markup
		var heading = document.createElement( 'div' );
		heading.className = 'vector-menu-heading';

		var label = document.createElement( 'span' );
		label.className = 'vector-menu-heading-label';
		label.textContent = 'SemanticSchemas';
		heading.appendChild( label );

		var content = document.createElement( 'div' );
		content.className = 'vector-menu-content';

		var list = document.createElement( 'ul' );
		list.className = 'vector-menu-content-list';

		// Move items from More to our menu
		ssItems.forEach( function ( item ) {
			list.appendChild( item );
		} );

		content.appendChild( list );
		ssMenu.appendChild( heading );
		ssMenu.appendChild( content );

		// Insert after the More dropdown
		cactions.parentNode.insertBefore( ssMenu, cactions.nextSibling );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
