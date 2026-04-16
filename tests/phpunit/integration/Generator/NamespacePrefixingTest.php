<?php

namespace MediaWiki\Extension\SemanticSchemas\Tests\Integration\Generator;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator;
use MediaWiki\Extension\SemanticSchemas\Schema\PropertyModel;
use MediaWiki\Extension\SemanticSchemas\Store\PageCreator;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;
use MediaWiki\Extension\SemanticSchemas\Store\WikiPropertyStore;
use MediaWiki\Language\Language;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use ReflectionMethod;

/**
 * Verifies that the namespace-prefixing wikitext produced by
 * TemplateGenerator::generatePropertyLine() evaluates correctly
 * through the MediaWiki parser.
 *
 * Each test calls the real generatePropertyLine(), extracts the value
 * expression it produces, wraps it in a visible template, and checks
 * the parser output for correct namespace handling.
 *
 * @covers \MediaWiki\Extension\SemanticSchemas\Generator\TemplateGenerator
 * @group Database
 */
class NamespacePrefixingTest extends MediaWikiIntegrationTestCase {

	/**
	 * Build a TemplateGenerator whose property store returns the given models.
	 *
	 * @param array<string, PropertyModel> $propertyMap
	 * @return TemplateGenerator
	 */
	private function makeGenerator( array $propertyMap ): TemplateGenerator {
		$propStore = $this->createMock( WikiPropertyStore::class );
		$propStore->method( 'readProperty' )
			->willReturnCallback(
				static fn ( string $name ) => $propertyMap[$name] ?? null
			);

		$services = $this->getServiceContainer();

		return new TemplateGenerator(
			new PageCreator(
				$services->getWikiPageFactory(),
				$services->getDeletePageFactory(),
			),
			$this->createMock( WikiCategoryStore::class ),
			$propStore,
			$this->createMock( Language::class )
		);
	}

	/**
	 * Call the private generatePropertyLine() and extract just the value
	 * expression (stripping the " | PropName = " prefix and any " |+sep=,"
	 * suffix, which are {{#set:}} syntax not relevant to evaluation).
	 */
	private function getValueExpression(
		TemplateGenerator $generator,
		string $propertyName,
		string $param
	): string {
		$method = new ReflectionMethod( $generator, 'generatePropertyLine' );
		$method->setAccessible( true );
		$line = $method->invoke( $generator, $propertyName, $param );

		// Strip " | PropertyName = " prefix — find first " = "
		$eqPos = strpos( $line, ' = ' );
		$this->assertNotFalse( $eqPos, "generatePropertyLine must contain ' = '" );
		$expr = substr( $line, $eqPos + 3 );

		// Strip " |+sep=," suffix ({{#set:}} multi-value separator)
		return preg_replace( '/\s*\|\+sep=,\s*$/', '', $expr );
	}

