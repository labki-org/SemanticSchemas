/**
 * Create Page — category tree interaction logic.
 *
 * Categories are rendered as a nested tree by PHP (SpecialSemanticSchemas).
 * Each checkbox carries data attributes set by the server:
 *   data-category  — the category name (same for all instances of that category)
 *   data-ancestors — pipe-separated list of ancestor category names (from C3 linearization)
 *
 * This script adds four behaviors:
 * 1. Multi-instance sync: categories with multiple parents appear in the tree
 *    multiple times. Checking one instance checks all others.
 * 2. Ancestor redundancy: when a child is selected, its ancestors are greyed out
 *    because the child's dispatcher already chains ancestor semantic templates.
 * 3. Collapsible tree nodes: parent categories have a toggle arrow to show/hide
 *    their children.
 * 4. Live search: typing in the search box filters the tree, auto-expanding
 *    parents to show matches and hiding non-matching items.
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

	// --- Live search filtering ---

	var searchInput = document.getElementById( 'ss-cat-search' );
	var allItems = grid.querySelectorAll( '.ss-create-cat-item' );
	var allChildContainers = grid.querySelectorAll( '.ss-create-cat-children' );
	var allToggles = grid.querySelectorAll( '.ss-create-cat-toggle' );

	function filterTree( query ) {
		query = query.toLowerCase().trim();

		if ( !query ) {
			// Reset: show everything, clear highlights, restore collapse state
			allItems.forEach( function ( item ) {
				item.classList.remove( 'ss-search-hidden' );
				item.classList.remove( 'ss-search-match' );
			} );
			allChildContainers.forEach( function ( container ) {
				container.classList.remove( 'ss-search-hidden' );
				container.classList.remove( 'is-collapsed' );
			} );
			allToggles.forEach( function ( toggle ) {
				toggle.classList.add( 'is-open' );
			} );
			return;
		}

		// Determine which categories match the search
		var matchingCategories = {};
		checkboxes.forEach( function ( cb ) {
			var catName = cb.dataset.category;
			var item = cb.closest( '.ss-create-cat-item' );
			if ( !item ) {
				return;
			}
			// Match against category label and description, not dynamic annotations
			var labelEl = item.querySelector( '.ss-create-cat-label' );
			var label = ( labelEl || item ).textContent.toLowerCase();
			if ( label.indexOf( query ) !== -1 ) {
				matchingCategories[ catName ] = true;
			}
		} );

		// For each matching category, also mark its ancestors as visible
		// so the parent chain is shown for context
		var visibleCategories = {};
		Object.keys( matchingCategories ).forEach( function ( catName ) {
			visibleCategories[ catName ] = true;
			( byCategoryName[ catName ] || [] ).forEach( function ( cb ) {
				( cb.dataset.ancestors || '' ).split( '|' ).filter( Boolean )
					.forEach( function ( ancestor ) {
						visibleCategories[ ancestor ] = true;
					} );
			} );
		} );

		// Apply visibility and highlight direct matches
		allItems.forEach( function ( item ) {
			var cb = item.querySelector( 'input[type="checkbox"][data-category]' );
			if ( !cb ) {
				return;
			}
			var catName = cb.dataset.category;
			if ( visibleCategories[ catName ] ) {
				item.classList.remove( 'ss-search-hidden' );
				if ( matchingCategories[ catName ] ) {
					item.classList.add( 'ss-search-match' );
				} else {
					item.classList.remove( 'ss-search-match' );
				}
			} else {
				item.classList.add( 'ss-search-hidden' );
				item.classList.remove( 'ss-search-match' );
			}
		} );

		// Expand all child containers that have visible items, hide empty ones
		allChildContainers.forEach( function ( container ) {
			var hasVisible = container.querySelector(
				'.ss-create-cat-item:not(.ss-search-hidden)'
			);
			if ( hasVisible ) {
				container.classList.remove( 'ss-search-hidden' );
				container.classList.remove( 'is-collapsed' );
			} else {
				container.classList.add( 'ss-search-hidden' );
			}
		} );

		// Expand all toggles when searching
		allToggles.forEach( function ( toggle ) {
			toggle.classList.add( 'is-open' );
		} );
	}

	if ( searchInput ) {
		searchInput.addEventListener( 'input', function () {
			filterTree( searchInput.value );
		} );
	}

	// Set initial toggle state (all open) and compute initial redundancy
	grid.querySelectorAll( '.ss-create-cat-toggle' ).forEach( function ( toggle ) {
		toggle.classList.add( 'is-open' );
	} );
	updateAncestorState();
}() );
