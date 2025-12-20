<?php

namespace MediaWiki\Extension\SemanticSchemas\Special;

use MediaWiki\Extension\SemanticSchemas\Generator\DisplayStubGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\FormGenerator;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\SchemaComparer;
use MediaWiki\Extension\SemanticSchemas\Schema\OntologyInspector;
use MediaWiki\Extension\SemanticSchemas\Schema\SchemaLoader;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiSubobjectStore;
use MediaWiki\Extension\SemanticSchemas\Store\StateManager;
use MediaWiki\Extension\SemanticSchemas\Store\PageHashComputer;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Html\Html;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * SpecialSemanticSchemas
 * --------------------
 * Central administrative UI for SemanticSchemas schema management.
 * 
 * File Size Note:
 * --------------
 * This file is intentionally large (1001 lines) as it serves as the main
 * UI controller for all schema operations. It follows MediaWiki's SpecialPage
 * pattern where a single class handles:
 * - Request routing
 * - Form rendering
 * - Action processing
 * - UI generation
 * 
 * Future Refactoring:
 * ------------------
 * For maintainability, consider extracting to separate classes:
 * - UI/SpecialPageRenderer: HTML generation
 * - UI/TableBuilder: Category/property table rendering
 * - UI/FormHandler: Form processing logic
 * However, the current structure follows standard MediaWiki conventions.
 * 
 * Central UI for:
 * --------------
 * - Overview: Dashboard with category status and sync state
 * - Validate: Check wiki state for errors and modifications
 * - Generate: Regenerate templates/forms/displays
 * - Hierarchy: Visualize category inheritance and subobjects
 * 
 * Architecture:
 * ------------
 * - Schema is treated as the source of truth
 * - Wiki pages (Category/Property/Form/Template) are compiled artifacts
 * - State tracking prevents unnecessary regeneration
 * - Hash-based dirty detection identifies external modifications
 * 
 * Security:
 * --------
 * - Requires 'editinterface' permission
 * - CSRF token validation on all form submissions (matchEditToken)
 * - Input sanitization via MediaWiki's request handling
 * - File upload validation (JSON/YAML only)
 * - Rate limiting: max 20 operations per hour per user
 * - Sysops can bypass rate limits via 'protect' permission
 * - All operations logged to MediaWiki log system (audit trail)
 * 
 * Performance:
 * -----------
 * - Large operations (import/generate) are synchronous
 * - Progress indicators show operation status
 * - Per-category progress feedback for bulk operations
 * - State caching reduces repeated inheritance resolution
 * - Rate limiting prevents server overload
 */
class SpecialSemanticSchemas extends SpecialPage
{

	/** @var int Rate limit: max operations per hour per user */
	private const RATE_LIMIT_PER_HOUR = 20;

	/** @var string Cache key for rate limiting */
	private const RATE_LIMIT_CACHE_PREFIX = 'semanticschemas-ratelimit';

	public function __construct()
	{
		parent::__construct('SemanticSchemas', 'editinterface');
	}

	/**
	 * Check rate limiting for expensive operations.
	 *
	 * Limits users to a maximum number of import/generate operations per hour
	 * to prevent abuse and server overload.
	 *
	 * @param string $operation Operation name (import, generate, etc.)
	 * @return bool True if rate limit exceeded
	 */
	private function checkRateLimit(string $operation): bool
	{
		$user = $this->getUser();

		// Exempt sysops from rate limiting
		if ($user->isAllowed('semanticschemas-bypass-ratelimit') || $user->isAllowed('protect')) {
			return false;
		}

		$cache = \ObjectCache::getLocalClusterInstance();
		$key = $cache->makeKey(self::RATE_LIMIT_CACHE_PREFIX, $user->getId(), $operation);

		$count = $cache->get($key) ?: 0;

		if ($count >= self::RATE_LIMIT_PER_HOUR) {
			return true; // Rate limit exceeded
		}

		// Increment counter (expires in 1 hour)
		$cache->set($key, $count + 1, 3600);

		return false;
	}

