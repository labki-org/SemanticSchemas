/**
 * SemanticSchemas Hierarchy Visualization
 * --------------------------------------
 * Modernized, modularized, and performance-optimized rewrite.
 *
 * NOTE:
 * - No UI/UX changes
 * - Behavior identical to original
 * - Significantly reduced repetition
 * - Centralized helpers for links, titles, sorting, required flags
 *
 * @param {Object} mw
 * @param {jQuery} $
 */
( function ( mw, $ ) {
	'use strict';

	/* =======================================================================
	 * HELPERS
	 * ======================================================================= */

	const msg = ( name ) => mw.msg( name );

	const stripPrefix = ( title, prefix ) => typeof title === 'string' ?
		title.replace( new RegExp( '^' + prefix + ':' ), '' ) :
		'';

	const isRequired = ( val ) => val === 1 || val === true;

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

	const sortedKeys = ( obj ) => Object.keys( obj ).sort( ( a, b ) => a.localeCompare( b ) );

	const renderError = ( $c, m ) => $c.empty().append( $( '<p>' ).addClass( 'error' ).text( m ) );

	const renderEmpty = ( $c, m ) => $c.empty().append( $( '<p>' ).addClass( 's2-hierarchy-empty' ).text( m ) );

	/* =======================================================================
	 * HIERARCHY TREE
	 * ======================================================================= */

	function renderHierarchyTree( $container, data ) {
		const root = data.rootCategory;
		const nodes = data.nodes || {};

		if ( !root || !nodes[ root ] ) {
			renderEmpty( $container, msg( 'semanticschemas-hierarchy-no-data' ) );
			return;
		}

		/* Recursive builder */
		const buildNode = ( title ) => {
			const node = nodes[ title ];
			if ( !node ) {
				return null;
			}

			const parents = Array.isArray( node.parents ) ? node.parents : [];
			const $li = $( '<li>' );
			const $content = $( '<span>' ).addClass( 's2-hierarchy-node-content' );

			if ( parents.length ) {
				$content.append(
					$( '<span>' )
						.addClass( 's2-hierarchy-toggle' )
						.attr( { role: 'button', tabindex: 0, 'aria-expanded': 'true' } )
						.text( '▼' )
				);
				$li.addClass( 's2-hierarchy-has-children' );
			}

			$content.append( ' ', buildLink( title, 'Category' ) );
			$li.append( $content );

			if ( parents.length ) {
				const $ul = $( '<ul>' ).addClass( 's2-hierarchy-tree-nested' );
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

		const $rootTree = $( '<ul>' ).addClass( 's2-hierarchy-tree' );
		const $rootNode = buildNode( root );
		if ( $rootNode ) {
			$rootTree.append( $rootNode );
		}

		$container.empty().append( $rootTree );

		/* Toggle handlers */
		$container.off( 'click.ssToggle keydown.ssToggle' );

		$container.on( 'click.ssToggle', '.s2-hierarchy-toggle', function ( e ) {
			e.preventDefault();
			const $toggle = $( this );
			const $li = $toggle.closest( 'li' );
			const $nested = $li.children( '.s2-hierarchy-tree-nested' );

			const expanded = $nested.is( ':visible' );
			if ( expanded ) {
				$nested.slideUp( 200 );
				$toggle.text( '▶' ).attr( 'aria-expanded', 'false' );
				$li.addClass( 's2-hierarchy-collapsed' );
			} else {
				$nested.slideDown( 200 );
				$toggle.text( '▼' ).attr( 'aria-expanded', 'true' );
				$li.removeClass( 's2-hierarchy-collapsed' );
			}
		} );

		$container.on( 'keydown.ssToggle', '.s2-hierarchy-toggle', function ( e ) {
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
			return $( '<p>' ).addClass( 's2-hierarchy-empty' ).text(
				msg( 'semanticschemas-hierarchy-no-properties' )
			);
		}

		const grouped = {};
		for ( const p of props ) {
			const s = p.sourceCategory || '';
			if ( !grouped[ s ] ) {
				grouped[ s ] = [];
			}
			grouped[ s ].push( p );
		}

		const $table = $( '<table>' )
			.addClass( 'wikitable s2-prop-table' )
			.append(
				$( '<thead>' ).append(
					$( '<tr>' )
						.append( $( '<th>' ).text( msg( 'semanticschemas-hierarchy-source-category' ) ) )
						.append( $( '<th>' ).text( msg( 'semanticschemas-hierarchy-properties' ) ) )
				)
			);

		const $tbody = $( '<tbody>' );

		for ( const source of sortedKeys( grouped ) ) {
			const list = grouped[ source ];
			const $row = $( '<tr>' );

			/* Source cell */
			const $srcCell = $( '<td>' ).addClass( 's2-prop-source-cell' );
			if ( source ) {
				$srcCell.append( buildLink( source, 'Category' ) );
			} else {
				$srcCell.text( msg( 'semanticschemas-hierarchy-unknown-category' ) );
			}

			/* Properties cell */
			const $propList = $( '<ul>' ).addClass( 's2-prop-list' );

			for ( const p of list ) {
				const $li = $( '<li>' )
					.addClass( isRequired( p.required ) ? 's2-prop-required' : 's2-prop-optional' );

				if ( p.propertyTitle ) {
					$li.append(
						buildLink( p.propertyTitle, 'Property' ),
						' ',
						$( '<span>' )
							.addClass( 's2-prop-badge' )
							.text(
								isRequired( p.required ) ?
									msg( 'semanticschemas-hierarchy-required' ) :
									msg( 'semanticschemas-hierarchy-optional' )
							)
					);
				} else {
					$li.text( msg( 'semanticschemas-hierarchy-unnamed-property' ) );
				}

				$propList.append( $li );
			}

			$row.append( $srcCell )
				.append( $( '<td>' ).addClass( 's2-prop-list-cell' ).append( $propList ) );
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

		const sortFn = ( a, b ) => ( a.propertyTitle || '' ).localeCompare( b.propertyTitle || '' );

		required.sort( sortFn );
		optional.sort( sortFn );

		const buildList = ( arr, css ) => {
			const $ul = $( '<ul>' ).addClass( 's2-prop-list s2-prop-list-by-type' );
			for ( const p of arr ) {
				const $li = $( '<li>' ).addClass( css );

				if ( p.propertyTitle ) {
					$li.append( buildLink( p.propertyTitle, 'Property' ) );

					if ( p.sourceCategory ) {
						$li.append(
							' ',
							$( '<span>' ).addClass( 's2-prop-source-label' ).append(
								'(',
								buildLink( p.sourceCategory, 'Category' ),
								')'
							)
						);
					}
				} else {
					$li.text( msg( 'semanticschemas-hierarchy-unnamed-property' ) );
				}

				$ul.append( $li );
			}
			return $ul;
		};

		const $root = $( '<div>' ).addClass( 's2-prop-by-type' );

		if ( required.length ) {
			$root.append(
				$( '<div>' ).addClass( 's2-prop-type-section s2-prop-type-required-section' )
					.append( $( '<h4>' ).text( `Required Properties (${ required.length })` ) )
					.append( buildList( required, 's2-prop-required' ) )
			);
		}

		if ( optional.length ) {
			$root.append(
				$( '<div>' ).addClass( 's2-prop-type-section s2-prop-type-optional-section' )
					.append( $( '<h4>' ).text( `Optional Properties (${ optional.length })` ) )
					.append( buildList( optional, 's2-prop-optional' ) )
			);
		}

		return $root;
	}

	/* =======================================================================
	 * SUBGROUP TABLE
	 * ======================================================================= */

	function renderSubobjectTable( $container, data ) {
		const list = data.inheritedSubobjects || [];
		if ( !list.length ) {
			return renderEmpty( $container, msg( 'semanticschemas-hierarchy-no-subobjects' ) );
		}

		const $table = $( '<table>' )
			.addClass( 'wikitable s2-subobject-summary' )
			.append(
				$( '<thead>' ).append(
					$( '<tr>' )
						.append( $( '<th>' ).text( msg( 'semanticschemas-hierarchy-subobject-name' ) ) )
						.append( $( '<th>' ).text( msg( 'semanticschemas-hierarchy-source-category' ) ) )
						.append( $( '<th>' ).text( msg( 'semanticschemas-hierarchy-required-state' ) ) )
				)
			);

		const $tbody = $( '<tbody>' );

		for ( const s of list ) {
			const required = isRequired( s.required );
			$tbody.append(
				$( '<tr>' )
					.append(
						$( '<td>' ).append(
							s.subobjectTitle ?
								buildLink( s.subobjectTitle, 'Subobject' ) :
								stripPrefix( s.subobjectTitle, 'Subobject' ) || '—'
						)
					)
					.append(
						$( '<td>' ).append(
							s.sourceCategory ?
								buildLink( s.sourceCategory, 'Category' ) :
								stripPrefix( s.sourceCategory, 'Category' ) || '—'
						)
					)
					.append(
						$( '<td>' )
							.addClass( required ? 's2-prop-required' : 's2-prop-optional' )
							.text(
								required ?
									msg( 'semanticschemas-hierarchy-required' ) :
									msg( 'semanticschemas-hierarchy-optional' )
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
			return renderEmpty( $container, msg( 'semanticschemas-hierarchy-no-properties' ) );
		}

		const $tabs = $( '<div>' ).addClass( 's2-prop-tabs' );
		const $byCat = $( '<button>' )
			.addClass( 's2-prop-tab s2-prop-tab-active' )
			.attr( 'data-tab', 'category' )
			.text( 'By Category' );
		const $byType = $( '<button>' )
			.addClass( 's2-prop-tab' )
			.attr( 'data-tab', 'type' )
			.text( 'By Type' );
		$tabs.append( $byCat, $byType );

		const $contents = $( '<div>' ).addClass( 's2-prop-tab-contents' );
		const $catContent = $( '<div>' )
			.addClass( 's2-prop-tab-content s2-prop-tab-content-active' )
			.attr( 'data-content', 'category' )
			.append( renderPropertiesByCategory( props ) );
		const $typeContent = $( '<div>' )
			.addClass( 's2-prop-tab-content' )
			.attr( 'data-content', 'type' )
			.append( renderPropertiesByType( props ) );

		$contents.append( $catContent, $typeContent );

		$container.empty().append( $tabs, $contents );

		/* Tab toggle */
		$tabs.on( 'click', '.s2-prop-tab', function () {
			const tab = $( this ).data( 'tab' );
			$tabs.find( '.s2-prop-tab' ).removeClass( 's2-prop-tab-active' );
			$( this ).addClass( 's2-prop-tab-active' );
			$contents.find( '.s2-prop-tab-content' )
				.removeClass( 's2-prop-tab-content-active' );
			$contents.find( `[data-content="${ tab }"]` )
				.addClass( 's2-prop-tab-content-active' );
		} );
	}

	/* =======================================================================
	 * FETCH + RENDER WRAPPER
	 * ======================================================================= */

	function fetchAndRender( $root, title ) {
		$root
			.addClass( 's2-hierarchy-loading' )
			.empty()
			.append( $( '<p>' ).text( msg( 'semanticschemas-hierarchy-loading' ) ) );

		new mw.Api()
			.get( {
				action: 'semanticschemas-hierarchy',
				category: title,
				format: 'json'
			} )
			.done( ( data ) => {
				$root.removeClass( 's2-hierarchy-loading' );

				const payload = data[ 'semanticschemas-hierarchy' ];
				if ( !payload ) {
					return renderError(
						$root,
						msg( 'semanticschemas-hierarchy-no-data' )
					);
				}

				const $tree = $( '<div>' ).addClass( 's2-hierarchy-tree-container' );
				const $props = $( '<div>' ).addClass( 's2-hierarchy-props-container' );
				const $subs = $( '<div>' ).addClass( 's2-hierarchy-subobjects-container' );

				$root.empty().append(
					$( '<div>' ).addClass( 's2-hierarchy-section' )
						.append(
							$( '<h3>' ).text( msg( 'semanticschemas-hierarchy-tree-title' ) ),
							$tree
						),
					$( '<div>' ).addClass( 's2-hierarchy-section' )
						.append(
							$( '<h3>' ).text( msg( 'semanticschemas-hierarchy-props-title' ) ),
							$props
						),
					$( '<div>' ).addClass( 's2-hierarchy-section' )
						.append(
							$( '<h3>' ).text( msg( 'semanticschemas-hierarchy-subobjects-title' ) ),
							$subs
						)
				);

				renderHierarchyTree( $tree, payload );
				renderPropertyTable( $props, payload );
				renderSubobjectTable( $subs, payload );
			} )
			.fail( ( code, result ) => {
				$root.removeClass( 's2-hierarchy-loading' );
				renderError(
					$root,
					msg( 'semanticschemas-hierarchy-error' ) + ': ' +
					( ( result.error && result.error.info ) || code )
				);
			} );
	}

	/* =======================================================================
	 * PUBLIC API
	 * ======================================================================= */

	mw.SemanticSchemasHierarchy = {
		renderInto: ( container, title ) => {
			const $root = $( container );
			if ( !$root.length ) {
				mw.log.warn( 'SemanticSchemasHierarchy: Missing container' );
				return;
			}
			if ( !title ) {
				return renderError(
					$root,
					msg( 'semanticschemas-hierarchy-no-category' )
				);
			}
			fetchAndRender( $root, title );
		}
	};

	/* =======================================================================
	 * AUTO-INIT
	 * ======================================================================= */

	$( () => {
		$( '.s2-hierarchy-block[data-category]' ).each( function () {
			const $node = $( this );
			const title = $node.data( 'category' );
			if ( title ) {
				mw.SemanticSchemasHierarchy.renderInto( $node, title );
			}
		} );
	} );

}( mw, jQuery ) );