	/**
	 * Save a wiki page with the given wikitext.
	 */
	private function savePage( Title $title, string $wikitext ): void {
		$wikiPage = $this->getServiceContainer()
			->getWikiPageFactory()
			->newFromTitle( $title );
		$updater = $wikiPage->newPageUpdater(
			static::getTestSysop()->getUser()
		);
		$updater->setContent(
			SlotRecord::MAIN,
			ContentHandler::makeContent( $wikitext, $title )
		);
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment( 'Test' )
		);
	}

	/**
	 * Create a template containing the value expression, transclude it
	 * with a parameter value, and return the parser output as plain text.
	 *
	 * @param string $expression Wikitext value expression (uses {{{param|}}})
	 * @param string $param Template parameter name
	 * @param string $value Value to pass to the template
	 * @return string Stripped text from parser output
	 */
	private function evaluateExpression(
		string $expression,
		string $param,
		string $value
	): string {
		$uid = uniqid();

		$tplTitle = Title::makeTitle(
			NS_TEMPLATE, "NsPrefixTest_$uid"
		);
		$this->savePage(
			$tplTitle,
			"<includeonly>$expression</includeonly>"
		);

		$pageTitle = Title::makeTitle(
			NS_MAIN, "NsPrefixTestPage_$uid"
		);
		$this->savePage(
			$pageTitle,
			'{{' . $tplTitle->getText() . "|$param=$value}}"
		);

		$wikiPage = $this->getServiceContainer()
			->getWikiPageFactory()
			->newFromTitle( $pageTitle );
		$parserOutput = $wikiPage->getParserOutput();

		return trim( strip_tags( $parserOutput->getRawText() ) );
	}

	/* =========================================================================
	 * SINGLE-VALUE NAMESPACE PREFIXING
	 * ========================================================================= */

	public function testSingleValueBareNameGetsPrefixed(): void {
		$gen = $this->makeGenerator( [
			'Has member' => new PropertyModel( 'Has member', [
				'datatype' => 'Page',
				'allowedNamespace' => 'User',
			] ),
		] );

		$expr = $this->getValueExpression( $gen, 'Has member', 'has_member' );
		$text = $this->evaluateExpression( $expr, 'has_member', 'Alice' );

		$this->assertSame( 'User:Alice', $text );
	}

	public function testSingleValueNamespacedNameNotDoublePrefixed(): void {
		$gen = $this->makeGenerator( [
			'Has member' => new PropertyModel( 'Has member', [
				'datatype' => 'Page',
				'allowedNamespace' => 'User',
			] ),
		] );

		$expr = $this->getValueExpression( $gen, 'Has member', 'has_member' );
		$text = $this->evaluateExpression( $expr, 'has_member', 'User:Alice' );

		$this->assertSame( 'User:Alice', $text );
	}

	public function testSingleValueDifferentNamespacePreserved(): void {
		$gen = $this->makeGenerator( [
			'Has member' => new PropertyModel( 'Has member', [
				'datatype' => 'Page',
				'allowedNamespace' => 'User',
			] ),
		] );

		$expr = $this->getValueExpression( $gen, 'Has member', 'has_member' );
		$text = $this->evaluateExpression(
			$expr, 'has_member', 'Category:SomeCat'
		);

		// User typed a different namespace — preserved as-is
		$this->assertSame( 'Category:SomeCat', $text );
	}

	public function testSingleValueEmptyProducesEmpty(): void {
		$gen = $this->makeGenerator( [
			'Has member' => new PropertyModel( 'Has member', [
				'datatype' => 'Page',
				'allowedNamespace' => 'User',
			] ),
		] );

		$expr = $this->getValueExpression( $gen, 'Has member', 'has_member' );
		$text = $this->evaluateExpression( $expr, 'has_member', '' );

		$this->assertSame( '', $text );
	}

	/* =========================================================================
	 * MULTI-VALUE NAMESPACE PREFIXING
	 * ========================================================================= */

	public function testMultiValueBareNamesGetPrefixed(): void {
		$gen = $this->makeGenerator( [
			'Has members' => new PropertyModel( 'Has members', [
				'datatype' => 'Page',
				'allowsMultipleValues' => true,
				'allowedNamespace' => 'User',
			] ),
		] );

		$expr = $this->getValueExpression(
			$gen, 'Has members', 'has_members'
		);
		$text = $this->evaluateExpression(
			$expr, 'has_members', 'Alice,Bob'
		);

		$this->assertStringContainsString( 'User:Alice', $text );
		$this->assertStringContainsString( 'User:Bob', $text );
	}

	public function testMultiValueNamespacedNamesNotDoublePrefixed(): void {
		$gen = $this->makeGenerator( [
			'Has members' => new PropertyModel( 'Has members', [
				'datatype' => 'Page',
				'allowsMultipleValues' => true,
				'allowedNamespace' => 'User',
			] ),
		] );

		$expr = $this->getValueExpression(
			$gen, 'Has members', 'has_members'
		);
		$text = $this->evaluateExpression(
			$expr, 'has_members', 'User:Alice,User:Bob'
		);

		$this->assertStringContainsString( 'User:Alice', $text );
		$this->assertStringContainsString( 'User:Bob', $text );
		$this->assertStringNotContainsString( 'User:User:', $text );
	}

	public function testMultiValueMixedBareAndNamespaced(): void {
		$gen = $this->makeGenerator( [
			'Has members' => new PropertyModel( 'Has members', [
				'datatype' => 'Page',
				'allowsMultipleValues' => true,
				'allowedNamespace' => 'User',
			] ),
		] );

		$expr = $this->getValueExpression(
			$gen, 'Has members', 'has_members'
		);
		$text = $this->evaluateExpression(
			$expr, 'has_members', 'Alice,User:Bob'
		);

		$this->assertStringContainsString( 'User:Alice', $text );
		$this->assertStringContainsString( 'User:Bob', $text );
		$this->assertStringNotContainsString( 'User:User:', $text );
	}
}
