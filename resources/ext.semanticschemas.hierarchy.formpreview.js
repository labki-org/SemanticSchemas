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
 * - Container div: <div id="s2-form-hierarchy-preview" data-parent-field="FIELD_NAME">
 * - PageForms tokens/combobox field for parent categories
 * - API endpoint: action=semanticschemas-hierarchy
 */

(function (mw, $) {
	'use strict';

	// Configuration
	var updateTimer = null;
	var UPDATE_DELAY = 500; // Debounce delay (ms) after user stops typing
	var DEBUG = false; // Enable for detailed console logging

	/**
	 * Log debug messages (only when DEBUG mode is enabled).
	 * 
	 * @param {...*} args Arguments to log
	 */
	function debug() {
		if (DEBUG && window.console && console.log) {
			var args = Array.prototype.slice.call(arguments);
			args.unshift('[SemanticSchemas]');
			console.log.apply(console, args);
		}
	}

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
				$('<p>').addClass('s2-hierarchy-empty').text(
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
			var $li = $('<li>').addClass('s2-preview-node');

			// Mark the virtual (new) category
			if (title === rootTitle) {
				$li.addClass('s2-preview-node-virtual');
				$li.append(
					$('<span>')
						.addClass('s2-preview-node-label')
						.text(displayName + ' ')
						.append(
							$('<span>').addClass('s2-preview-badge').text('(new)')
						)
				);
			} else {
				$li.append(
					$('<span>')
						.addClass('s2-preview-node-label')
						.text(displayName)
				);
			}

			// If this node has parents, create nested list
			if (Array.isArray(node.parents) && node.parents.length > 0) {
				var $ul = $('<ul>').addClass('s2-preview-tree-nested');

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
		var $rootList = $('<ul>').addClass('s2-preview-tree');
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
				$('<p>').addClass('s2-hierarchy-empty').text(
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

		var $propContainer = $('<div>').addClass('s2-prop-by-type');

		// Render required properties
		if (required.length > 0) {
			var $requiredSection = $('<div>').addClass('s2-prop-type-section s2-prop-type-required-section');
			$requiredSection.append(
				$('<h4>').addClass('s2-prop-type-heading').text('Required Properties (' + required.length + ')')
			);

			var $requiredList = $('<ul>').addClass('s2-prop-list s2-prop-list-by-type');
			required.forEach(function (p) {
				var $li = $('<li>').addClass('s2-prop-required');

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
							$('<span>').addClass('s2-prop-source-label').append(
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
			var $optionalSection = $('<div>').addClass('s2-prop-type-section s2-prop-type-optional-section');
			$optionalSection.append(
				$('<h4>').addClass('s2-prop-type-heading').text('Optional Properties (' + optional.length + ')')
			);

			var $optionalList = $('<ul>').addClass('s2-prop-list s2-prop-list-by-type');
			optional.forEach(function (p) {
				var $li = $('<li>').addClass('s2-prop-optional');

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
							$('<span>').addClass('s2-prop-source-label').append(
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

		// Render subobject summary
		var subobjects = hierarchyData.inheritedSubobjects || [];
		var $subobjectSection = $('<div>').addClass('s2-prop-type-section');
		$subobjectSection.append(
			$('<h4>').addClass('s2-prop-type-heading').text('Subobjects (' + subobjects.length + ')')
		);

		if (subobjects.length === 0) {
			$subobjectSection.append(
				$('<p>').addClass('s2-hierarchy-empty').text('No subobjects defined.')
			);
		} else {
			var $subobjectList = $('<ul>').addClass('s2-prop-list s2-prop-list-by-type');
			subobjects.forEach(function (entry) {
				var $li = $('<li>').addClass(entry.required ? 's2-prop-required' : 's2-prop-optional');
				var subobjectTitle = entry.subobjectTitle || '';
				if (subobjectTitle) {
					var href = mw.util.getUrl(subobjectTitle);
					var displayName = subobjectTitle.replace(/^Subobject:/, '');
					$li.append(
						$('<a>')
							.attr('href', href)
							.attr('title', subobjectTitle)
							.text(displayName)
					);
				} else {
					$li.text('(unnamed subobject)');
				}
				$li.append(
					' ',
					$('<span>')
						.addClass('s2-prop-badge')
						.text(entry.required ? 'required' : 'optional')
				);
				$subobjectList.append($li);
			});
			$subobjectSection.append($subobjectList);
		}

		$container.empty().append($propContainer, $subobjectSection);
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
	function updatePreview(categoryName, parentCategories) {
		debug('updatePreview called:', categoryName, parentCategories);

		var $previewContainer = $('#s2-form-hierarchy-preview');

		if ($previewContainer.length === 0) {
			debug('Preview container not found');
			return;
		}

		// If no parents, show empty state
		if (parentCategories.length === 0) {
			debug('No parents selected, showing empty state');
			$previewContainer.html(
				'<p class="s2-hierarchy-empty">Add parent categories to see what this category will inherit.</p>'
			);
			return;
		}

		// Show loading state
		debug('Making API call for hierarchy data');
		$previewContainer.html('<p class="s2-hierarchy-loading">Loading preview...</p>');

		// Make API call
		var api = new mw.Api();
		var apiParams = {
			action: 'semanticschemas-hierarchy',
			category: categoryName,
			parents: parentCategories.join('|'),
			format: 'json'
		};

		api.get(apiParams).done(function (response) {
			debug('API response received:', response);

			var data = response['semanticschemas-hierarchy'];
			if (!data) {
				console.error('[SemanticSchemas] No data in API response');
				$previewContainer.html('<p class="s2-hierarchy-error">Error loading preview.</p>');
				return;
			}

			// Build preview HTML
			var $preview = $('<div>').addClass('s2-preview-wrapper');

			// Tree section
			var $treeSection = $('<div>').addClass('s2-preview-section');
			$treeSection.append($('<h4>').text('Inheritance Hierarchy'));
			var $treeContainer = $('<div>').addClass('s2-preview-tree-container');
			renderPreviewTree($treeContainer, data);
			$treeSection.append($treeContainer);

			// Properties section
			var $propsSection = $('<div>').addClass('s2-preview-section');
			$propsSection.append($('<h4>').text('Inherited Properties'));
			var $propsContainer = $('<div>').addClass('s2-preview-props-container');
			renderPreviewProperties($propsContainer, data);
			$propsSection.append($propsContainer);

			$preview.append($treeSection, $propsSection);
			$previewContainer.empty().append($preview);

			debug('Preview rendered successfully');

		}).fail(function (error) {
			console.error('[SemanticSchemas] API call failed:', error);
			$previewContainer.html('<p class="s2-hierarchy-error">Error loading preview. Please check parent category names.</p>');
		});
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
		debug('Initializing form preview, URL:', window.location.href);

		// Check if we're on a form page with the preview container
		var $previewContainer = $('#s2-form-hierarchy-preview');
		if ($previewContainer.length === 0) {
			debug('Preview container not found, skipping initialization');
			return;
		}

		debug('Preview container found');

		// Only initialize if we're on a PageForms edit page (not the form input page)
		// PageForms adds specific markers to edit pages
		var isPageFormsEditPage = (
			$('.pfForm').length > 0 || // PageForms form container
			$('#pfForm').length > 0 ||  // Alternative form ID
			$('form input[name="wpSave"]').length > 0 // Edit page save button
		);

		if (!isPageFormsEditPage) {
			debug('Not on PageForms edit page, skipping initialization');
			return;
		}

		// Determine category name from URL or form field
		var categoryName = 'NewCategory';
		var matches = window.location.pathname.match(/Category:([^\/]+)/);
		if (matches && matches[1]) {
			categoryName = decodeURIComponent(matches[1]);
		}

		var $categoryNameField = $('input[name="page_name"], input[name="category_name"], input[name="Category"]').first();
		if ($categoryNameField.length > 0 && $categoryNameField.val()) {
			categoryName = $categoryNameField.val();
		}

		// Find parent category field using data-parent-field attribute
		var $parentField = null;
		var parentFieldId = $previewContainer.data('parent-field');

		debug('Looking for parent field:', parentFieldId);
		debug('Available select fields:', $('select').map(function () { return $(this).attr('name'); }).get());

		if (parentFieldId) {
			// PageForms tokens creates: <select name="Category[field_name][]" class="pfTokens">
			// Try with brackets first (standard PageForms format)
			var selector1 = 'select[name*="[' + parentFieldId + ']"]';
			$parentField = $(selector1).first();
			debug('Tried selector:', selector1, '- Found:', $parentField.length);

			// If not found, try without brackets (edge case)
			if ($parentField.length === 0) {
				var selector2 = 'select[name="' + parentFieldId + '"]';
				$parentField = $(selector2).first();
				debug('Tried selector:', selector2, '- Found:', $parentField.length);
			}
		}

		// Fallback: auto-detect any field with "parent" in the name
		if (!$parentField || $parentField.length === 0) {
			$parentField = $('select[name*="parent"]').first();
			debug('Fallback detection - Found:', $parentField.length);
		}

		// Cannot proceed without parent field
		if (!$parentField || $parentField.length === 0) {
			var errorMsg = 'Could not find parent category field for preview.';
			if (parentFieldId) {
				errorMsg += ' Looking for field: ' + parentFieldId;
			}
			$previewContainer.html('<p class="s2-hierarchy-empty">' + errorMsg + '</p>');
			console.error('[SemanticSchemas] Parent field not found, preview disabled');
			return;
		}

		debug('Initialized for category:', categoryName, '- Parent field:', $parentField.attr('name'));

		// Find the free text field for automatic category tag injection
		var $freeTextField = $('input[name="pf_free_text"], textarea[name="pf_free_text"]').first();
		debug('Free text field found:', $freeTextField.length);

		/**
		 * Update free text field with category membership tags.
		 * 
		 * Automatically populates the free text field with [[Category:...]] tags
		 * based on the selected parent categories, ensuring pages are properly
		 * categorized when saved.
		 */
		function updateFreeText() {
			if ($freeTextField.length === 0) {
				debug('No free text field, skipping category tag update');
				return;
			}

			var parents = extractParentCategories($parentField);
			if (parents.length === 0) {
				$freeTextField.val('');
				debug('Cleared free text field (no parents)');
				return;
			}

			// Build category links - one per line for readability
			var categoryLinks = parents.map(function (parent) {
				var cleanName = parent.replace(/^Category:\s*/i, '');
				return '[[Category:' + cleanName + ']]';
			});

			var linkText = categoryLinks.join('\n');
			$freeTextField.val(linkText);
			debug('Updated free text field:', linkText);
		}

		// Watch for changes to the parent field (debounced)
		$parentField.on('change', function () {
			debug('Parent field changed:', $parentField.val());

			// Clear any pending update
			if (updateTimer !== null) {
				clearTimeout(updateTimer);
			}

			// Schedule debounced update
			updateTimer = setTimeout(function () {
				var currentCategory = categoryName;
				if ($categoryNameField.length > 0 && $categoryNameField.val()) {
					currentCategory = $categoryNameField.val();
				}

				var parents = extractParentCategories($parentField);
				debug('Updating preview - Category:', currentCategory, 'Parents:', parents);

				// Update both the preview and the free text field
				updatePreview(currentCategory, parents);
				updateFreeText();
			}, UPDATE_DELAY);
		});

		// Also watch the category name field if it exists (for page renames)
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

		// Perform initial render if parent categories are already selected
		var initialParents = extractParentCategories($parentField);
		if (initialParents.length > 0) {
			debug('Initial parents found, rendering preview');
			updatePreview(categoryName, initialParents);
			updateFreeText();
		} else {
			// Show empty state
			$previewContainer.html('<p class="s2-hierarchy-empty">Add parent categories to see what this category will inherit.</p>');
		}
	}

	// Initialize when DOM is ready
	$(function () {
		debug('DOM ready, initializing form preview');
		init();
	});

}(mediaWiki, jQuery));

