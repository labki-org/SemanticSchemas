<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Special;

use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RequestContext;

/**
 * Integration tests for Special:CreateSemanticPage.
 *
 * Tests page creation, multi-category composition, and parent-to-child
 * category replacement behavior.
 *
 * @group Database
 * @covers \MediaWiki\Extension\SemanticSchemas\Special\SpecialCreateSemanticPage
 */
class SpecialCreateSemanticPageTest extends MediaWikiIntegrationTestCase {

	private WikiCategoryStore $categoryStore;
	private PageCreator $pageCreator;

	protected function setUp(): void {
		parent::setUp();

		$services = $this->getServiceContainer();
		$this->pageCreator = new PageCreator(
			$services->getWikiPageFactory(),
		);
		$this->categoryStore = new WikiCategoryStore(
			$services->getConnectionProvider(),
			$services->getMainConfig()
		);
	}

	/* =========================================================================
	 * SINGLE-CATEGORY PAGE CREATION
	 * ========================================================================= */

	public function testSingleCategoryRedirectsToFormEdit(): void {
		$catName = 'SingleCat' . uniqid();
		$this->createCategory( $catName );
		$this->createFormPage( $catName );

		$pageName = 'TestPage' . uniqid();
		$context = $this->executeCreatePage( $pageName, [ $catName ] );

		$redirect = $context->getOutput()->getRedirect();
		$this->assertStringContainsString( 'FormEdit/' . $catName, $redirect );
		$this->assertStringContainsString( $pageName, $redirect );
	}

	/**
	 * @group Broken
	 * SMW semantic data (targetNamespace) not available in test context
	 * after executeJobs(). The logic is correct but SMW's deferred
	 * processing does not complete within the test transaction.
	 */
	public function testSingleCategoryWithNamespaceIncludesNamespaceInRedirect(): void {
		$catName = 'NsCat' . uniqid();
		$this->createCategory( $catName, "[[Has target namespace::User]]" );
		$this->createFormPage( $catName );

		// SMW needs to process semantic data before categoryStore can read targetNamespace
		$this->executeJobs();

		$pageName = 'TestPage' . uniqid();
		$context = $this->executeCreatePage( $pageName, [ $catName ] );

		$redirect = $context->getOutput()->getRedirect();
		$this->assertStringContainsString( 'User:' . $pageName, $redirect );
	}

	/* =========================================================================
	 * MULTI-CATEGORY PAGE CREATION
	 * ========================================================================= */

	public function testMultiCategoryCreatesPageWithTemplateCallsForEach(): void {
		$cat1 = 'MultiA' . uniqid();
		$cat2 = 'MultiB' . uniqid();
		$this->createCategory( $cat1 );
		$this->createCategory( $cat2 );

		$pageName = 'MultiPage' . uniqid();
		$this->executeCreatePage( $pageName, [ $cat1, $cat2 ] );

		$title = Title::makeTitleSafe( NS_MAIN, $pageName );
		$this->assertTrue( $title->exists(), 'Page should have been created' );

		$content = $this->getPageContent( $title );
		$this->assertStringContainsString( '{{' . $cat1, $content );
		$this->assertStringContainsString( '{{' . $cat2, $content );
	}

	public function testMultiCategoryPageRedirectsToCompositeForm(): void {
		$cat1 = 'CompA' . uniqid();
		$cat2 = 'CompB' . uniqid();
		$this->createCategory( $cat1 );
		$this->createCategory( $cat2 );

		$pageName = 'CompPage' . uniqid();
		$context = $this->executeCreatePage( $pageName, [ $cat1, $cat2 ] );

		$html = $context->getOutput()->getHTML();
		$this->assertStringContainsString( 'CompositeForm', $html );
	}

	/* =========================================================================
	 * ADD CATEGORY TO EXISTING PAGE
	 * ========================================================================= */

