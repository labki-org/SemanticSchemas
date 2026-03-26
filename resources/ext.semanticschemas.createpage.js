/**
 * Create Page — category tree interaction logic.
 *
 * Handles:
 * - Syncing checkboxes for categories that appear multiple times in the tree
 *   (due to multiple inheritance)
 * - Greying out ancestor categories when a descendant is selected, since the
 *   descendant's dispatcher already chains ancestor semantic templates
 */
( function () {
	'use strict';

	var grid = document.querySelector( '.ss-create-cat-grid' );
	if ( !grid ) {
		return;
	}

	var checkboxes = grid.querySelectorAll( 'input[type="checkbox"][data-category]' );

	/**
	 * Sync all checkbox instances of the same category.
	 */
	function syncInstances( categoryName, checked ) {
		checkboxes.forEach( function ( cb ) {
			if ( cb.dataset.category === categoryName ) {
				cb.checked = checked;
			}
		} );
	}

	/**
	 * Collect all ancestors for all currently checked categories.
	 */
	function getRedundantAncestors() {
		var redundant = {};
		checkboxes.forEach( function ( cb ) {
			if ( !cb.checked ) {
				return;
			}
			var ancestors = ( cb.dataset.ancestors || '' ).split( '|' ).filter( Boolean );
			ancestors.forEach( function ( a ) {
				redundant[ a ] = cb.dataset.category;
			} );
		} );
		return redundant;
	}

	/**
	 * Update the visual state of all items based on current selections.
	 */
	function updateAncestorState() {
		var redundant = getRedundantAncestors();

		checkboxes.forEach( function ( cb ) {
			var item = cb.closest( '.ss-create-cat-item' );
			if ( !item ) {
				return;
			}
			var catName = cb.dataset.category;
			var viaEl = item.querySelector( '.ss-create-cat-via' );

			if ( redundant[ catName ] && !cb.checked ) {
				item.classList.add( 'is-redundant' );
				cb.disabled = true;
				if ( !viaEl ) {
					viaEl = document.createElement( 'span' );
					viaEl.className = 'ss-create-cat-via';
					item.querySelector( '.ss-create-cat-label' ).appendChild( viaEl );
				}
				viaEl.textContent = 'via ' + redundant[ catName ];
			} else {
				item.classList.remove( 'is-redundant' );
				// Don't re-enable checkboxes that are disabled because they're
				// already on the page (is-existing)
				if ( !item.classList.contains( 'is-existing' ) ) {
					cb.disabled = false;
				}
				if ( viaEl ) {
					viaEl.remove();
				}
			}
		} );
	}

	// Attach checkbox change handlers
	checkboxes.forEach( function ( cb ) {
		cb.addEventListener( 'change', function () {
			syncInstances( cb.dataset.category, cb.checked );
			updateAncestorState();
		} );
	} );

	// Attach toggle handlers for collapsible tree nodes
	grid.querySelectorAll( '.ss-create-cat-toggle' ).forEach( function ( toggle ) {
		// Start open
		toggle.classList.add( 'is-open' );

		toggle.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();

			var item = toggle.closest( '.ss-create-cat-item' );
			var children = item.nextElementSibling;
			if ( !children || !children.classList.contains( 'ss-create-cat-children' ) ) {
				return;
			}

			var collapsed = !children.classList.contains( 'is-collapsed' );
			children.classList.toggle( 'is-collapsed', collapsed );
			toggle.classList.toggle( 'is-open', !collapsed );
		} );
	} );

	// Initial state
	updateAncestorState();
}() );
