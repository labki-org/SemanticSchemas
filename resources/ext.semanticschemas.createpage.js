/**
 * Create Page — category tree interaction logic.
 *
 * Categories are rendered as a nested tree by PHP (SpecialSemanticSchemas).
 * Each checkbox carries data attributes set by the server:
 *   data-category  — the category name (same for all instances of that category)
 *   data-ancestors — pipe-separated list of ancestor category names (from C3 linearization)
 *
 * This script adds three behaviors:
 * 1. Multi-instance sync: categories with multiple parents appear in the tree
 *    multiple times. Checking one instance checks all others.
 * 2. Ancestor redundancy: when a child is selected, its ancestors are greyed out
 *    because the child's dispatcher already chains ancestor semantic templates.
 * 3. Collapsible tree nodes: parent categories have a toggle arrow to show/hide
 *    their children.
 */
( function () {
	'use strict';

	var grid = document.querySelector( '.ss-create-cat-grid' );
	if ( !grid ) {
		return;
	}

	// Pre-build a map from category name → array of checkbox elements
	// so that syncing multi-instance categories is O(1) lookup.
	var checkboxes = grid.querySelectorAll( 'input[type="checkbox"][data-category]' );
	var byCategoryName = {};
	checkboxes.forEach( function ( cb ) {
		var name = cb.dataset.category;
		if ( !byCategoryName[ name ] ) {
			byCategoryName[ name ] = [];
		}
		byCategoryName[ name ].push( cb );
	} );

	/**
	 * Sync all checkbox instances of the same category to match the given state.
	 */
	function syncInstances( categoryName, checked ) {
		( byCategoryName[ categoryName ] || [] ).forEach( function ( cb ) {
			cb.checked = checked;
		} );
	}

	/**
	 * Scan all checked checkboxes and update ancestor redundancy state in one pass.
	 *
	 * For each checked category, its ancestors (from data-ancestors) are marked
	 * redundant — disabled and greyed out with a "via ChildName" indicator.
	 * If multiple checked categories share an ancestor, the last one wins
	 * for the "via" label (the visual effect is the same either way).
	 */
	function updateAncestorState() {
		// Pass 1: collect which ancestors are made redundant and by whom
		var redundant = {};
		checkboxes.forEach( function ( cb ) {
			if ( !cb.checked ) {
				return;
			}
			( cb.dataset.ancestors || '' ).split( '|' ).filter( Boolean )
				.forEach( function ( ancestor ) {
					redundant[ ancestor ] = cb.dataset.category;
				} );
		} );

		// Pass 2: apply visual state to each checkbox
		checkboxes.forEach( function ( cb ) {
			var item = cb.closest( '.ss-create-cat-item' );
			if ( !item ) {
				return;
			}
			var catName = cb.dataset.category;
			var isRedundant = redundant[ catName ] && !cb.checked;
			var viaEl = item.querySelector( '.ss-create-cat-via' );

			if ( isRedundant ) {
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
				// Don't re-enable checkboxes disabled because they're
				// already on the page (is-existing items)
				if ( !item.classList.contains( 'is-existing' ) ) {
					cb.disabled = false;
				}
				if ( viaEl ) {
					viaEl.remove();
				}
			}
		} );
	}

	// --- Event delegation on the grid container ---

	grid.addEventListener( 'change', function ( e ) {
		var cb = e.target;
		if ( cb.type !== 'checkbox' || !cb.dataset.category ) {
			return;
		}
		syncInstances( cb.dataset.category, cb.checked );
		updateAncestorState();
	} );

	grid.addEventListener( 'click', function ( e ) {
		var toggle = e.target.closest( '.ss-create-cat-toggle' );
		if ( !toggle ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();

		// Children container is the next sibling after the toggle's parent item
		var item = toggle.closest( '.ss-create-cat-item' );
		var children = item && item.nextElementSibling;
		if ( !children || !children.classList.contains( 'ss-create-cat-children' ) ) {
			return;
		}

		var collapsed = !children.classList.contains( 'is-collapsed' );
		children.classList.toggle( 'is-collapsed', collapsed );
		toggle.classList.toggle( 'is-open', !collapsed );
	} );

	// Set initial toggle state (all open) and compute initial redundancy
	grid.querySelectorAll( '.ss-create-cat-toggle' ).forEach( function ( toggle ) {
		toggle.classList.add( 'is-open' );
	} );
	updateAncestorState();
}() );
