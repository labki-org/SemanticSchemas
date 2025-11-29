/**
 * StructureSync Hierarchy Form Preview
 * ======================================
 * Dynamic hierarchy preview for Form:Category (category creation forms).
 * 
 * Watches the parent category field and updates the hierarchy preview as the user types.
 * 
 * Usage:
 * 1. Add this module to your Form:Category page via ResourceLoader
 * 2. Add a container div with id="ss-form-hierarchy-preview"
 * 3. The module will auto-detect parent category fields and update the preview
 */

(function (mw, $) {
	'use strict';

	var updateTimer = null;
	var UPDATE_DELAY = 500; // milliseconds to wait after typing stops

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
	function extractParentCategories($field) {
		if (!$field || $field.length === 0) {
			return [];
		}
		
		var value = $field.val();
		var parents = [];

		// Handle different field types
		// Select2 multi-select returns an array
		// Text inputs return a string
		
		if (Array.isArray(value)) {
			// Select/Select2 field - value is already an array
			value.forEach(function (item) {
				var cleaned = (item || '').trim();
				
				// Remove "Category:" prefix if present
				cleaned = cleaned.replace(/^Category:\s*/i, '');
				
				if (cleaned) {
					parents.push(cleaned);
				}
			});
		} else if (typeof value === 'string') {
			// Text input - split by delimiters
			var raw = value.split(/[,\n|;]+/);
			
			raw.forEach(function (item) {
				var cleaned = item.trim();
				
				// Remove "Category:" prefix if present
				cleaned = cleaned.replace(/^Category:\s*/i, '');
				
				// PageForms might also wrap in brackets or other markup
				cleaned = cleaned.replace(/^\[\[|\]\]$/g, '');
				
				// Remove any trailing/leading whitespace again
				cleaned = cleaned.trim();
				
				if (cleaned) {
					parents.push(cleaned);
				}
			});
		}

		return parents;
	}

	/**
	 * Render a simple tree structure for the preview.
	 * 
	 * @param {jQuery} $container Container element
	 * @param {Object} hierarchyData Hierarchy data from API
	 */
	function renderPreviewTree($container, hierarchyData) {
		var rootTitle = hierarchyData.rootCategory || null;
		
		if (!rootTitle || !hierarchyData.nodes || !hierarchyData.nodes[rootTitle]) {
			$container.empty().append(
				$('<p>').addClass('ss-hierarchy-empty').text(
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
		function buildNode(title, depth) {
			var node = hierarchyData.nodes[title];
			if (!node) {
				return null;
			}

			var displayName = title.replace(/^Category:/, '');
			var $li = $('<li>').addClass('ss-preview-node');
			
			// Mark the virtual (new) category
			if (title === rootTitle) {
				$li.addClass('ss-preview-node-virtual');
				$li.append(
					$('<span>')
						.addClass('ss-preview-node-label')
						.text(displayName + ' ')
						.append(
							$('<span>').addClass('ss-preview-badge').text('(new)')
						)
				);
			} else {
				$li.append(
					$('<span>')
						.addClass('ss-preview-node-label')
						.text(displayName)
				);
			}

			// If this node has parents, create nested list
			if (Array.isArray(node.parents) && node.parents.length > 0) {
				var $ul = $('<ul>').addClass('ss-preview-tree-nested');
				
				node.parents.forEach(function (parentTitle) {
					var $childNode = buildNode(parentTitle, depth + 1);
					if ($childNode) {
						$ul.append($childNode);
					}
				});
				
				$li.append($ul);
			}

			return $li;
		}

		// Build and render the tree
		var $rootList = $('<ul>').addClass('ss-preview-tree');
		var $rootNode = buildNode(rootTitle, 0);
		
		if ($rootNode) {
			$rootList.append($rootNode);
		}

		$container.empty().append($rootList);
	}

	/**
	 * Render inherited properties grouped by type (required/optional).
	 * Reuses the same display logic as the main hierarchy visualization.
	 * 
	 * @param {jQuery} $container Container element
	 * @param {Object} hierarchyData Hierarchy data from API
	 */
	function renderPreviewProperties($container, hierarchyData) {
		var props = hierarchyData.inheritedProperties || [];
		
		if (props.length === 0) {
			$container.empty().append(
				$('<p>').addClass('ss-hierarchy-empty').text(
					'No properties will be inherited.'
				)
			);
			return;
		}

		// Separate required and optional
		var required = [];
		var optional = [];
		
		props.forEach(function (p) {
			var isRequired = (p.required === 1 || p.required === true);
			if (isRequired) {
				required.push(p);
			} else {
				optional.push(p);
			}
		});

		// Sort alphabetically within each group
		var sortByTitle = function (a, b) {
			var titleA = (a.propertyTitle || '').toLowerCase();
			var titleB = (b.propertyTitle || '').toLowerCase();
			return titleA.localeCompare(titleB);
		};
		required.sort(sortByTitle);
		optional.sort(sortByTitle);

		var $propContainer = $('<div>').addClass('ss-prop-by-type');

		// Render required properties
		if (required.length > 0) {
			var $requiredSection = $('<div>').addClass('ss-prop-type-section ss-prop-type-required-section');
			$requiredSection.append(
				$('<h4>').addClass('ss-prop-type-heading').text('Required Properties (' + required.length + ')')
			);
			
			var $requiredList = $('<ul>').addClass('ss-prop-list ss-prop-list-by-type');
			required.forEach(function (p) {
				var $li = $('<li>').addClass('ss-prop-required');
				
				var propertyTitle = p.propertyTitle || '';
				if (propertyTitle) {
					var propHref = mw.util.getUrl(propertyTitle);
					var propDisplayName = propertyTitle.replace(/^Property:/, '');
					$li.append(
						$('<a>')
							.attr('href', propHref)
							.attr('title', propertyTitle)
							.text(propDisplayName)
					);
					
					// Add source category in parentheses
					var sourceTitle = p.sourceCategory || '';
					if (sourceTitle) {
						var sourceDisplayName = sourceTitle.replace(/^Category:/, '');
						var sourceHref = mw.util.getUrl(sourceTitle);
						$li.append(
							' ',
							$('<span>').addClass('ss-prop-source-label').append(
								'(',
								$('<a>')
									.attr('href', sourceHref)
									.attr('title', sourceTitle)
									.text(sourceDisplayName),
								')'
							)
						);
					}
				} else {
					$li.text('(unnamed property)');
				}
				
				$requiredList.append($li);
			});
			$requiredSection.append($requiredList);
			$propContainer.append($requiredSection);
		}

		// Render optional properties
		if (optional.length > 0) {
			var $optionalSection = $('<div>').addClass('ss-prop-type-section ss-prop-type-optional-section');
			$optionalSection.append(
				$('<h4>').addClass('ss-prop-type-heading').text('Optional Properties (' + optional.length + ')')
			);
			
			var $optionalList = $('<ul>').addClass('ss-prop-list ss-prop-list-by-type');
			optional.forEach(function (p) {
				var $li = $('<li>').addClass('ss-prop-optional');
				
				var propertyTitle = p.propertyTitle || '';
				if (propertyTitle) {
					var propHref = mw.util.getUrl(propertyTitle);
					var propDisplayName = propertyTitle.replace(/^Property:/, '');
					$li.append(
						$('<a>')
							.attr('href', propHref)
							.attr('title', propertyTitle)
							.text(propDisplayName)
					);
					
					// Add source category in parentheses
					var sourceTitle = p.sourceCategory || '';
					if (sourceTitle) {
						var sourceDisplayName = sourceTitle.replace(/^Category:/, '');
						var sourceHref = mw.util.getUrl(sourceTitle);
						$li.append(
							' ',
							$('<span>').addClass('ss-prop-source-label').append(
								'(',
								$('<a>')
									.attr('href', sourceHref)
									.attr('title', sourceTitle)
									.text(sourceDisplayName),
								')'
							)
						);
					}
				} else {
					$li.text('(unnamed property)');
				}
				
				$optionalList.append($li);
			});
			$optionalSection.append($optionalList);
			$propContainer.append($optionalSection);
		}

		$container.empty().append($propContainer);
	}

	/**
	 * Update the preview based on current form values.
	 * 
	 * @param {string} categoryName Name of the virtual category being created
	 * @param {Array} parentCategories Array of parent category names
	 */
	function updatePreview(categoryName, parentCategories) {
		var $previewContainer = $('#ss-form-hierarchy-preview');
		
		if ($previewContainer.length === 0) {
			return;
		}

		// If no parents, show empty state
		if (parentCategories.length === 0) {
			$previewContainer.html(
				'<p class="ss-hierarchy-empty">Add parent categories to see what this category will inherit.</p>'
			);
			return;
		}

		// Show loading state
		$previewContainer.html('<p class="ss-hierarchy-loading">Loading preview...</p>');

		// Make API call
		var api = new mw.Api();
		api.get({
			action: 'structuresync-hierarchy',
			category: categoryName,
			parents: parentCategories.join('|'),
			format: 'json'
		}).done(function (response) {
			var data = response['structuresync-hierarchy'];
			if (!data) {
				$previewContainer.html('<p class="ss-hierarchy-error">Error loading preview.</p>');
				return;
			}

			// Build preview HTML
			var $preview = $('<div>').addClass('ss-preview-wrapper');
			
			// Tree section
			var $treeSection = $('<div>').addClass('ss-preview-section');
			$treeSection.append($('<h4>').text('Inheritance Hierarchy'));
			var $treeContainer = $('<div>').addClass('ss-preview-tree-container');
			renderPreviewTree($treeContainer, data);
			$treeSection.append($treeContainer);
			
			// Properties section
			var $propsSection = $('<div>').addClass('ss-preview-section');
			$propsSection.append($('<h4>').text('Inherited Properties'));
			var $propsContainer = $('<div>').addClass('ss-preview-props-container');
			renderPreviewProperties($propsContainer, data);
			$propsSection.append($propsContainer);
			
			$preview.append($treeSection, $propsSection);
			$previewContainer.empty().append($preview);

		}).fail(function () {
			$previewContainer.html('<p class="ss-hierarchy-error">Error loading preview. Please check parent category names.</p>');
		});
	}

	/**
	 * Initialize form preview functionality.
	 */
	function init() {
		// Check if we're on a form page with the preview container
		var $previewContainer = $('#ss-form-hierarchy-preview');
		if ($previewContainer.length === 0) {
			return;
		}
		
		// Only initialize if we're on a PageForms edit page (not the form input page)
		// PageForms adds specific markers to edit pages
		var isPageFormsEditPage = (
			$('.pfForm').length > 0 || // PageForms form container
			$('#pfForm').length > 0 ||  // Alternative form ID
			$('form input[name="wpSave"]').length > 0 // Edit page save button
		);
		
		if (!isPageFormsEditPage) {
			// Not on a PageForms edit page, don't initialize
			if (window.console && console.log) {
				console.log('[StructureSync] Not on PageForms edit page, skipping initialization');
			}
			return;
		}

		// Find the category name field (the page being created)
		// Check URL for the category name in Special:FormEdit pages
		var categoryName = 'NewCategory';
		var matches = window.location.pathname.match(/Category:([^\/]+)/);
		if (matches && matches[1]) {
			categoryName = decodeURIComponent(matches[1]);
		}
		
		// Also try to find it in a field
		var $categoryNameField = $('input[name="page_name"], input[name="category_name"], input[name="Category"]').first();
		if ($categoryNameField.length > 0 && $categoryNameField.val()) {
			categoryName = $categoryNameField.val();
		}
		
		// Find parent category field
		// PageForms tokens fields use Select2 multi-select for the tokens input type
		var $parentField = null;
		
		// First, try to use the data attribute if specified
		var parentFieldId = $previewContainer.data('parent-field');
		
		if (parentFieldId) {
			// Try to find the select element with the field name
			// PageForms tokens creates: <select name="Category[field_name][]" class="pfTokens">
			$parentField = $('select[name*="[' + parentFieldId + ']"]').first();
			
			// If not found, try without brackets
			if ($parentField.length === 0) {
				$parentField = $('select[name="' + parentFieldId + '"]').first();
			}
		}
		
		// Auto-detect if still not found
		if (!$parentField || $parentField.length === 0) {
			// Look for any select with "parent" in the name (likely a tokens field)
			$parentField = $('select[name*="parent_category"]').first();
		}

		if (!$parentField || $parentField.length === 0) {
			$previewContainer.html('<p class="ss-hierarchy-empty">Could not find parent category field for preview.</p>');
			return;
		}

		// Debug: Log initialization
		if (window.console && console.log) {
			console.log('[StructureSync] Form preview initialized for category:', categoryName);
		}

		// Watch for changes to the parent field
		// For Select2 fields, we listen to 'change' events
		$parentField.on('change', function () {
			// Clear any pending update
			if (updateTimer !== null) {
				clearTimeout(updateTimer);
			}

			// Schedule update
			updateTimer = setTimeout(function () {
				var currentCategory = categoryName;
				if ($categoryNameField.length > 0 && $categoryNameField.val()) {
					currentCategory = $categoryNameField.val();
				}
				
				var parents = extractParentCategories($parentField);
				updatePreview(currentCategory, parents);
			}, UPDATE_DELAY);
		});

		// Also watch the category name field if present
		if ($categoryNameField.length > 0) {
			$categoryNameField.on('input change keyup', function () {
				if (updateTimer !== null) {
					clearTimeout(updateTimer);
				}

				updateTimer = setTimeout(function () {
					var currentCategory = $categoryNameField.val() || categoryName;
					var parents = extractParentCategories($parentField);
					updatePreview(currentCategory, parents);
				}, UPDATE_DELAY);
			});
		}
		
		// Do an initial render if there are already parent categories selected
		var initialParents = extractParentCategories($parentField);
		if (initialParents.length > 0) {
			updatePreview(categoryName, initialParents);
		} else {
			// Show empty state
			$previewContainer.html('<p class="ss-hierarchy-empty">Add parent categories to see what this category will inherit.</p>');
		}
	}

	// Initialize when DOM is ready
	$(function () {
		init();
	});

}(mediaWiki, jQuery));

