/**
 * StructureSync Hierarchy Visualization
 * --------------------------------------
 * Modernized, modularized, and performance-optimized rewrite.
 *
 * NOTE:
 *   - No UI/UX changes
 *   - Behavior identical to original
 *   - Significantly reduced repetition
 *   - Centralized helpers for links, titles, sorting, required flags
 */
( function ( mw, $ ) {
	'use strict';

	/* =======================================================================
	 * HELPERS
	 * ======================================================================= */

	const msg = name => mw.msg( name );

	const stripPrefix = ( title, prefix ) =>
		typeof title === 'string'
			? title.replace( new RegExp( '^' + prefix + ':' ), '' )
			: '';

	const isRequired = val => val === 1 || val === true;

	const buildLink = ( fullTitle, displayPrefix ) => {
		if ( !fullTitle ) {
			return $( '<span>' ).text( '—' );
		}
		const display = stripPrefix( fullTitle, displayPrefix );
		return $( '<a>' )
			.attr( 'href', mw.util.getUrl( fullTitle ) )
			.attr( 'title', fullTitle )
			.text( display );
	};

	const sortedKeys = obj =>
		Object.keys( obj ).sort( ( a, b ) => a.localeCompare( b ) );

	const renderError = ( $c, m ) =>
		$c.empty().append( $( '<p>' ).addClass( 'error' ).text( m ) );

	const renderEmpty = ( $c, m ) =>
		$c.empty().append( $( '<p>' ).addClass( 'ss-hierarchy-empty' ).text( m ) );

	/* =======================================================================
	 * HIERARCHY TREE
	 * ======================================================================= */

	function renderHierarchyTree( $container, data ) {
		const root = data.rootCategory;
		const nodes = data.nodes || {};

		if ( !root || !nodes[ root ] ) {
			renderEmpty( $container, msg( 'structuresync-hierarchy-no-data' ) );
			return;
		}

		/* Recursive builder */
		const buildNode = title => {
			const node = nodes[ title ];
			if ( !node ) {
				return null;
			}

			const parents = Array.isArray( node.parents ) ? node.parents : [];
			const $li = $( '<li>' );
			const $content = $( '<span>' ).addClass( 'ss-hierarchy-node-content' );

			if ( parents.length ) {
				$content.append(
					$( '<span>' )
						.addClass( 'ss-hierarchy-toggle' )
						.attr( { role: 'button', tabindex: 0, 'aria-expanded': 'true' } )
						.text( '▼' )
				);
				$li.addClass( 'ss-hierarchy-has-children' );
			}

			$content.append( ' ', buildLink( title, 'Category' ) );
			$li.append( $content );

			if ( parents.length ) {
				const $ul = $( '<ul>' ).addClass( 'ss-hierarchy-tree-nested' );
				for ( const p of parents ) {
					const child = buildNode( p );
					if ( child ) {
						$ul.append( child );
					}
				}
				$li.append( $ul );
			}

			return $li;
		};

		const $rootTree = $( '<ul>' ).addClass( 'ss-hierarchy-tree' );
		const $rootNode = buildNode( root );
		if ( $rootNode ) {
			$rootTree.append( $rootNode );
		}

		$container.empty().append( $rootTree );

		/* Toggle handlers */
		$container.off( 'click.ssToggle keydown.ssToggle' );

		$container.on( 'click.ssToggle', '.ss-hierarchy-toggle', function ( e ) {
			e.preventDefault();
			const $toggle = $( this );
			const $li = $toggle.closest( 'li' );
			const $nested = $li.children( '.ss-hierarchy-tree-nested' );

			const expanded = $nested.is( ':visible' );
			if ( expanded ) {
				$nested.slideUp( 200 );
				$toggle.text( '▶' ).attr( 'aria-expanded', 'false' );
				$li.addClass( 'ss-hierarchy-collapsed' );
			} else {
				$nested.slideDown( 200 );
				$toggle.text( '▼' ).attr( 'aria-expanded', 'true' );
				$li.removeClass( 'ss-hierarchy-collapsed' );
			}
		} );

		$container.on( 'keydown.ssToggle', '.ss-hierarchy-toggle', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				$( this ).trigger( 'click' );
			}
		} );
	}

	/* =======================================================================
	 * PROPERTIES — GROUPED BY CATEGORY
	 * ======================================================================= */

	function renderPropertiesByCategory( props ) {
		if ( !props.length ) {
			return $( '<p>' ).addClass( 'ss-hierarchy-empty' ).text(
				msg( 'structuresync-hierarchy-no-properties' )
			);
		}

		const grouped = {};
		for ( const p of props ) {
			const s = p.sourceCategory || '';
			( grouped[ s ] ||= [] ).push( p );
		}

		const $table = $( '<table>' )
			.addClass( 'wikitable ss-prop-table' )
			.append(
				$( '<thead>' ).append(
					$( '<tr>' )
						.append( $( '<th>' ).text( msg( 'structuresync-hierarchy-source-category' ) ) )
						.append( $( '<th>' ).text( msg( 'structuresync-hierarchy-properties' ) ) )
				)
			);

		const $tbody = $( '<tbody>' );

		for ( const source of sortedKeys( grouped ) ) {
			const list = grouped[ source ];
			const $row = $( '<tr>' );

			/* Source cell */
			const $srcCell = $( '<td>' ).addClass( 'ss-prop-source-cell' );
			if ( source ) {
				$srcCell.append( buildLink( source, 'Category' ) );
			} else {
				$srcCell.text( msg( 'structuresync-hierarchy-unknown-category' ) );
			}

			/* Properties cell */
			const $propList = $( '<ul>' ).addClass( 'ss-prop-list' );

			for ( const p of list ) {
				const $li = $( '<li>' )
					.addClass( isRequired( p.required ) ? 'ss-prop-required' : 'ss-prop-optional' );

				if ( p.propertyTitle ) {
					$li.append(
						buildLink( p.propertyTitle, 'Property' ),
						' ',
						$( '<span>' )
							.addClass( 'ss-prop-badge' )
							.text(
								isRequired( p.required )
									? msg( 'structuresync-hierarchy-required' )
									: msg( 'structuresync-hierarchy-optional' )
							)
					);
				} else {
					$li.text( msg( 'structuresync-hierarchy-unnamed-property' ) );
				}

				$propList.append( $li );
			}

			$row.append( $srcCell )
				.append( $( '<td>' ).addClass( 'ss-prop-list-cell' ).append( $propList ) );
			$tbody.append( $row );
		}

		return $table.append( $tbody );
	}

	/* =======================================================================
	 * PROPERTIES — REQUIRED vs OPTIONAL
	 * ======================================================================= */

	function renderPropertiesByType( props ) {
		const required = [];
		const optional = [];
		for ( const p of props ) {
			( isRequired( p.required ) ? required : optional ).push( p );
		}

		const sortFn = ( a, b ) =>
			( a.propertyTitle || '' ).localeCompare( b.propertyTitle || '' );

		required.sort( sortFn );
		optional.sort( sortFn );

		const buildList = ( arr, css ) => {
			const $ul = $( '<ul>' ).addClass( 'ss-prop-list ss-prop-list-by-type' );
			for ( const p of arr ) {
				const $li = $( '<li>' ).addClass( css );

				if ( p.propertyTitle ) {
					$li.append( buildLink( p.propertyTitle, 'Property' ) );

					if ( p.sourceCategory ) {
						$li.append(
							' ',
							$( '<span>' ).addClass( 'ss-prop-source-label' ).append(
								'(',
								buildLink( p.sourceCategory, 'Category' ),
								')'
							)
						);
					}
				} else {
					$li.text( msg( 'structuresync-hierarchy-unnamed-property' ) );
				}

				$ul.append( $li );
			}
			return $ul;
		};

		const $root = $( '<div>' ).addClass( 'ss-prop-by-type' );

		if ( required.length ) {
			$root.append(
				$( '<div>' ).addClass( 'ss-prop-type-section ss-prop-type-required-section' )
					.append( $( '<h4>' ).text( `Required Properties (${ required.length })` ) )
					.append( buildList( required, 'ss-prop-required' ) )
			);
		}

		if ( optional.length ) {
			$root.append(
				$( '<div>' ).addClass( 'ss-prop-type-section ss-prop-type-optional-section' )
					.append( $( '<h4>' ).text( `Optional Properties (${ optional.length })` ) )
					.append( buildList( optional, 'ss-prop-optional' ) )
			);
		}

		return $root;
	}

	/* =======================================================================
	 * SUBGROUP TABLE
	 * ======================================================================= */

	function renderSubgroupTable( $container, data ) {
		const list = data.inheritedSubgroups || [];
		if ( !list.length ) {
			return renderEmpty( $container, msg( 'structuresync-hierarchy-no-subgroups' ) );
		}

		const $table = $( '<table>' )
			.addClass( 'wikitable ss-subgroup-summary' )
			.append(
				$( '<thead>' ).append(
					$( '<tr>' )
						.append( $( '<th>' ).text( msg( 'structuresync-hierarchy-subgroup-name' ) ) )
						.append( $( '<th>' ).text( msg( 'structuresync-hierarchy-source-category' ) ) )
						.append( $( '<th>' ).text( msg( 'structuresync-hierarchy-required-state' ) ) )
				)
			);

		const $tbody = $( '<tbody>' );

		for ( const s of list ) {
			const required = isRequired( s.required );
			$tbody.append(
				$( '<tr>' )
					.append(
						$( '<td>' ).append(
							s.subgroupTitle
								? buildLink( s.subgroupTitle, 'Subobject' )
								: stripPrefix( s.subgroupTitle, 'Subobject' ) || '—'
						)
					)
					.append(
						$( '<td>' ).append(
							s.sourceCategory
								? buildLink( s.sourceCategory, 'Category' )
								: stripPrefix( s.sourceCategory, 'Category' ) || '—'
						)
					)
					.append(
						$( '<td>' )
							.addClass( required ? 'ss-prop-required' : 'ss-prop-optional' )
							.text(
								required
									? msg( 'structuresync-hierarchy-required' )
									: msg( 'structuresync-hierarchy-optional' )
							)
					)
			);
		}

		$container.empty().append( $table.append( $tbody ) );
	}

	/* =======================================================================
	 * PROPERTIES TAB WRAPPER
	 * ======================================================================= */

	function renderPropertyTable( $container, data ) {
		const props = data.inheritedProperties || [];
		if ( !props.length ) {
			return renderEmpty( $container, msg( 'structuresync-hierarchy-no-properties' ) );
		}

		const $tabs = $( '<div>' ).addClass( 'ss-prop-tabs' );
		const $byCat = $( '<button>' )
			.addClass( 'ss-prop-tab ss-prop-tab-active' )
			.attr( 'data-tab', 'category' )
			.text( 'By Category' );
		const $byType = $( '<button>' )
			.addClass( 'ss-prop-tab' )
			.attr( 'data-tab', 'type' )
			.text( 'By Type' );
		$tabs.append( $byCat, $byType );

		const $contents = $( '<div>' ).addClass( 'ss-prop-tab-contents' );
		const $catContent = $( '<div>' )
			.addClass( 'ss-prop-tab-content ss-prop-tab-content-active' )
			.attr( 'data-content', 'category' )
			.append( renderPropertiesByCategory( props ) );
		const $typeContent = $( '<div>' )
			.addClass( 'ss-prop-tab-content' )
			.attr( 'data-content', 'type' )
			.append( renderPropertiesByType( props ) );

		$contents.append( $catContent, $typeContent );

		$container.empty().append( $tabs, $contents );

		/* Tab toggle */
		$tabs.on( 'click', '.ss-prop-tab', function () {
			const tab = $( this ).data( 'tab' );
			$tabs.find( '.ss-prop-tab' ).removeClass( 'ss-prop-tab-active' );
			$( this ).addClass( 'ss-prop-tab-active' );
			$contents.find( '.ss-prop-tab-content' )
				.removeClass( 'ss-prop-tab-content-active' );
			$contents.find( `[data-content="${ tab }"]` )
				.addClass( 'ss-prop-tab-content-active' );
		} );
	}

	/* =======================================================================
	 * FETCH + RENDER WRAPPER
	 * ======================================================================= */

	function fetchAndRender( $root, title ) {
		$root
			.addClass( 'ss-hierarchy-loading' )
			.empty()
			.append( $( '<p>' ).text( msg( 'structuresync-hierarchy-loading' ) ) );

		new mw.Api()
			.get( {
				action: 'structuresync-hierarchy',
				category: title,
				format: 'json'
			} )
			.done( data => {
				$root.removeClass( 'ss-hierarchy-loading' );

				const payload = data[ 'structuresync-hierarchy' ];
				if ( !payload ) {
					return renderError(
						$root,
						msg( 'structuresync-hierarchy-no-data' )
					);
				}

				const $tree = $( '<div>' ).addClass( 'ss-hierarchy-tree-container' );
				const $props = $( '<div>' ).addClass( 'ss-hierarchy-props-container' );
				const $subs = $( '<div>' ).addClass( 'ss-hierarchy-subgroups-container' );

				$root.empty().append(
					$( '<div>' ).addClass( 'ss-hierarchy-section' )
						.append(
							$( '<h3>' ).text( msg( 'structuresync-hierarchy-tree-title' ) ),
							$tree
						),
					$( '<div>' ).addClass( 'ss-hierarchy-section' )
						.append(
							$( '<h3>' ).text( msg( 'structuresync-hierarchy-props-title' ) ),
							$props
						),
					$( '<div>' ).addClass( 'ss-hierarchy-section' )
						.append(
							$( '<h3>' ).text( msg( 'structuresync-hierarchy-subgroups-title' ) ),
							$subs
						)
				);

				renderHierarchyTree( $tree, payload );
				renderPropertyTable( $props, payload );
				renderSubgroupTable( $subs, payload );
			} )
			.fail( ( code, result ) => {
				$root.removeClass( 'ss-hierarchy-loading' );
				renderError(
					$root,
					msg( 'structuresync-hierarchy-error' ) + ': ' +
						( result.error?.info || code )
				);
			} );
	}

	/* =======================================================================
	 * PUBLIC API
	 * ======================================================================= */

	mw.StructureSyncHierarchy = {
		renderInto: ( container, title ) => {
			const $root = $( container );
			if ( !$root.length ) {
				mw.log.warn( 'StructureSyncHierarchy: Missing container' );
				return;
			}
			if ( !title ) {
				return renderError(
					$root,
					msg( 'structuresync-hierarchy-no-category' )
				);
			}
			fetchAndRender( $root, title );
		}
	};

	/* =======================================================================
	 * AUTO-INIT
	 * ======================================================================= */

	$( () => {
		$( '.ss-hierarchy-block[data-category]' ).each( function () {
			const $node = $( this );
			const title = $node.data( 'category' );
			if ( title ) {
				mw.StructureSyncHierarchy.renderInto( $node, title );
			}
		} );
	} );

}( mediaWiki, jQuery ) );
