/**
 * SemanticSchemas Create Page UI
 * ===============================
 * Interactive category selection, live property preview, and form submission.
 *
 * Architecture:
 * - Checkbox tree with expand/collapse (built from embedded category data)
 * - Chip list for selected categories
 * - Debounced AJAX property preview
 * - Namespace conflict resolution
 * - Page existence check
 * - Submit to Special:FormEdit
 */
(function (mw, $) {
	'use strict';

	/* =======================================================================
	 * CONSTANTS AND STATE
	 * ======================================================================= */

	var UPDATE_DELAY = 300; // ms debounce for property preview
	var PAGE_CHECK_DELAY = 500; // ms debounce for page existence check
	var updateTimer = null;
	var pageCheckTimer = null;
	var requestCounter = 0; // Race condition prevention
	var selectedCategories = {}; // { categoryName: true }
	var currentNamespace = null; // Selected namespace (null = default)
	var treeData = null; // Cached tree data

	/* =======================================================================
	 * HELPERS
	 * ======================================================================= */

	var msg = function (name) {
		return mw.msg(name);
	};

	var stripPrefix = function (title, prefix) {
		return typeof title === 'string'
			? title.replace(new RegExp('^' + prefix + ':'), '')
			: '';
	};

	/* =======================================================================
	 * COMPONENT 1: TREE LOADING AND RENDERING
	 * ======================================================================= */

	function loadAndRenderTree() {
		var $treeContainer = $('#ss-createpage-tree');
		var treeNodesJson = $treeContainer.attr('data-tree-nodes');

		if (!treeNodesJson) {
			$treeContainer.html('<p class="ss-hierarchy-empty">' + msg('semanticschemas-hierarchy-no-data') + '</p>');
			return;
		}

		var nodes;
		try {
			nodes = JSON.parse(treeNodesJson);
		} catch (e) {
			$treeContainer.html('<p class="error">' + msg('semanticschemas-hierarchy-error') + '</p>');
			return;
		}

		treeData = {nodes: nodes};
		renderTree($treeContainer, nodes);
	}

	function renderTree($container, nodes) {
		var nodeKeys = Object.keys(nodes);

		if (nodeKeys.length === 0) {
			$container.html('<p class="ss-hierarchy-empty">' + msg('semanticschemas-hierarchy-no-data') + '</p>');
			return;
		}

		// Build children index by inverting parent relationships
		var childrenOf = {};
		var i, key, node, parents, j;

		for (i = 0; i < nodeKeys.length; i++) {
			childrenOf[nodeKeys[i]] = [];
		}

		for (i = 0; i < nodeKeys.length; i++) {
			key = nodeKeys[i];
			node = nodes[key];
			parents = Array.isArray(node.parents) ? node.parents : [];
			for (j = 0; j < parents.length; j++) {
				if (!childrenOf[parents[j]]) {
					childrenOf[parents[j]] = [];
				}
				childrenOf[parents[j]].push(key);
			}
		}

		// Sort children alphabetically
		for (key in childrenOf) {
			childrenOf[key].sort();
		}

		// Find root categories: no parents, or all parents missing from nodes map
		var roots = [];
		for (i = 0; i < nodeKeys.length; i++) {
			key = nodeKeys[i];
			parents = nodes[key].parents || [];
			if (parents.length === 0 || parents.every(function (p) {
				return !nodes[p];
			})) {
				roots.push(key);
			}
		}
		roots.sort();

		if (roots.length === 0) {
			$container.html('<p class="ss-hierarchy-empty">' + msg('semanticschemas-hierarchy-no-data') + '</p>');
			return;
		}

		// Recursive node builder
		var buildNode = function (title, depth) {
			var nodeData = nodes[title];
			if (!nodeData) {
				return null;
			}

			var children = childrenOf[title] || [];
			var $li = $('<li>');
			var $content = $('<span>').addClass('ss-hierarchy-node-content');
			var expanded;

			// Toggle arrow if has children
			if (children.length > 0) {
				expanded = (depth < 1);
				$content.append(
					$('<span>')
						.addClass('ss-hierarchy-toggle')
						.attr({
							role: 'button',
							tabindex: 0,
							'aria-expanded': expanded ? 'true' : 'false'
						})
						.text(expanded ? '▼' : '▶')
				);
				$li.addClass('ss-hierarchy-has-children');
				if (!expanded) {
					$li.addClass('ss-hierarchy-collapsed');
				}
			}

			// Checkbox
			var categoryName = stripPrefix(title, 'Category');
			var $checkbox = $('<input>')
				.attr({
					type: 'checkbox',
					'data-category': categoryName
				})
				.addClass('ss-createpage-checkbox');

			$content.append(' ', $checkbox, ' ');

			// Category name
			var $label = $('<label>')
				.text(categoryName)
				.css({cursor: 'pointer'});

			$label.on('click', function (e) {
				e.preventDefault();
				$checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
			});

			$content.append($label);
			$li.append($content);

			// Children
			if (children.length > 0) {
				var $ul = $('<ul>').addClass('ss-hierarchy-tree-nested');
				if (!expanded) {
					$ul.hide();
				}
				for (var c = 0; c < children.length; c++) {
					var child = buildNode(children[c], depth + 1);
					if (child) {
						$ul.append(child);
					}
				}
				$li.append($ul);
			}

			return $li;
		};

		var $rootTree = $('<ul>').addClass('ss-hierarchy-tree');
		for (var r = 0; r < roots.length; r++) {
			var $rootNode = buildNode(roots[r], 0);
			if ($rootNode) {
				$rootTree.append($rootNode);
			}
		}

		$container.empty().append($rootTree);

		// Attach event handlers
		attachTreeHandlers($container);
	}

	function attachTreeHandlers($container) {
		// Toggle expand/collapse
		$container.off('click.ssToggle keydown.ssToggle');

		$container.on('click.ssToggle', '.ss-hierarchy-toggle', function (e) {
			e.preventDefault();
			var $toggle = $(this);
			var $li = $toggle.closest('li');
			var $nested = $li.children('.ss-hierarchy-tree-nested');

			var expanded = $nested.is(':visible');
			if (expanded) {
				$nested.slideUp(200);
				$toggle.text('▶').attr('aria-expanded', 'false');
				$li.addClass('ss-hierarchy-collapsed');
			} else {
				$nested.slideDown(200);
				$toggle.text('▼').attr('aria-expanded', 'true');
				$li.removeClass('ss-hierarchy-collapsed');
			}
		});

		$container.on('keydown.ssToggle', '.ss-hierarchy-toggle', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$(this).trigger('click');
			}
		});

		// Checkbox change handler
		$container.on('change', '.ss-createpage-checkbox', function () {
			var $checkbox = $(this);
			var categoryName = $checkbox.data('category');
			var isChecked = $checkbox.prop('checked');

			if (isChecked) {
				selectedCategories[categoryName] = true;
			} else {
				delete selectedCategories[categoryName];
			}

			updateChipList();
			onSelectionChanged();
		});
	}

	/* =======================================================================
	 * COMPONENT 2: CHIP LIST
	 * ======================================================================= */

	function updateChipList() {
		var $chipContainer = $('#ss-createpage-chips');
		$chipContainer.empty();

		var categoryNames = Object.keys(selectedCategories).sort();

		for (var i = 0; i < categoryNames.length; i++) {
			var catName = categoryNames[i];

			var $chip = $('<span>').addClass('ss-createpage-chip');
			$chip.text(catName);

			var $removeBtn = $('<button>')
				.addClass('ss-createpage-chip-remove')
				.attr('type', 'button')
				.attr('aria-label', 'Remove ' + catName)
				.html('&times;')
				.data('category', catName);

			$chip.append($removeBtn);
			$chipContainer.append($chip);
		}

		// Remove button handler
		$chipContainer.off('click.chipRemove');
		$chipContainer.on('click.chipRemove', '.ss-createpage-chip-remove', function () {
			var catName = $(this).data('category');
			delete selectedCategories[catName];

			// Uncheck corresponding tree checkbox
			$('.ss-createpage-checkbox[data-category="' + catName + '"]').prop('checked', false);

			updateChipList();
			onSelectionChanged();
		});
	}

	/* =======================================================================
	 * COMPONENT 3: PROPERTY PREVIEW (DEBOUNCED AJAX)
	 * ======================================================================= */

	function onSelectionChanged() {
		// Clear debounce timer
		if (updateTimer !== null) {
			clearTimeout(updateTimer);
		}

		// Set new timer
		updateTimer = setTimeout(function () {
			var categories = Object.keys(selectedCategories);

			if (categories.length === 0) {
				showEmptyPreview();
				hideNamespacePicker();
				updateSubmitState();
			} else {
				fetchPreview(categories);
			}
		}, UPDATE_DELAY);
	}

	function showEmptyPreview() {
		var $preview = $('#ss-createpage-preview');
		$preview.html('<p class="ss-hierarchy-empty">' + msg('semanticschemas-createpage-empty-state') + '</p>');
	}

	function fetchPreview(categories) {
		var thisRequest = ++requestCounter;

		var $preview = $('#ss-createpage-preview');
		$preview.addClass('ss-hierarchy-loading');
		$preview.html('<p>' + msg('semanticschemas-hierarchy-loading') + '</p>');

		new mw.Api().get({
			action: 'semanticschemas-multicategory',
			categories: categories.join('|'),
			format: 'json'
		}).done(function (response) {
			// Discard stale responses
			if (thisRequest !== requestCounter) {
				return;
			}

			$preview.removeClass('ss-hierarchy-loading');

			var data = response['semanticschemas-multicategory'];
			if (!data) {
				$preview.html('<p class="error">' + msg('semanticschemas-hierarchy-error') + '</p>');
				return;
			}

			renderPropertyPreview(data);
			checkNamespaceConflict(data);
			updateSubmitState();

		}).fail(function () {
			if (thisRequest !== requestCounter) {
				return;
			}

			$preview.removeClass('ss-hierarchy-loading');
			$preview.html('<p class="error">' + msg('semanticschemas-hierarchy-error') + '</p>');
		});
	}

	function renderPropertyPreview(data) {
		var $preview = $('#ss-createpage-preview');
		$preview.empty();

		var properties = data.properties || [];

		if (properties.length === 0) {
			$preview.html('<p class="ss-hierarchy-empty">' + msg('semanticschemas-hierarchy-no-properties') + '</p>');
			return;
		}

		// Separate shared and per-category properties
		var sharedProps = [];
		var categoryProps = {}; // { categoryName: [props] }

		for (var i = 0; i < properties.length; i++) {
			var prop = properties[i];
			if (prop.shared === 1) {
				sharedProps.push(prop);
			} else {
				var sourceCat = (prop.sources && prop.sources.length > 0) ? prop.sources[0] : '';
				if (!categoryProps[sourceCat]) {
					categoryProps[sourceCat] = [];
				}
				categoryProps[sourceCat].push(prop);
			}
		}

		// Render shared section
		if (sharedProps.length > 0) {
			var $sharedSection = $('<div>').addClass('ss-createpage-shared-section');
			$sharedSection.append($('<h4>').text(msg('semanticschemas-createpage-shared-section')));

			var $sharedList = $('<ul>').addClass('ss-prop-list ss-prop-list-by-type');
			for (var j = 0; j < sharedProps.length; j++) {
				$sharedList.append(renderPropertyRow(sharedProps[j]));
			}
			$sharedSection.append($sharedList);
			$preview.append($sharedSection);
		}

		// Render per-category sections
		var sortedCategoryNames = Object.keys(categoryProps).sort();
		for (var k = 0; k < sortedCategoryNames.length; k++) {
			var catName = sortedCategoryNames[k];
			var props = categoryProps[catName];

			var $catSection = $('<div>').addClass('ss-createpage-category-section');
			$catSection.append($('<h4>').text(catName));

			var $catList = $('<ul>').addClass('ss-prop-list ss-prop-list-by-type');
			for (var m = 0; m < props.length; m++) {
				$catList.append(renderPropertyRow(props[m]));
			}
			$catSection.append($catList);
			$preview.append($catSection);
		}
	}

	function renderPropertyRow(prop) {
		var isRequired = (prop.required === 1 || prop.required === true);
		var $li = $('<li>').addClass(isRequired ? 'ss-prop-required' : 'ss-prop-optional');

		// Property name (bold)
		var propName = prop.name || '';
		$li.append($('<strong>').text(propName));

		// Datatype badge
		if (prop.datatype) {
			$li.append(
				$('<span>')
					.addClass('ss-createpage-datatype')
					.text(prop.datatype)
			);
		}

		// Required/optional badge
		$li.append(
			$('<span>')
				.addClass('ss-prop-badge')
				.text(isRequired ? msg('semanticschemas-hierarchy-required') : msg('semanticschemas-hierarchy-optional'))
		);

		return $li;
	}

	/* =======================================================================
	 * COMPONENT 4: NAMESPACE CONFLICT DETECTION AND PICKER
	 * ======================================================================= */

	function checkNamespaceConflict(data) {
		var categoryList = data.categories || [];

		// Extract unique namespaces
		var namespaces = {};
		for (var i = 0; i < categoryList.length; i++) {
			var cat = categoryList[i];
			var ns = cat.targetNamespace || null;
			var nsDisplay = ns || 'Main';

			if (!namespaces[nsDisplay]) {
				namespaces[nsDisplay] = {
					value: ns,
					categories: []
				};
			}
			namespaces[nsDisplay].categories.push(stripPrefix(cat.name, 'Category'));
		}

		var uniqueNamespaces = Object.keys(namespaces);

		if (uniqueNamespaces.length <= 1) {
			// No conflict
			hideNamespacePicker();
			if (uniqueNamespaces.length === 1) {
				currentNamespace = namespaces[uniqueNamespaces[0]].value;
			} else {
				currentNamespace = null;
			}
		} else {
			// Conflict: show picker
			showNamespacePicker(namespaces);
		}
	}

	function showNamespacePicker(namespaces) {
		var $picker = $('#ss-createpage-namespace');
		$picker.empty();

		var $warning = $('<div>').addClass('ss-createpage-namespace-picker');
		$warning.append($('<p>').text(msg('semanticschemas-createpage-namespace-conflict')));

		var sortedNs = Object.keys(namespaces).sort();
		for (var i = 0; i < sortedNs.length; i++) {
			var nsDisplay = sortedNs[i];
			var nsData = namespaces[nsDisplay];

			var radioId = 'ss-ns-radio-' + i;
			var $radio = $('<input>')
				.attr({
					type: 'radio',
					name: 'ss-namespace',
					id: radioId,
					value: nsData.value || ''
				})
				.data('ns-value', nsData.value);

			if (i === 0) {
				$radio.prop('checked', true);
				currentNamespace = nsData.value;
			}

			var labelText = nsDisplay + ' (used by: ' + nsData.categories.join(', ') + ')';
			var $label = $('<label>')
				.attr('for', radioId)
				.text(labelText)
				.css({marginLeft: '5px'});

			var $radioDiv = $('<div>').append($radio, $label);
			$warning.append($radioDiv);
		}

		$picker.append($warning);
		$picker.show();

		// Radio change handler
		$picker.off('change.nsRadio');
		$picker.on('change.nsRadio', 'input[type="radio"]', function () {
			currentNamespace = $(this).data('ns-value');
		});
	}

	function hideNamespacePicker() {
		$('#ss-createpage-namespace').hide().empty();
		currentNamespace = null;
	}

	/* =======================================================================
	 * COMPONENT 5: PAGE NAME INPUT WITH EXISTENCE CHECK
	 * ======================================================================= */

	function setupPageNameInput() {
		var $input = $('#ss-createpage-pagename');
		var $warning = $('#ss-createpage-page-warning');

		$input.on('input', function () {
			// Clear debounce timer
			if (pageCheckTimer !== null) {
				clearTimeout(pageCheckTimer);
			}

			var pageName = $input.val().trim();
			updateSubmitState();

			if (pageName === '') {
				$warning.hide();
				return;
			}

			// Debounce page existence check
			pageCheckTimer = setTimeout(function () {
				checkPageExistence(pageName, $warning);
			}, PAGE_CHECK_DELAY);
		});
	}

	function checkPageExistence(pageName, $warning) {
		var fullTitle = pageName;
		if (currentNamespace) {
			fullTitle = currentNamespace + ':' + pageName;
		}

		new mw.Api().get({
			action: 'query',
			titles: fullTitle,
			format: 'json'
		}).done(function (response) {
			if (!response.query || !response.query.pages) {
				return;
			}

			var pages = response.query.pages;
			var pageId = Object.keys(pages)[0];
			var page = pages[pageId];

			if (!page.hasOwnProperty('missing')) {
				// Page exists
				$warning.text(msg('semanticschemas-createpage-page-exists-warning')).show();
			} else {
				$warning.hide();
			}
		}).fail(function () {
			$warning.hide();
		});
	}

	/* =======================================================================
	 * COMPONENT 6: SUBMIT BUTTON
	 * ======================================================================= */

	function updateSubmitState() {
		var $submit = $('#ss-createpage-submit');
		var $input = $('#ss-createpage-pagename');

		var hasCategories = Object.keys(selectedCategories).length > 0;
		var hasPageName = $input.val().trim() !== '';

		if (hasCategories && hasPageName) {
			$submit.prop('disabled', false);
		} else {
			$submit.prop('disabled', true);
		}
	}

	function setupSubmitHandler() {
		var $submit = $('#ss-createpage-submit');

		$submit.on('click', function () {
			if ($submit.prop('disabled')) {
				return;
			}

			// Disable button, show submitting state
			$submit.prop('disabled', true);
			var originalText = $submit.text();
			$submit.text(msg('semanticschemas-createpage-submitting'));

			// Gather data
			var categories = Object.keys(selectedCategories);
			var pageName = $('#ss-createpage-pagename').val().trim();
			var namespace = currentNamespace || '';

			// POST to SpecialPage
			$.post(mw.util.getUrl('Special:CreateSemanticPage'), {
				action: 'createpage',
				categories: categories,
				pagename: pageName,
				namespace: namespace,
				wpEditToken: mw.user.tokens.get('csrfToken')
			}, 'json')
			.done(function (response) {
				if (response.success) {
					// Build target page
					var targetPage = pageName;
					if (namespace) {
						targetPage = namespace + ':' + pageName;
					}

					// Redirect to FormEdit
					var formEditUrl = mw.util.getUrl('Special:FormEdit/' + response.formName + '/' + targetPage);
					window.location.href = formEditUrl;
				} else {
					// Show error
					var $preview = $('#ss-createpage-preview');
					var errorMsg = response.error || msg('semanticschemas-hierarchy-error');
					$preview.html('<p class="error">' + errorMsg + '</p>');

					// Re-enable button
					$submit.prop('disabled', false);
					$submit.text(originalText);
				}
			})
			.fail(function () {
				// Show error
				var $preview = $('#ss-createpage-preview');
				$preview.html('<p class="error">' + msg('semanticschemas-hierarchy-error') + '</p>');

				// Re-enable button
				$submit.prop('disabled', false);
				$submit.text(originalText);
			});
		});
	}

	/* =======================================================================
	 * INIT
	 * ======================================================================= */

	function init() {
		loadAndRenderTree();
		setupPageNameInput();
		setupSubmitHandler();
		updateSubmitState();
	}

	// Initialize on DOM ready
	$(function () {
		init();
	});

}(mediaWiki, jQuery));
