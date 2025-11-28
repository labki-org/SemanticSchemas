/**
 * StructureSync Hierarchy Visualization
 * --------------------------------------
 * Frontend module for rendering category hierarchy trees and inherited properties.
 * 
 * Usage:
 * - Special:StructureSync/hierarchy tab
 * - Category pages (via parser function)
 * - PageForms preview
 * 
 * API:
 * mw.StructureSyncHierarchy.renderInto(container, categoryTitle)
 */
(function (mw, $) {
	'use strict';

	/**
	 * Render the hierarchy tree as nested lists.
	 * 
	 * @param {jQuery} $container Container element
	 * @param {Object} hierarchyData Hierarchy data from API
	 */
	function renderHierarchyTree($container, hierarchyData) {
		var rootTitle = hierarchyData.rootCategory || null;
		
		if (!rootTitle || !hierarchyData.nodes || !hierarchyData.nodes[rootTitle]) {
			$container.empty().append(
				$('<p>').addClass('ss-hierarchy-empty').text(
					mw.msg('structuresync-hierarchy-no-data')
				)
			);
			return;
		}

		/**
		 * Create a link to a category page.
		 * 
		 * @param {string} title Full category title with "Category:" prefix
		 * @return {jQuery} Link element
		 */
		function makeCategoryLink(title) {
			var href = mw.util.getUrl(title);
			var displayName = title.replace(/^Category:/, '');
			return $('<a>')
				.attr('href', href)
				.attr('title', title)
				.text(displayName);
		}

		/**
		 * Recursively build tree node with collapsible functionality.
		 * 
		 * @param {string} title Category title
		 * @return {jQuery|null} List item element or null
		 */
		function buildNode(title) {
			var node = hierarchyData.nodes[title];
			if (!node) {
				return null;
			}

			var $li = $('<li>');
			var $content = $('<span>').addClass('ss-hierarchy-node-content');

			// If this node has parents, add collapse/expand toggle
			if (Array.isArray(node.parents) && node.parents.length > 0) {
				var $toggle = $('<span>')
					.addClass('ss-hierarchy-toggle')
					.attr('role', 'button')
					.attr('tabindex', '0')
					.attr('aria-expanded', 'true')
					.text('▼');
				
				$content.append($toggle);
				$li.addClass('ss-hierarchy-has-children');
			}

			// Add the category link
			$content.append(' ', makeCategoryLink(title));
			$li.append($content);

			// If this node has parents, create nested list
			if (Array.isArray(node.parents) && node.parents.length > 0) {
				var $ul = $('<ul>').addClass('ss-hierarchy-tree-nested');
				
				node.parents.forEach(function (parentTitle) {
					var $childNode = buildNode(parentTitle);
					if ($childNode) {
						$ul.append($childNode);
					}
				});
				
				$li.append($ul);
			}

			return $li;
		}

		// Build and render the tree
		var $rootList = $('<ul>').addClass('ss-hierarchy-tree');
		var $rootNode = buildNode(rootTitle);
		
		if ($rootNode) {
			$rootList.append($rootNode);
		}

		$container.empty().append($rootList);

		// Add click handlers for collapsible functionality
		$container.on('click', '.ss-hierarchy-toggle', function (e) {
			e.preventDefault();
			var $toggle = $(this);
			var $li = $toggle.closest('li');
			var $nested = $li.find('> .ss-hierarchy-tree-nested');
			
			if ($nested.is(':visible')) {
				// Collapse
				$nested.slideUp(200);
				$toggle.text('▶').attr('aria-expanded', 'false');
				$li.addClass('ss-hierarchy-collapsed');
			} else {
				// Expand
				$nested.slideDown(200);
				$toggle.text('▼').attr('aria-expanded', 'true');
				$li.removeClass('ss-hierarchy-collapsed');
			}
		});

		// Allow keyboard navigation (Enter/Space to toggle)
		$container.on('keydown', '.ss-hierarchy-toggle', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$(this).trigger('click');
			}
		});
	}

	/**
	 * Render properties grouped by source category.
	 * 
	 * @param {Array} props Properties array from API
	 * @return {jQuery} Table element
	 */
	function renderPropertiesByCategory(props) {
		// Group properties by source category
		var grouped = {};
		props.forEach(function (p) {
			var source = p.sourceCategory || '';
			if (!grouped[source]) {
				grouped[source] = [];
			}
			grouped[source].push(p);
		});

		// Create table
		var $table = $('<table>')
			.addClass('wikitable ss-prop-table');

		// Table header
		var $thead = $('<thead>').append(
			$('<tr>')
				.append($('<th>').text(mw.msg('structuresync-hierarchy-source-category')))
				.append($('<th>').text(mw.msg('structuresync-hierarchy-properties')))
		);
		$table.append($thead);

		// Table body
		var $tbody = $('<tbody>');

		// Sort source categories for consistent display
		var sources = Object.keys(grouped).sort();

		sources.forEach(function (sourceTitle) {
			var $row = $('<tr>');

			// Category cell
			var $catCell = $('<td>').addClass('ss-prop-source-cell');
			if (sourceTitle) {
				var href = mw.util.getUrl(sourceTitle);
				var displayName = sourceTitle.replace(/^Category:/, '');
				$catCell.append(
					$('<a>')
						.attr('href', href)
						.attr('title', sourceTitle)
						.text(displayName)
				);
			} else {
				$catCell.text(mw.msg('structuresync-hierarchy-unknown-category'));
			}

			// Properties cell
			var $propCell = $('<td>').addClass('ss-prop-list-cell');
			var $ul = $('<ul>').addClass('ss-prop-list');

			grouped[sourceTitle].forEach(function (p) {
				var $li = $('<li>');
				var isRequired = (p.required === 1 || p.required === true);
				var cssClass = isRequired ? 'ss-prop-required' : 'ss-prop-optional';
				$li.addClass(cssClass);

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
					
					var badgeText = isRequired
						? mw.msg('structuresync-hierarchy-required')
						: mw.msg('structuresync-hierarchy-optional');
					$li.append(
						' ',
						$('<span>')
							.addClass('ss-prop-badge')
							.text(badgeText)
					);
				} else {
					$li.text(mw.msg('structuresync-hierarchy-unnamed-property'));
				}

				$ul.append($li);
			});

			$propCell.append($ul);
			$row.append($catCell).append($propCell);
			$tbody.append($row);
		});

		$table.append($tbody);
		return $table;
	}

	/**
	 * Render properties grouped by required/optional status.
	 * 
	 * @param {Array} props Properties array from API
	 * @return {jQuery} Container element with grouped lists
	 */
	function renderPropertiesByType(props) {
		var $container = $('<div>').addClass('ss-prop-by-type');

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
					$li.text(mw.msg('structuresync-hierarchy-unnamed-property'));
				}
				
				$requiredList.append($li);
			});
			$requiredSection.append($requiredList);
			$container.append($requiredSection);
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
					$li.text(mw.msg('structuresync-hierarchy-unnamed-property'));
				}
				
				$optionalList.append($li);
			});
			$optionalSection.append($optionalList);
			$container.append($optionalSection);
		}

		return $container;
	}

	/**
	 * Render the inherited properties with tabbed views.
	 * 
	 * Properties can be viewed grouped by category or by type (required/optional).
	 * 
	 * @param {jQuery} $container Container element
	 * @param {Object} hierarchyData Hierarchy data from API
	 */
	function renderPropertyTable($container, hierarchyData) {
		var props = hierarchyData.inheritedProperties || [];
		
		if (props.length === 0) {
			$container.empty().append(
				$('<p>').addClass('ss-hierarchy-empty').text(
					mw.msg('structuresync-hierarchy-no-properties')
				)
			);
			return;
		}

		// Create tab controls
		var $tabs = $('<div>').addClass('ss-prop-tabs');
		
		var $tabByCategory = $('<button>')
			.addClass('ss-prop-tab ss-prop-tab-active')
			.attr('data-tab', 'category')
			.text('By Category');
		
		var $tabByType = $('<button>')
			.addClass('ss-prop-tab')
			.attr('data-tab', 'type')
			.text('By Type');
		
		$tabs.append($tabByCategory, $tabByType);

		// Create tab content containers
		var $tabContents = $('<div>').addClass('ss-prop-tab-contents');
		
		var $contentByCategory = $('<div>')
			.addClass('ss-prop-tab-content ss-prop-tab-content-active')
			.attr('data-content', 'category')
			.append(renderPropertiesByCategory(props));
		
		var $contentByType = $('<div>')
			.addClass('ss-prop-tab-content')
			.attr('data-content', 'type')
			.append(renderPropertiesByType(props));
		
		$tabContents.append($contentByCategory, $contentByType);

		// Render everything
		$container.empty().append($tabs, $tabContents);

		// Tab click handlers
		$tabs.on('click', '.ss-prop-tab', function () {
			var $clickedTab = $(this);
			var targetTab = $clickedTab.data('tab');
			
			// Update active tab
			$tabs.find('.ss-prop-tab').removeClass('ss-prop-tab-active');
			$clickedTab.addClass('ss-prop-tab-active');
			
			// Update visible content
			$tabContents.find('.ss-prop-tab-content').removeClass('ss-prop-tab-content-active');
			$tabContents.find('.ss-prop-tab-content[data-content="' + targetTab + '"]').addClass('ss-prop-tab-content-active');
		});
	}

	/**
	 * Fetch hierarchy data from API and render it.
	 * 
	 * @param {jQuery} $root Root container element
	 * @param {string} categoryTitle Category title (with or without "Category:" prefix)
	 */
	function fetchAndRender($root, categoryTitle) {
		// Show loading state
		$root.addClass('ss-hierarchy-loading').empty().append(
			$('<p>').text(mw.msg('structuresync-hierarchy-loading'))
		);

		var api = new mw.Api();
		api.get({
			action: 'structuresync-hierarchy',
			category: categoryTitle,
			format: 'json'
		}).done(function (data) {
			$root.removeClass('ss-hierarchy-loading');
			
			var moduleData = data['structuresync-hierarchy'];
			if (!moduleData) {
				$root.empty().append(
					$('<p>').addClass('error').text(
						mw.msg('structuresync-hierarchy-no-data')
					)
				);
				return;
			}

			// Create containers for tree and properties
			var $treeContainer = $('<div>').addClass('ss-hierarchy-tree-container');
			var $propsContainer = $('<div>').addClass('ss-hierarchy-props-container');

			// Build the UI
			$root.empty().append(
				$('<div>').addClass('ss-hierarchy-section').append(
					$('<h3>').text(mw.msg('structuresync-hierarchy-tree-title')),
					$treeContainer
				),
				$('<div>').addClass('ss-hierarchy-section').append(
					$('<h3>').text(mw.msg('structuresync-hierarchy-props-title')),
					$propsContainer
				)
			);

			// Render tree and properties
			renderHierarchyTree($treeContainer, moduleData);
			renderPropertyTable($propsContainer, moduleData);

		}).fail(function (code, result) {
			$root.removeClass('ss-hierarchy-loading').empty().append(
				$('<p>').addClass('error').text(
					mw.msg('structuresync-hierarchy-error') + ': ' + 
					(result.error && result.error.info ? result.error.info : code)
				)
			);
		});
	}

	/**
	 * Public API
	 */
	mw.StructureSyncHierarchy = {
		/**
		 * Render hierarchy visualization into a container.
		 * 
		 * @param {Element|jQuery|string} container Container element or selector
		 * @param {string} categoryTitle Category title to visualize
		 */
		renderInto: function (container, categoryTitle) {
			var $root = $(container);
			
			if ($root.length === 0) {
				mw.log.warn('StructureSyncHierarchy: Container not found');
				return;
			}

			if (!categoryTitle || typeof categoryTitle !== 'string') {
				$root.empty().append(
					$('<p>').addClass('error').text(
						mw.msg('structuresync-hierarchy-no-category')
					)
				);
				return;
			}

			fetchAndRender($root, categoryTitle);
		}
	};

	/**
	 * Auto-initialize: Check for containers with data-category attribute
	 * and automatically render the hierarchy.
	 */
	$(function () {
		$('.ss-hierarchy-block[data-category]').each(function () {
			var $container = $(this);
			var categoryTitle = $container.data('category');
			if (categoryTitle) {
				mw.StructureSyncHierarchy.renderInto($container, categoryTitle);
			}
		});
	});

}(mediaWiki, jQuery));

