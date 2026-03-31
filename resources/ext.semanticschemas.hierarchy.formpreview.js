/**
 * SemanticSchemas Hierarchy Form Preview
 * =====================================
 * Provides live hierarchy preview for category creation forms.
 *
 * When users create or edit categories via PageForms, this module:
 * - Watches the parent category field for changes
 * - Makes API calls to compute inheritance hierarchy
 * - Displays a tree visualization of parent/child relationships
 * - Shows inherited properties and subobjects
 * - Auto-populates free text field with category membership tags
 *
 * Architecture:
 * - Automatically loads via {{#semanticschemas_load_form_preview:}}
 * - Finds parent field using data-parent-field attribute
 * - Debounces updates to avoid excessive API calls
 * - Renders using jQuery DOM manipulation
 *
 * Requirements:
 * - Container div: <div id="ss-form-hierarchy-preview" data-parent-field="FIELD_NAME">
 * - PageForms tokens/combobox field for parent categories
 * - API endpoint: action=semanticschemas-hierarchy
 *
 * @param {Object} mw
 * @param {jQuery} $
 */

( function ( mw, $ ) {
	'use strict';

	// Configuration
	let updateTimer = null;
	const UPDATE_DELAY = 500; // Debounce delay (ms) after user stops typing
	const DEBUG = false; // Enable for detailed console logging

	/**
	 * Log debug messages (only when DEBUG mode is enabled).
	 *
	 * @param {...*} args Arguments to log
	 */
	/* eslint-disable no-console */
	function debug() {
		if ( DEBUG && window.console && console.log ) {
			const args = Array.prototype.slice.call( arguments );
			args.unshift( '[SemanticSchemas]' );
			console.log.apply( console, args );
		}
	}
	/* eslint-enable no-console */

	/**
	 * Extract parent categories from the form field.
	 *
	 * PageForms uses different field formats:
	 * - tokens input (comma-separated, often with full "Category:Name" format)
	 * - textarea (one per line)
	 * - input field (single value)
	 *
	 * @param {jQuery} $field The parent category field
	 * @return {Array} Array of parent category names
	 */
	function extractParentCategories( $field ) {
		if ( !$field || $field.length === 0 ) {
			return [];
		}

		const value = $field.val();
		const parents = [];

		// Handle different field types
		// Select2 multi-select returns an array
		// Text inputs return a string

		if ( Array.isArray( value ) ) {
			// Select/Select2 field - value is already an array
			value.forEach( ( item ) => {
				let cleaned = ( item || '' ).trim();

				// Remove "Category:" prefix if present
				cleaned = cleaned.replace( /^Category:\s*/i, '' );

				if ( cleaned ) {
					parents.push( cleaned );
				}
			} );
		} else if ( typeof value === 'string' ) {
			// Text input - split by delimiters
			const raw = value.split( /[,\n|;]+/ );

			raw.forEach( ( item ) => {
				let cleaned = item.trim();

				// Remove "Category:" prefix if present
				cleaned = cleaned.replace( /^Category:\s*/i, '' );

				// PageForms might also wrap in brackets or other markup
				cleaned = cleaned.replace( /^\[\[|\]\]$/g, '' );

				// Remove any trailing/leading whitespace again
				cleaned = cleaned.trim();

				if ( cleaned ) {
					parents.push( cleaned );
				}
			} );
		}

		return parents;
	}

	/**
	 * Render a simple tree structure for the preview.
	 *
	 * @param {jQuery} $container Container element
	 * @param {Object} hierarchyData Hierarchy data from API
	 */
	function renderPreviewTree( $container, hierarchyData ) {
		const rootTitle = hierarchyData.rootCategory || null;

		if ( !rootTitle || !hierarchyData.nodes || !hierarchyData.nodes[ rootTitle ] ) {
			$container.empty().append(
				$( '<p>' ).addClass( 'ss-hierarchy-empty' ).text(
					'No parents specified. Add parent categories to see the hierarchy preview.'
				)
			);
			return;
		}

		/**
		 * Recursively build tree node.
		 *
		 * @param {string} title Category title
		 * @param {number} depth Current depth (for styling)
		 * @return {jQuery|null} List item element or null
		 */
		function buildNode( title, depth ) {
			const node = hierarchyData.nodes[ title ];
			if ( !node ) {
				return null;
			}

			const displayName = title.replace( /^Category:/, '' );
			const $li = $( '<li>' ).addClass( 'ss-preview-node' );

			// Mark the virtual (new) category
			if ( title === rootTitle ) {
				$li.addClass( 'ss-preview-node-virtual' );
				$li.append(
					$( '<span>' )
						.addClass( 'ss-preview-node-label' )
						.text( displayName + ' ' )
						.append(
							$( '<span>' ).addClass( 'ss-preview-badge' ).text( '(new)' )
						)
				);
			} else {
				$li.append(
					$( '<span>' )
						.addClass( 'ss-preview-node-label' )
						.text( displayName )
				);
			}

			// If this node has parents, create nested list
			if ( Array.isArray( node.parents ) && node.parents.length > 0 ) {
				const $ul = $( '<ul>' ).addClass( 'ss-preview-tree-nested' );

				node.parents.forEach( ( parentTitle ) => {
					const $childNode = buildNode( parentTitle, depth + 1 );
					if ( $childNode ) {
						$ul.append( $childNode );
					}
				} );

				$li.append( $ul );
			}

			return $li;
		}

		// Build and render the tree
		const $rootList = $( '<ul>' ).addClass( 'ss-preview-tree' );
		const $rootNode = buildNode( rootTitle, 0 );

		if ( $rootNode ) {
			$rootList.append( $rootNode );
		}

		$container.empty().append( $rootList );
	}

	/**
	 * Render inherited properties grouped by type (required/optional).
	 * Reuses the same display logic as the main hierarchy visualization.
	 *
	 * @param {jQuery} $container Container element
	 * @param {Object} hierarchyData Hierarchy data from API
	 */
	function renderPreviewProperties( $container, hierarchyData ) {
		const props = hierarchyData.inheritedProperties || [];

		if ( props.length === 0 ) {
			$container.empty().append(
				$( '<p>' ).addClass( 'ss-hierarchy-empty' ).text(
					'No properties will be inherited.'
				)
			);
			return;
		}

		// Separate required and optional
		const required = [];
		const optional = [];

		props.forEach( ( p ) => {
			const isRequired = ( p.required === 1 || p.required === true );
			if ( isRequired ) {
				required.push( p );
			} else {
				optional.push( p );
			}
		} );

		// Sort alphabetically within each group
		const sortByTitle = function ( a, b ) {
			const titleA = ( a.propertyTitle || '' ).toLowerCase();
			const titleB = ( b.propertyTitle || '' ).toLowerCase();
			return titleA.localeCompare( titleB );
		};
		required.sort( sortByTitle );
		optional.sort( sortByTitle );

		const $propContainer = $( '<div>' ).addClass( 'ss-prop-by-type' );

		// Render required properties
		if ( required.length > 0 ) {
			const $requiredSection = $( '<div>' ).addClass( 'ss-prop-type-section ss-prop-type-required-section' );
			$requiredSection.append(
				$( '<h4>' ).addClass( 'ss-prop-type-heading' ).text( 'Required Properties (' + required.length + ')' )
			);

			const $requiredList = $( '<ul>' ).addClass( 'ss-prop-list ss-prop-list-by-type' );
			required.forEach( ( p ) => {
				const $li = $( '<li>' ).addClass( 'ss-prop-required' );

				const propertyTitle = p.propertyTitle || '';
				if ( propertyTitle ) {
					const propHref = mw.util.getUrl( propertyTitle );
					const propDisplayName = propertyTitle.replace( /^Property:/, '' );
					$li.append(
						$( '<a>' )
							.attr( 'href', propHref )
							.attr( 'title', propertyTitle )
							.text( propDisplayName )
					);

					// Add source category in parentheses
					const sourceTitle = p.sourceCategory || '';
					if ( sourceTitle ) {
						const sourceDisplayName = sourceTitle.replace( /^Category:/, '' );
						const sourceHref = mw.util.getUrl( sourceTitle );
						$li.append(
							' ',
							$( '<span>' ).addClass( 'ss-prop-source-label' ).append(
								'(',
								$( '<a>' )
									.attr( 'href', sourceHref )
									.attr( 'title', sourceTitle )
									.text( sourceDisplayName ),
								')'
							)
						);
					}
				} else {
					$li.text( '(unnamed property)' );
				}

				$requiredList.append( $li );
			} );
			$requiredSection.append( $requiredList );
			$propContainer.append( $requiredSection );
		}

		// Render optional properties
		if ( optional.length > 0 ) {
			const $optionalSection = $( '<div>' ).addClass( 'ss-prop-type-section ss-prop-type-optional-section' );
			$optionalSection.append(
				$( '<h4>' ).addClass( 'ss-prop-type-heading' ).text( 'Optional Properties (' + optional.length + ')' )
			);

			const $optionalList = $( '<ul>' ).addClass( 'ss-prop-list ss-prop-list-by-type' );
			optional.forEach( ( p ) => {
				const $li = $( '<li>' ).addClass( 'ss-prop-optional' );

				const propertyTitle = p.propertyTitle || '';
				if ( propertyTitle ) {
					const propHref = mw.util.getUrl( propertyTitle );
					const propDisplayName = propertyTitle.replace( /^Property:/, '' );
					$li.append(
						$( '<a>' )
							.attr( 'href', propHref )
							.attr( 'title', propertyTitle )
							.text( propDisplayName )
					);

					// Add source category in parentheses
					const sourceTitle = p.sourceCategory || '';
					if ( sourceTitle ) {
						const sourceDisplayName = sourceTitle.replace( /^Category:/, '' );
						const sourceHref = mw.util.getUrl( sourceTitle );
						$li.append(
							' ',
							$( '<span>' ).addClass( 'ss-prop-source-label' ).append(
								'(',
								$( '<a>' )
									.attr( 'href', sourceHref )
									.attr( 'title', sourceTitle )
									.text( sourceDisplayName ),
								')'
							)
						);
					}
				} else {
					$li.text( '(unnamed property)' );
				}

				$optionalList.append( $li );
			} );
			$optionalSection.append( $optionalList );
			$propContainer.append( $optionalSection );
		}

		// Render subobject summary
		const subobjects = hierarchyData.inheritedSubobjects || [];
		const $subobjectSection = $( '<div>' ).addClass( 'ss-prop-type-section' );
		$subobjectSection.append(
			$( '<h4>' ).addClass( 'ss-prop-type-heading' ).text( 'Subobjects (' + subobjects.length + ')' )
		);

		if ( subobjects.length === 0 ) {
			$subobjectSection.append(
				$( '<p>' ).addClass( 'ss-hierarchy-empty' ).text( 'No subobjects defined.' )
			);
		} else {
			const $subobjectList = $( '<ul>' ).addClass( 'ss-prop-list ss-prop-list-by-type' );
			subobjects.forEach( ( entry ) => {
				const $li = $( '<li>' ).addClass( entry.required ? 'ss-prop-required' : 'ss-prop-optional' );
				const subobjectTitle = entry.subobjectTitle || '';
				if ( subobjectTitle ) {
					const href = mw.util.getUrl( subobjectTitle );
					const displayName = subobjectTitle.replace( /^Subobject:/, '' );
					$li.append(
						$( '<a>' )
							.attr( 'href', href )
							.attr( 'title', subobjectTitle )
							.text( displayName )
					);
				} else {
					$li.text( '(unnamed subobject)' );
				}
				$li.append(
					' ',
					$( '<span>' )
						.addClass( 'ss-prop-badge' )
						.text( entry.required ? 'required' : 'optional' )
				);
				$subobjectList.append( $li );
			} );
			$subobjectSection.append( $subobjectList );
		}

		$container.empty().append( $propContainer, $subobjectSection );
	}

	/**
	 * Update the preview based on current form values.
	 *
	 * Makes an API call to compute the inheritance hierarchy for the given
	 * category and its parents, then renders the results.
	 *
	 * @param {string} categoryName Name of the virtual category being created
	 * @param {Array} parentCategories Array of parent category names
	 */
	function updatePreview( categoryName, parentCategories ) {
		debug( 'updatePreview called:', categoryName, parentCategories );

		const $previewContainer = $( '#ss-form-hierarchy-preview' );

		if ( $previewContainer.length === 0 ) {
			debug( 'Preview container not found' );
			return;
		}

		// If no parents, show empty state
		if ( parentCategories.length === 0 ) {
			debug( 'No parents selected, showing empty state' );
			$previewContainer.html(
				'<p class="ss-hierarchy-empty">Add parent categories to see what this category will inherit.</p>'
			);
			return;
		}

		// Show loading state
		debug( 'Making API call for hierarchy data' );
		$previewContainer.html( '<p class="ss-hierarchy-loading">Loading preview...</p>' );

		// Make API call
		const api = new mw.Api();
		const apiParams = {
			action: 'semanticschemas-hierarchy',
			category: categoryName,
			parents: parentCategories.join( '|' ),
			format: 'json'
		};

		api.get( apiParams ).done( ( response ) => {
			debug( 'API response received:', response );

			const data = response[ 'semanticschemas-hierarchy' ];
			if ( !data ) {
				// eslint-disable-next-line no-console
				console.error( '[SemanticSchemas] No data in API response' );
				$previewContainer.html( '<p class="ss-hierarchy-error">Error loading preview.</p>' );
				return;
			}

			// Build preview HTML
			const $preview = $( '<div>' ).addClass( 'ss-preview-wrapper' );

			// Tree section
			const $treeSection = $( '<div>' ).addClass( 'ss-preview-section' );
			$treeSection.append( $( '<h4>' ).text( 'Inheritance Hierarchy' ) );
			const $treeContainer = $( '<div>' ).addClass( 'ss-preview-tree-container' );
			renderPreviewTree( $treeContainer, data );
			$treeSection.append( $treeContainer );

			// Properties section
			const $propsSection = $( '<div>' ).addClass( 'ss-preview-section' );
			$propsSection.append( $( '<h4>' ).text( 'Inherited Properties' ) );
			const $propsContainer = $( '<div>' ).addClass( 'ss-preview-props-container' );
			renderPreviewProperties( $propsContainer, data );
			$propsSection.append( $propsContainer );

			$preview.append( $treeSection, $propsSection );
			$previewContainer.empty().append( $preview );

			debug( 'Preview rendered successfully' );

		} ).fail( ( error ) => {
			// eslint-disable-next-line no-console
			console.error( '[SemanticSchemas] API call failed:', error );
			$previewContainer.html( '<p class="ss-hierarchy-error">Error loading preview. Please check parent category names.</p>' );
		} );
	}

	/**
	 * Initialize form preview functionality.
	 *
	 * Finds the preview container and parent category field, sets up event
	 * listeners, and performs initial rendering if parents are pre-selected.
	 *
	 * Initialization is skipped if:
	 * - Preview container is not found
	 * - Not on a PageForms edit page
	 * - Parent category field cannot be detected
	 */
	function init() {
		debug( 'Initializing form preview, URL:', window.location.href );

		// Check if we're on a form page with the preview container
		const $previewContainer = $( '#ss-form-hierarchy-preview' );
		if ( $previewContainer.length === 0 ) {
			debug( 'Preview container not found, skipping initialization' );
			return;
		}

		debug( 'Preview container found' );

		// Only initialize if we're on a PageForms edit page (not the form input page)
		// PageForms adds specific markers to edit pages
		const isPageFormsEditPage = (
			$( '.pfForm' ).length > 0 || // PageForms form container
			$( '#pfForm' ).length > 0 || // Alternative form ID
			$( 'form input[name="wpSave"]' ).length > 0 // Edit page save button
		);

		if ( !isPageFormsEditPage ) {
			debug( 'Not on PageForms edit page, skipping initialization' );
			return;
		}

		// Determine category name from URL or form field
		let categoryName = 'NewCategory';
		const matches = window.location.pathname.match( /Category:([^/]+)/ );
		if ( matches && matches[ 1 ] ) {
			categoryName = decodeURIComponent( matches[ 1 ] );
		}

		const $categoryNameField = $( 'input[name="page_name"], input[name="category_name"], input[name="Category"]' ).first();
		if ( $categoryNameField.length > 0 && $categoryNameField.val() ) {
			categoryName = $categoryNameField.val();
		}

		// Find parent category field using data-parent-field attribute
		let $parentField = null;
		const parentFieldId = $previewContainer.data( 'parent-field' );

		debug( 'Looking for parent field:', parentFieldId );
		debug( 'Available select fields:', $( 'select' ).map( function () {
			return $( this ).attr( 'name' );
		} ).get() );

		if ( parentFieldId ) {
			// PageForms tokens creates: <select name="Category[field_name][]" class="pfTokens">
			// Try with brackets first (standard PageForms format)
			const selector1 = 'select[name*="[' + parentFieldId + ']"]';
			$parentField = $( selector1 ).first();
			debug( 'Tried selector:', selector1, '- Found:', $parentField.length );

			// If not found, try without brackets (edge case)
			if ( $parentField.length === 0 ) {
				const selector2 = 'select[name="' + parentFieldId + '"]';
				$parentField = $( selector2 ).first();
				debug( 'Tried selector:', selector2, '- Found:', $parentField.length );
			}
		}

		// Fallback: auto-detect any field with "parent" in the name
		if ( !$parentField || $parentField.length === 0 ) {
			$parentField = $( 'select[name*="parent"]' ).first();
			debug( 'Fallback detection - Found:', $parentField.length );
		}

		// Cannot proceed without parent field
		if ( !$parentField || $parentField.length === 0 ) {
			let errorMsg = 'Could not find parent category field for preview.';
			if ( parentFieldId ) {
				errorMsg += ' Looking for field: ' + parentFieldId;
			}
			$previewContainer.html( '<p class="ss-hierarchy-empty">' + errorMsg + '</p>' );
			// eslint-disable-next-line no-console
			console.error( '[SemanticSchemas] Parent field not found, preview disabled' );
			return;
		}

		debug( 'Initialized for category:', categoryName, '- Parent field:', $parentField.attr( 'name' ) );

		// Find the free text field for automatic category tag injection
		const $freeTextField = $( 'input[name="pf_free_text"], textarea[name="pf_free_text"]' ).first();
		debug( 'Free text field found:', $freeTextField.length );

		/**
		 * Update free text field with category membership tags.
		 *
		 * Automatically populates the free text field with [[Category:...]] tags
		 * based on the selected parent categories, ensuring pages are properly
		 * categorized when saved.
		 */
		function updateFreeText() {
			if ( $freeTextField.length === 0 ) {
				debug( 'No free text field, skipping category tag update' );
				return;
			}

			const parents = extractParentCategories( $parentField );
			if ( parents.length === 0 ) {
				$freeTextField.val( '' );
				debug( 'Cleared free text field (no parents)' );
				return;
			}

			// Build category links - one per line for readability
			const categoryLinks = parents.map( ( parent ) => {
				const cleanName = parent.replace( /^Category:\s*/i, '' );
				return '[[Category:' + cleanName + ']]';
			} );

			const linkText = categoryLinks.join( '\n' );
			$freeTextField.val( linkText );
			debug( 'Updated free text field:', linkText );
		}

		// Watch for changes to the parent field (debounced)
		$parentField.on( 'change', () => {
			debug( 'Parent field changed:', $parentField.val() );

			// Clear any pending update
			if ( updateTimer !== null ) {
				clearTimeout( updateTimer );
			}

			// Schedule debounced update
			updateTimer = setTimeout( () => {
				let currentCategory = categoryName;
				if ( $categoryNameField.length > 0 && $categoryNameField.val() ) {
					currentCategory = $categoryNameField.val();
				}

				const parents = extractParentCategories( $parentField );
				debug( 'Updating preview - Category:', currentCategory, 'Parents:', parents );

				// Update both the preview and the free text field
				updatePreview( currentCategory, parents );
				updateFreeText();
			}, UPDATE_DELAY );
		} );

		// Also watch the category name field if it exists (for page renames)
		if ( $categoryNameField.length > 0 ) {
			$categoryNameField.on( 'input change keyup', () => {
				if ( updateTimer !== null ) {
					clearTimeout( updateTimer );
				}

				updateTimer = setTimeout( () => {
					const currentCategory = $categoryNameField.val() || categoryName;
					const parents = extractParentCategories( $parentField );
					updatePreview( currentCategory, parents );
				}, UPDATE_DELAY );
			} );
		}

		// Perform initial render if parent categories are already selected
		const initialParents = extractParentCategories( $parentField );
		if ( initialParents.length > 0 ) {
			debug( 'Initial parents found, rendering preview' );
			updatePreview( categoryName, initialParents );
			updateFreeText();
		} else {
			// Show empty state
			$previewContainer.html( '<p class="ss-hierarchy-empty">Add parent categories to see what this category will inherit.</p>' );
		}
	}

	// Initialize when DOM is ready
	$( () => {
		debug( 'DOM ready, initializing form preview' );
		init();
	} );

}( mw, jQuery ) );