	/**
	 * Log an administrative operation to MediaWiki logs.
	 *
	 * Creates audit trail for import/export/generate operations.
	 *
	 * @param string $action Action performed (import, export, generate)
	 * @param string $details Additional details about the operation
	 * @param array $params Structured parameters for the log entry
	 */
	private function logOperation(string $action, string $details, array $params = []): void
	{
		$logEntry = new \ManualLogEntry('semanticschemas', $action);
		$logEntry->setPerformer($this->getUser());
		$logEntry->setTarget($this->getPageTitle());
		$logEntry->setComment($details);
		$logEntry->setParameters($params);
		$logId = $logEntry->insert();
		$logEntry->publish($logId);
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute($subPage)
	{
		$this->setHeaders();
		$this->checkPermissions();

		$request = $this->getRequest();
		$output = $this->getOutput();

		// Dependency checks
		if (!defined('SMW_VERSION')) {
			$output->addHTML(Html::errorBox(
				$this->msg('semanticschemas-error-no-smw')->parse()
			));
			return;
		}

		if (!defined('PF_VERSION')) {
			$output->addHTML(Html::errorBox(
				$this->msg('semanticschemas-error-no-pageforms')->parse()
			));
			return;
		}

		// Add ResourceLoader styles
		$output->addModuleStyles('ext.semanticschemas.styles');

		// Determine action from subpage
		$action = $subPage ?: 'overview';

		// Navigation tabs
		$this->showNavigation($action);

		// Dispatch
		switch ($action) {
			case 'validate':
				$this->showValidate();
				break;
			case 'generate':
				$this->showGenerate();
				break;
			case 'hierarchy':
				$this->showHierarchy();
				break;
			case 'overview':
			default:
				$this->showOverview();
				break;
		}
	}

	/**
	 * Navigation tabs for Special:SemanticSchemas
	 *
	 * @param string $currentAction
	 */
	private function showNavigation(string $currentAction): void
	{
		$tabs = [
			'overview' => [
				'label' => $this->msg('semanticschemas-overview')->text(),
				'subtext' => $this->msg('semanticschemas-tab-overview-subtext')->text(),
			],
			'validate' => [
				'label' => $this->msg('semanticschemas-validate')->text(),
				'subtext' => $this->msg('semanticschemas-tab-validate-subtext')->text(),
			],
			'generate' => [
				'label' => $this->msg('semanticschemas-generate')->text(),
				'subtext' => $this->msg('semanticschemas-tab-generate-subtext')->text(),
			],
			'hierarchy' => [
				'label' => $this->msg('semanticschemas-hierarchy')->text(),
				'subtext' => $this->msg('semanticschemas-tab-hierarchy-subtext')->text(),
			],
		];

		$links = '';
		foreach ($tabs as $action => $data) {
			$url = $this->getPageTitle($action)->getLocalURL();
			$isActive = ($action === $currentAction);
			$links .= Html::rawElement(
				'a',
				[
					'href' => $url,
					'class' => 'semanticschemas-tab' . ($isActive ? ' is-active' : ''),
					'aria-current' => $isActive ? 'page' : null,
				],
				Html::rawElement('span', ['class' => 'semanticschemas-tab-label'], $data['label']) .
				Html::rawElement('span', ['class' => 'semanticschemas-tab-subtext'], $data['subtext'])
			);
		}

		$nav = Html::rawElement(
			'nav',
			['class' => 'semanticschemas-tabs', 'role' => 'tablist'],
			$links
		);

		$this->getOutput()->addHTML(
			Html::rawElement('div', ['class' => 'semanticschemas-shell'], $nav)
		);
	}

	/**
	 * Wrap arbitrary HTML in the standard page shell container.
	 *
	 * @param string $html
	 * @return string
	 */
	private function wrapShell(string $html): string
	{
		return Html::rawElement('div', ['class' => 'semanticschemas-shell'], $html);
	}

	/**
	 * Render a compact stat card tile.
	 *
	 * @param string $label
	 * @param string $value
	 * @param string $subtext
	 * @return string
	 */
	private function renderStatCard(string $label, string $value, string $subtext = ''): string
	{
		$body = Html::rawElement('div', ['class' => 'semanticschemas-stat-label'], $label) .
			Html::rawElement('div', ['class' => 'semanticschemas-stat-value'], $value);

		if ($subtext !== '') {
			$body .= Html::rawElement('p', ['class' => 'semanticschemas-stat-subtext'], $subtext);
		}

		return Html::rawElement('div', ['class' => 'semanticschemas-stat-card'], $body);
	}

	/**
	 * Format timestamps for display using the viewer's language.
	 *
	 * @param string|null $timestamp
	 * @return string
	 */
	private function formatTimestamp(?string $timestamp): string
	{
		if ($timestamp === null || trim($timestamp) === '') {
			return $this->msg('semanticschemas-label-not-available')->text();
		}

		$ts = wfTimestamp(TS_MW, $timestamp);
		if ($ts === false) {
			return $timestamp;
		}

		$lang = $this->getLanguage();
		return $lang->userDate($ts, $this->getUser()) . ' · ' . $lang->userTime($ts, $this->getUser());
	}

	/**
	 * Trim schema hashes for smaller display.
	 *
	 * @param string|null $hash
	 * @return string
	 */
	private function formatSchemaHash(?string $hash): string
	{
		if ($hash === null || $hash === '') {
			return $this->msg('semanticschemas-label-not-available')->text();
		}

		return substr($hash, 0, 10) . '…';
	}

	/**
	 * Render a colored pill badge.
	 *
	 * @param bool $state
	 * @param string $labelWhenTrue
	 * @param string $labelWhenFalse
	 * @param string $trueClass
	 * @param string $falseClass
	 * @return string
	 */
	private function renderBadge(
		bool $state,
		string $labelWhenTrue,
		string $labelWhenFalse,
		string $trueClass = 'is-ok',
		string $falseClass = 'is-alert'
	): string {
		return Html::rawElement(
			'span',
			['class' => 'semanticschemas-badge ' . ($state ? $trueClass : $falseClass)],
			$state ? $labelWhenTrue : $labelWhenFalse
		);
	}

	/**
	 * Render a badge that links to a MediaWiki title when provided.
	 *
	 * @param bool $state
	 * @param string $labelWhenTrue
	 * @param string $labelWhenFalse
	 * @param Title|null $title
	 * @param string $trueClass
	 * @param string $falseClass
	 * @return string
	 */
	private function renderBadgeLink(
		bool $state,
		string $labelWhenTrue,
		string $labelWhenFalse,
		?Title $title,
		string $trueClass = 'is-ok',
		string $falseClass = 'is-alert'
	): string {
		$badge = Html::rawElement(
			'span',
			['class' => 'semanticschemas-badge ' . ($state ? $trueClass : $falseClass)],
			$state ? $labelWhenTrue : $labelWhenFalse
		);

		if ($title === null) {
			return $badge;
		}

		return Html::rawElement(
			'a',
			[
				'href' => $title->getLocalURL(),
				'class' => 'semanticschemas-inline-link'
			],
			$badge
		);
	}

	/**
	 * Resolve the PageForms namespace ID.
	 *
	 * @return int
	 */
	private function getFormNamespace(): int
	{
		return defined('PF_NS_FORM') ? constant('PF_NS_FORM') : NS_MAIN;
	}

	private function getTemplateTitle(string $categoryName): ?Title
	{
		return Title::makeTitleSafe(NS_TEMPLATE, $categoryName);
	}

	private function getFormTitle(string $categoryName): ?Title
	{
		return Title::makeTitleSafe($this->getFormNamespace(), $categoryName);
	}

	private function getDisplayTitle(string $categoryName): ?Title
	{
		return Title::makeTitleSafe(NS_TEMPLATE, $categoryName . '/display');
	}

	/**
	 * Metadata describing quick actions surfaced on the overview page.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function getQuickActions(): array
	{
		return [
			[
				'action' => 'generate',
				'label' => $this->msg('semanticschemas-generate')->text(),
				'description' => $this->msg('semanticschemas-generate-description')->text(),
			],
			[
				'action' => 'validate',
				'label' => $this->msg('semanticschemas-validate')->text(),
				'description' => $this->msg('semanticschemas-validate-description')->text(),
			],
		];
	}

	/**
	 * Resolve the namespace ID used for Semantic MediaWiki properties.
	 *
	 * @return int
	 */
	private function getPropertyNamespace(): int
	{
		return defined('SMW_NS_PROPERTY') ? constant('SMW_NS_PROPERTY') : NS_MAIN;
	}

	/**
	 * Overview page: summarises current schema + category/template/form status.
	 */
	private function showOverview(): void
	{
		$output = $this->getOutput();
		$output->setPageTitle($this->msg('semanticschemas-overview')->text());

		$inspector = new OntologyInspector();
		$stats = $inspector->getStatistics();
		$stateManager = new StateManager();
		$state = $stateManager->getFullState();

		// Check sync status
		$isDirty = $stateManager->isDirty();
		$lastChange = $state['lastChangeTimestamp'] ?? null;
		$lastGenerated = $state['generated'] ?? null;
		$sourceSchemaHash = $state['sourceSchemaHash'] ?? null;

		$statusMessage = $isDirty
			? $this->msg('semanticschemas-status-out-of-sync')->text()
			: $this->msg('semanticschemas-status-in-sync')->text();

		$hero = Html::rawElement(
			'div',
			['class' => 'semanticschemas-hero'],
			Html::rawElement(
				'div',
				[],
				Html::element(
					'h1',
					[],
					$this->msg('semanticschemas')->text() . ' — ' . $this->msg('semanticschemas-overview')->text()
				) .
				Html::element(
					'p',
					[],
					$this->msg('semanticschemas-overview-hero-description')->text()
				)
			) .
			Html::rawElement(
				'div',
				['class' => 'semanticschemas-hero-status'],
				Html::rawElement(
					'span',
					['class' => 'semanticschemas-status-chip ' . ($isDirty ? 'is-dirty' : 'is-clean')],
					$statusMessage
				) .
				($isDirty
					? Html::element(
						'a',
						[
							'href' => $this->getPageTitle('generate')->getLocalURL(),
							'class' => 'mw-ui-button mw-ui-progressive'
						],
						$this->msg('semanticschemas-status-generate-link')->text()
					)
					: '')
			)
		);

		$categoryCount = (int) ($stats['categoryCount'] ?? 0);
		$propertyCount = (int) ($stats['propertyCount'] ?? 0);
		$subobjectCount = (int) ($stats['subobjectCount'] ?? 0);
		$lang = $this->getLanguage();

		$summaryGrid = Html::rawElement(
			'div',
			['class' => 'semanticschemas-summary-grid'],
			$this->renderStatCard(
				$this->msg('semanticschemas-label-categories')->text(),
				$lang->formatNum($categoryCount),
				$this->msg('semanticschemas-categories-count')->numParams($categoryCount)->text()
			) .
			$this->renderStatCard(
				$this->msg('semanticschemas-label-properties')->text(),
				$lang->formatNum($propertyCount),
				$this->msg('semanticschemas-properties-count')->numParams($propertyCount)->text()
			) .
			$this->renderStatCard(
				$this->msg('semanticschemas-label-subobjects')->text(),
				$lang->formatNum($subobjectCount),
				$this->msg('semanticschemas-subobjects-count')->numParams($subobjectCount)->text()
			) .
			$this->renderStatCard(
				$this->msg('semanticschemas-label-last-change')->text(),
				$this->formatTimestamp($lastChange)
			) .
			$this->renderStatCard(
				$this->msg('semanticschemas-label-last-generation')->text(),
				$this->formatTimestamp($lastGenerated)
			) .
			$this->renderStatCard(
				$this->msg('semanticschemas-label-schema-hash')->text(),
				$this->formatSchemaHash($sourceSchemaHash)
			)
		);

		$quickActions = '';
		foreach ($this->getQuickActions() as $action) {
			$quickActions .= Html::rawElement(
				'a',
				[
					'href' => $this->getPageTitle($action['action'])->getLocalURL(),
					'class' => 'semanticschemas-quick-action'
				],
				Html::element('strong', [], $action['label']) .
				Html::rawElement('span', [], $action['description'])
			);
		}

		$quickActionsCard = Html::rawElement(
			'div',
			['class' => 'semanticschemas-card'],
			Html::rawElement(
				'div',
				['class' => 'semanticschemas-card-header'],
				Html::element(
					'h3',
					['class' => 'semanticschemas-card-title'],
					$this->msg('semanticschemas-overview-quick-actions')->text()
				) .
				Html::element(
					'p',
					['class' => 'semanticschemas-card-subtitle'],
					$this->msg('semanticschemas-overview-quick-actions-subtitle')->text()
				)
			) .
			Html::rawElement('div', ['class' => 'semanticschemas-quick-actions'], $quickActions)
		);

		$categoryCard = Html::rawElement(
			'div',
			['class' => 'semanticschemas-card'],
			Html::rawElement(
				'div',
				['class' => 'semanticschemas-card-header'],
				Html::element(
					'h3',
					['class' => 'semanticschemas-card-title'],
					$this->msg('semanticschemas-overview-summary')->text()
				) .
				Html::element(
					'p',
					['class' => 'semanticschemas-card-subtitle'],
					$this->msg('semanticschemas-overview-categories-subtitle')->text()
				)
			) .
			Html::rawElement('div', ['class' => 'semanticschemas-table-wrapper'], $this->getCategoryStatusTable())
		);

		$content = $hero . $summaryGrid . $quickActionsCard . $categoryCard;

		$output->addHTML($this->wrapShell($content));
	}

	/**
	 * Status table: each category + whether templates/forms/display exist.
	 *
	 * @return string HTML
	 */
	private function getCategoryStatusTable(): string
	{
		$categoryStore = new WikiCategoryStore();
		$propertyStore = new WikiPropertyStore();
		$templateGenerator = new TemplateGenerator();
		$formGenerator = new FormGenerator();
		$displayGenerator = new DisplayStubGenerator();
		$stateManager = new StateManager();
		$hashComputer = new PageHashComputer();
		$pageCreator = new PageCreator();

		$categories = $categoryStore->getAllCategories();

		if (empty($categories)) {
			return Html::rawElement(
				'div',
				['class' => 'semanticschemas-empty-state'],
				$this->msg('semanticschemas-overview-no-categories')->text()
			);
		}

		// Get stored hashes for comparison
		$storedHashes = $stateManager->getPageHashes();
		$modifiedPages = [];

		// Check each category and its properties using schema-based hashes
		foreach ($categories as $category) {
			$categoryName = $category->getName();
			$pageName = "Category:$categoryName";

			$currentHash = $hashComputer->computeCategoryModelHash($category);
			$storedHash = $storedHashes[$pageName]['generated'] ?? '';
			if ($storedHash !== '' && $currentHash !== $storedHash) {
				$modifiedPages[$pageName] = true;
			}

			// Check all properties used by this category
			$allProperties = $category->getAllProperties();
			foreach ($allProperties as $propertyName) {
				$propPageName = "Property:$propertyName";
				if (isset($modifiedPages[$propPageName])) {
					continue; // Already checked
				}

				$propModel = $propertyStore->readProperty($propertyName);
				if ($propModel) {
					$currentHash = $hashComputer->computePropertyModelHash($propModel);
					$storedHash = $storedHashes[$propPageName]['generated'] ?? '';
					if ($storedHash !== '' && $currentHash !== $storedHash) {
						$modifiedPages[$propPageName] = true;
					}
				}
			}
		}

		$html = Html::openElement(
			'table',
			['class' => 'wikitable sortable semanticschemas-table']
		);

		$html .= Html::openElement('thead');
		$html .= Html::openElement('tr');
		$html .= Html::element('th', [], 'Category');
		$html .= Html::element('th', [], 'Parents');
		$html .= Html::element('th', [], 'Properties');
		$html .= Html::element('th', [], 'Template');
		$html .= Html::element('th', [], 'Form');
		$html .= Html::element('th', [], 'Display');
		$html .= Html::element('th', [], $this->msg('semanticschemas-status-modified-outside')->text());
		$html .= Html::closeElement('tr');
		$html .= Html::closeElement('thead');

		$html .= Html::openElement('tbody');
		foreach ($categories as $category) {
			$name = $category->getName();
			$pageName = "Category:$name";

			// Check if this category or any of its properties are modified
			$isModified = isset($modifiedPages["Category:$name"]);
			if (!$isModified) {
				// Check if any properties are modified
				foreach ($category->getAllProperties() as $propName) {
					if (isset($modifiedPages["Property:$propName"])) {
						$isModified = true;
						break;
					}
				}
			}

			$html .= Html::openElement('tr');
			$html .= Html::element('td', [], $name);
			$html .= Html::element('td', [], (string) count($category->getParents()));
			$html .= Html::element('td', [], (string) count($category->getAllProperties()));
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderBadgeLink(
					$templateGenerator->semanticTemplateExists($name),
					$this->msg('semanticschemas-badge-available')->text(),
					$this->msg('semanticschemas-badge-missing')->text(),
					$this->getTemplateTitle($name)
				)
			);
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderBadgeLink(
					$formGenerator->formExists($name),
					$this->msg('semanticschemas-badge-available')->text(),
					$this->msg('semanticschemas-badge-missing')->text(),
					$this->getFormTitle($name)
				)
			);
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderBadgeLink(
					$displayGenerator->displayStubExists($name),
					$this->msg('semanticschemas-badge-available')->text(),
					$this->msg('semanticschemas-badge-missing')->text(),
					$this->getDisplayTitle($name)
				)
			);
			$html .= Html::rawElement(
				'td',
				[],
				$this->renderBadge(
					!$isModified,
					$this->msg('semanticschemas-badge-clean')->text(),
					$this->msg('semanticschemas-badge-review')->text(),
					'is-ok',
					'is-alert'
				)
			);
			$html .= Html::closeElement('tr');
		}
		$html .= Html::closeElement('tbody');
		$html .= Html::closeElement('table');

		return $html;
	}

	/**
	 * Validate wiki state against expected invariants.
	 */
	private function showValidate(): void
	{
		$output = $this->getOutput();
		$output->setPageTitle($this->msg('semanticschemas-validate-title')->text());

		$inspector = new OntologyInspector();
		$result = $inspector->validateWikiState();

		$body = Html::rawElement(
			'div',
			['class' => 'semanticschemas-card-header'],
			Html::element(
				'h3',
				['class' => 'semanticschemas-card-title'],
				$this->msg('semanticschemas-validate-title')->text()
			) .
			Html::element(
				'p',
				['class' => 'semanticschemas-card-subtitle'],
				$this->msg('semanticschemas-validate-description')->text()
			)
		);

		if (empty($result['errors'])) {
			$body .= Html::successBox(
				$this->msg('semanticschemas-validate-success')->text()
			);
		} else {
			$body .= Html::element(
				'h3',
				[],
				$this->msg('semanticschemas-validate-errors')->text()
			);
			$body .= Html::openElement('ul');
			foreach ($result['errors'] as $error) {
				$body .= Html::element('li', [], $error);
			}
			$body .= Html::closeElement('ul');
		}

		if (!empty($result['warnings'])) {
			$body .= Html::element(
				'h3',
				[],
				$this->msg('semanticschemas-validate-warnings')->text()
			);
			$body .= Html::openElement('ul');
			foreach ($result['warnings'] as $warning) {
				$body .= Html::element('li', [], $warning);
			}
			$body .= Html::closeElement('ul');
		}

		$modifiedPages = $result['modifiedPages'] ?? [];
		if (!empty($modifiedPages)) {
			$body .= Html::element(
				'h3',
				[],
				$this->msg('semanticschemas-validate-modified-pages')
					->numParams(count($modifiedPages))
					->text()
			);
			$body .= Html::openElement('ul');
			foreach ($modifiedPages as $pageName) {
				$body .= Html::element('li', [], $pageName);
			}
			$body .= Html::closeElement('ul');
		}

		$output->addHTML(
			$this->wrapShell(
				Html::rawElement('div', ['class' => 'semanticschemas-card'], $body)
			)
		);
	}

	/**
	 * Show the "Generate" page for regenerating templates/forms/display.
	 */
	private function showGenerate(): void
	{
		$output = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle($this->msg('semanticschemas-generate-title')->text());

		if ($request->wasPosted() && $request->getVal('action') === 'generate') {
			if (!$this->getUser()->matchEditToken($request->getVal('token'))) {
				$output->addHTML(Html::errorBox('Invalid edit token'));
				return;
			}
			$this->processGenerate();
			return;
		}

		$categoryStore = new WikiCategoryStore();
		$categories = $categoryStore->getAllCategories();

		$form = Html::openElement('form', [
			'method' => 'post',
			'action' => $this->getPageTitle('generate')->getLocalURL()
		]);

		$form .= Html::openElement('div', ['class' => 'semanticschemas-form-group']);
		$form .= Html::element(
			'label',
			[],
			$this->msg('semanticschemas-generate-category')->text()
		);
		$form .= Html::openElement('select', ['name' => 'category']);
		$form .= Html::element(
			'option',
			['value' => ''],
			$this->msg('semanticschemas-generate-all')->text()
		);
		foreach ($categories as $category) {
			$name = $category->getName();
			$form .= Html::element(
				'option',
				['value' => $name],
				$name
			);
		}
		$form .= Html::closeElement('select');
		$form .= Html::closeElement('div');

		$form .= Html::openElement('div', ['class' => 'semanticschemas-form-group']);
		$form .= Html::element('input', [
			'type' => 'checkbox',
			'name' => 'generate-display',
			'value' => '1',
			'id' => 'generate-display-check'
		]);
		$form .= Html::element(
			'label',
			['for' => 'generate-display-check', 'style' => 'display:inline; margin-left: 0.5em;'],
			"Force update display templates (e.g. Template:Category/display)"
		);
		$form .= Html::element(
			'p',
			['class' => 'semanticschemas-form-help', 'style' => 'margin-top: 0.25em; color: #72777d; font-size: 0.85em;'],
			"Warning: This replaces any manual customizations to the display structure."
		);
		$form .= Html::closeElement('div');

		$form .= Html::hidden('action', 'generate');
		$form .= Html::hidden('token', $this->getUser()->getEditToken());

		$form .= Html::submitButton(
			$this->msg('semanticschemas-generate-button')->text(),
			['class' => 'mw-ui-button mw-ui-progressive']
		);

		$form .= Html::closeElement('form');

		$helper = Html::rawElement(
			'div',
			['class' => 'semanticschemas-help-card'],
			Html::element('strong', [], $this->msg('semanticschemas-generate-title')->text()) .
			Html::openElement('ul') .
			Html::element('li', [], $this->msg('semanticschemas-generate-description')->text()) .
			Html::element('li', [], $this->msg('semanticschemas-generate-tip')->text()) .
			Html::element('li', [], $this->msg('semanticschemas-status-modified-outside')->text()) .
			Html::closeElement('ul')
		);

		$card = Html::rawElement(
			'div',
			['class' => 'semanticschemas-card'],
			Html::rawElement(
				'div',
				['class' => 'semanticschemas-card-header'],
				Html::element(
					'h3',
					['class' => 'semanticschemas-card-title'],
					$this->msg('semanticschemas-generate-title')->text()
				) .
				Html::element(
					'p',
					['class' => 'semanticschemas-card-subtitle'],
					$this->msg('semanticschemas-generate-description')->text()
				)
			) .
			Html::rawElement(
				'div',
				['class' => 'semanticschemas-form-grid'],
				Html::rawElement('div', [], $form) .
				Html::rawElement('div', [], $helper)
			)
		);

		$output->addHTML($this->wrapShell($card));
	}

	/**
	 * Process "Generate" POST:
	 *   - Build inheritance graph from current categories.
	 *   - For each category: compute effective category + ancestor chain.
	 *   - Invoke TemplateGenerator, FormGenerator (with ancestors), DisplayStubGenerator.
	 */
	private function processGenerate(): void
	{
		$output = $this->getOutput();
		$request = $this->getRequest();

		// Rate limiting for generation (expensive operation)
		if ($this->checkRateLimit('generate')) {
			$output->addHTML(Html::errorBox(
				$this->msg('semanticschemas-ratelimit-exceeded')
					->params(self::RATE_LIMIT_PER_HOUR)
					->text()
			));
			return;
		}

		$categoryName = trim($request->getText('category'));
		$categoryStore = new WikiCategoryStore();
		$templateGenerator = new TemplateGenerator();
		$formGenerator = new FormGenerator();
		$displayGenerator = new DisplayStubGenerator();

		try {
			// Show debug details if requested
			if ($request->getBool('debug') && !empty($allResult['details'])) {
				$output->addHTML(
					Html::element(
						'div',
						['class' => 'semanticschemas-progress-item'],
						'Property template details:'
					)
				);
				foreach ($allResult['details'] as $detail) {
					$output->addHTML(
						Html::element(
							'div',
							['class' => 'semanticschemas-progress-item'],
							'    ' . $detail
						)
					);
				}
			}

			// Step 2: Determine target categories
			if ($categoryName === '') {
				$categories = $categoryStore->getAllCategories();
			} else {
				$single = $categoryStore->readCategory($categoryName);
				$categories = $single ? [$single] : [];
			}

			if (empty($categories)) {
				$output->addHTML(Html::errorBox(
					$this->msg('semanticschemas-generate-no-categories')->text()
				));
				return;
			}

			$progressContainerOpen = true;
			$output->addHTML(
				Html::openElement('div', ['class' => 'semanticschemas-progress']) .
				Html::element('p', [], $this->msg('semanticschemas-generate-inprogress')->text()) .
				Html::openElement('div', ['class' => 'semanticschemas-progress-log'])
			);

			// Build map for inheritance resolution
			$categoryMap = [];
			foreach ($categories as $cat) {
				$categoryMap[$cat->getName()] = $cat;
			}

			// In many cases, you want to include *all* categories in the resolver,
			// not only the subset being generated, so parent relationships resolve.
			$allCategories = $categoryStore->getAllCategories();
			foreach ($allCategories as $cat) {
				$name = $cat->getName();
				if (!isset($categoryMap[$name])) {
					$categoryMap[$name] = $cat;
				}
			}

			$resolver = new InheritanceResolver($categoryMap);

			$stateManager = new StateManager();
			$hashComputer = new PageHashComputer();
			$pageCreator = new PageCreator();
			$propertyStore = new WikiPropertyStore();
			$subobjectStore = new WikiSubobjectStore();

			$successCount = 0;
			$totalCount = count($categories);

			foreach ($categories as $category) {
				$name = $category->getName();

				// Progress feedback for each category
				$output->addHTML(
					Html::element(
						'div',
						['class' => 'semanticschemas-progress-item'],
						$this->msg('semanticschemas-generate-processing')->params($name)->text()
					)
				);

				try {
					// Effective category (merged properties)
					$effective = $resolver->getEffectiveCategory($name);
					// Ancestor chain for layout grouping (Option C)
					$ancestors = $resolver->getAncestors($name);

					$templateGenerator->generateAllTemplates($effective);
					$formGenerator->generateAndSaveForm($effective, $ancestors);

					// Only generate display stub if explicitly requested (to avoid overwriting customizations)
					if ($request->getBool('generate-display')) {
						$displayGenerator->generateOrUpdateDisplayStub($effective);
					}

					$successCount++;
				} catch (\Exception $e) {
					// Log error but continue with other categories
					wfLogWarning("SemanticSchemas generate failed for category '$name': " . $e->getMessage());
				}
			}

			// Compute and store hashes for all generated pages (schema-based)
			$pageHashes = [];

			// Hash all categories
			$allCategories = $categoryStore->getAllCategories();
			foreach ($allCategories as $category) {
				$categoryName = $category->getName();
				$hash = $hashComputer->computeCategoryModelHash($category);
				$pageHashes["Category:$categoryName"] = $hash;
			}

			// Hash all properties
			$allProperties = $propertyStore->getAllProperties();
			foreach ($allProperties as $property) {
				$propertyName = $property->getName();
				$hash = $hashComputer->computePropertyModelHash($property);
				$pageHashes["Property:$propertyName"] = $hash;
			}

			// Hash all subobjects
			$allSubobjects = $subobjectStore->getAllSubobjects();
			foreach ($allSubobjects as $subobject) {
				$subobjectName = $subobject->getName();
				$hash = $hashComputer->computeSubobjectModelHash($subobject);
				$pageHashes["Subobject:$subobjectName"] = $hash;
			}

			// Store hashes and clear dirty flag
			if (!empty($pageHashes)) {
				$stateManager->setPageHashes($pageHashes);
				$stateManager->clearDirty();
			}

			if ($progressContainerOpen) {
				$output->addHTML(
					Html::closeElement('div') . // closes log
					Html::closeElement('div')   // closes progress container
				);
				$progressContainerOpen = false;
			}

			// Log the operation
			$this->logOperation('generate', 'Template/form generation completed', [
				'categoryFilter' => $categoryName ?: 'all',
				'categoriesProcessed' => $successCount,
				'categoriesTotal' => $totalCount,
				'pagesHashed' => count($pageHashes),
			]);

			$output->addHTML(
				Html::successBox(
					$this->msg('semanticschemas-generate-success')
						->numParams($successCount, $totalCount)
						->text()
				)
			);
		} catch (\Exception $e) {
			if ($progressContainerOpen) {
				$output->addHTML(
					Html::closeElement('div') .
					Html::closeElement('div')
				);
			}
			// Log exception
			$this->logOperation('generate', 'Generation exception: ' . $e->getMessage(), [
				'exception' => get_class($e),
				'categoryFilter' => $categoryName ?? '',
			]);

			$output->addHTML(Html::errorBox(
				$this->msg('semanticschemas-generate-error')->params($e->getMessage())->text()
			));
		}
	}

	/**
	 * Show diff page: compare an external schema to current wiki-derived schema.
	 */
	private function showDiff(): void
	{
		$output = $this->getOutput();
		$request = $this->getRequest();

		$output->setPageTitle($this->msg('semanticschemas-diff-title')->text());

		if ($request->wasPosted() && $request->getVal('action') === 'diff') {
			if (!$this->getUser()->matchEditToken($request->getVal('token'))) {
				$output->addHTML(Html::errorBox('Invalid edit token'));
				return;
			}
			$this->processDiff();
			return;
		}

		$form = Html::openElement('form', [
			'method' => 'post',
			'enctype' => 'multipart/form-data',
			'action' => $this->getPageTitle('diff')->getLocalURL()
		]);

		$form .= Html::openElement('div', ['class' => 'semanticschemas-form-group']);
		$form .= Html::element(
			'label',
			[],
			$this->msg('semanticschemas-diff-file')->text()
		);
		$form .= Html::element('textarea', [
			'name' => 'schematext',
			'rows' => '12',
			'cols' => '80'
		], '');
		$form .= Html::closeElement('div');

		$form .= Html::hidden('action', 'diff');
		$form .= Html::hidden('token', $this->getUser()->getEditToken());

		$form .= Html::submitButton(
			$this->msg('semanticschemas-diff-button')->text(),
			['class' => 'mw-ui-button mw-ui-progressive']
		);

		$form .= Html::closeElement('form');

		$card = Html::rawElement(
			'div',
			['class' => 'semanticschemas-card'],
			Html::rawElement(
				'div',
				['class' => 'semanticschemas-card-header'],
				Html::element(
					'h3',
					['class' => 'semanticschemas-card-title'],
					$this->msg('semanticschemas-diff-title')->text()
				) .
				Html::element(
					'p',
					['class' => 'semanticschemas-card-subtitle'],
					$this->msg('semanticschemas-diff-description')->text()
				)
			) .
			$form
		);

		$output->addHTML($this->wrapShell($card));
	}

	/**
	 * Process diff submission: external schema vs current wiki schema.
	 */
	private function processDiff(): void
	{
		$output = $this->getOutput();
		$request = $this->getRequest();

		try {
			$content = $request->getText('schematext');
			if (trim($content) === '') {
				$output->addHTML(Html::errorBox('No schema provided'));
				return;
			}

			$loader = new SchemaLoader();
			$fileSchema = $loader->loadFromContent($content);

			// NOTE: Diff functionality has been moved to a future schema
			// management extension. This code path is kept only as a
			// reference and no longer executes via the UI.
			$inspector = new OntologyInspector();
			$wikiSchema = $inspector->validateWikiState();

			$comparer = new SchemaComparer();
			$diff = $comparer->compare($fileSchema, $wikiSchema);
			$summary = $comparer->generateSummary($diff);

			$output->addHTML(
				Html::element('pre', [], $summary)
			);
		} catch (\Exception $e) {
			$output->addHTML(Html::errorBox('Error: ' . $e->getMessage()));
		}
	}

	/**
	 * Show hierarchy visualization tab.
	 * 
	 * Provides a simple form to select a category and displays:
	 * - Inheritance tree (parents, grandparents, etc.)
	 * - Inherited properties with source category and required/optional status
	 */
	private function showHierarchy(): void
	{
		$output = $this->getOutput();
		$request = $this->getRequest();
		$output->setPageTitle($this->msg('semanticschemas-hierarchy-title')->text());

		// Add the hierarchy module
		$output->addModules('ext.semanticschemas.hierarchy');

		// Form for category selection
		$form = Html::openElement('form', [
			'method' => 'get',
			'class' => 'ss-hierarchy-special-form',
		]);

		// Hidden field to maintain the subpage
		$form .= Html::element('input', [
			'type' => 'hidden',
			'name' => 'title',
			'value' => $this->getPageTitle('hierarchy')->getPrefixedText(),
		]);

		// Category input field
		$form .= Html::element('label', [
			'for' => 'ss-hierarchy-category-input',
		], $this->msg('semanticschemas-hierarchy-category-label')->text());

		$categoryValue = $request->getText('category', '');
		$form .= Html::element('input', [
			'type' => 'text',
			'id' => 'ss-hierarchy-category-input',
			'name' => 'category',
			'value' => $categoryValue,
			'placeholder' => 'e.g., PhDStudent',
		]);

		// Submit button
		$form .= Html::element('button', [
			'type' => 'submit',
			'class' => 'mw-ui-button mw-ui-progressive',
		], $this->msg('semanticschemas-hierarchy-show-button')->text());

		$form .= Html::closeElement('form');

		// Container for hierarchy visualization
		// If a category was submitted, add it as a data attribute for JS to pick up
		$containerAttrs = [
			'id' => 'ss-hierarchy-container',
			'class' => 'ss-hierarchy-block',
		];
		if ($categoryValue !== '') {
			$containerAttrs['data-category'] = $categoryValue;
		}
		$container = Html::rawElement('div', $containerAttrs, '');

		// Build the card
		$card = Html::rawElement(
			'div',
			['class' => 'semanticschemas-card'],
			Html::rawElement(
				'div',
				['class' => 'semanticschemas-card-header'],
				Html::element(
					'h3',
					['class' => 'semanticschemas-card-title'],
					$this->msg('semanticschemas-hierarchy-title')->text()
				) .
				Html::element(
					'p',
					['class' => 'semanticschemas-card-subtitle'],
					$this->msg('semanticschemas-hierarchy-tree-title')->text()
				)
			) .
			$form .
			$container
		);

		$output->addHTML($this->wrapShell($card));
	}

	/**
	 * Special page group name.
	 *
	 * @return string
	 */
	protected function getGroupName()
	{
		return 'wiki';
	}
}