	public function testAddCategoryAppendsTemplateCallToExistingPage(): void {
		$cat1 = 'ExistA' . uniqid();
		$cat2 = 'ExistB' . uniqid();
		$this->createCategory( $cat1 );
		$this->createCategory( $cat2 );

		$pageName = 'ExistPage' . uniqid();
		$title = Title::makeTitleSafe( NS_MAIN, $pageName );

		// Create page with first category template including field values
		$this->pageCreator->createOrUpdatePage(
			$title,
			"{{" . $cat1 . "\n|has_name=Alice\n|has_age=30\n}}",
			'Initial page'
		);

		// Run jobs so parser output is populated
		$this->executeJobs();

		// Add second category
		$this->executeCreatePage( $pageName, [ $cat1, $cat2 ] );

		$content = $this->getPageContent( $title );
		$this->assertStringContainsString( '{{' . $cat1, $content );
		$this->assertStringContainsString( '{{' . $cat2, $content );
		$this->assertStringContainsString( 'has_name=Alice', $content,
			'Existing field values should be preserved' );
		$this->assertStringContainsString( 'has_age=30', $content,
			'Existing field values should be preserved' );
	}

	public function testAddCategoryDoesNotDuplicateExistingTemplate(): void {
		$cat1 = 'DupA' . uniqid();
		$cat2 = 'DupB' . uniqid();
		$this->createCategory( $cat1 );
		$this->createCategory( $cat2 );

		$pageName = 'DupPage' . uniqid();
		$title = Title::makeTitleSafe( NS_MAIN, $pageName );

		// Create page with category template
		$this->pageCreator->createOrUpdatePage(
			$title,
			"{{" . $cat1 . "\n}}",
			'Initial page'
		);

		$this->executeJobs();

		// Add same category again plus a new one
		$this->executeCreatePage( $pageName, [ $cat1, $cat2 ] );

		$content = $this->getPageContent( $title );
		// cat1 should appear exactly once
		$this->assertSame(
			1,
			substr_count( $content, '{{' . $cat1 ),
			'Existing template should not be duplicated'
		);
		$this->assertStringContainsString( '{{' . $cat2, $content );
	}

	/* =========================================================================
	 * VALIDATION
	 * ========================================================================= */

	public function testEmptyPageNameShowsError(): void {
		$cat = 'ValCat' . uniqid();

		$context = $this->executeCreatePage( '', [ $cat ] );

		$html = $context->getOutput()->getHTML();
		$this->assertStringContainsString( 'cdx-message--error', $html );
	}

	public function testNoCategoriesSelectedShowsError(): void {
		$context = $this->executeCreatePage( 'SomePage' . uniqid(), [] );

		$html = $context->getOutput()->getHTML();
		$this->assertStringContainsString( 'cdx-message--error', $html );
	}

	/* =========================================================================
	 * Helpers
	 * ========================================================================= */

	private function createCategory( string $name, string $content = "" ): void {
		$title = Title::makeTitle( NS_CATEGORY, $name );
		$this->pageCreator->createOrUpdatePage( $title, $content, '' );
	}

	private function createFormPage( string $categoryName ): void {
		$title = Title::makeTitleSafe( PF_NS_FORM, $categoryName );
		$this->pageCreator->createOrUpdatePage(
			$title,
			'<noinclude>Test form</noinclude><includeonly>{{{for template|'
				. $categoryName . '}}}{{{end template}}}</includeonly>',
			'Test form'
		);
	}

	private function executeCreatePage( string $pageName, array $categories ): RequestContext {
		$user = static::getTestSysop()->getUser();

		$request = new FauxRequest( [
			's2-action' => 'create-page',
			's2-page-name' => $pageName,
			's2-categories' => $categories,
			'wpEditToken' => $user->getEditToken(),
		], true );

		$page = $this->getServiceContainer()
			->getSpecialPageFactory()
			->getPage( 'CreateSemanticPage' );

		$context = new RequestContext();
		$context->setRequest( $request );
		$context->setUser( $user );
		$context->setTitle( $page->getPageTitle() );
		$page->setContext( $context );

		$page->execute( '' );

		return $context;
	}

	private function getPageContent( Title $title ): string {
		// Re-fetch to get latest revision
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
		$content = $page->getContent();
		return $content->serialize();
	}

	private function executeJobs(): void {
		$runner = $this->getServiceContainer()->getJobRunner();
		$runner->run( [
			'type' => false,
			'maxJobs' => 100,
			'maxTime' => 30,
		] );
	}
}
