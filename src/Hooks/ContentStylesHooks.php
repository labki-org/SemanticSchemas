<?php

namespace MediaWiki\Extension\SemanticSchemas\Hooks;

use MediaWiki\Output\OutputPage;
use Skin;

/**
 * ContentStylesHooks
 *
 * Loads the small `ext.semanticschemas.basecontent` styles module on
 * every page so the class-based styles for SemanticSchemas wiki-content
 * templates (`source-semanticschemas-sidebox`, `…-section-header`,
 * `…-backlinks-header`) render correctly anywhere those templates are
 * transcluded — category pages, content pages, previews.
 *
 * Loading on every page (rather than detecting template usage from
 * ParserOutput) keeps the wiring simple; the module is style-only and
 * tiny, so the load.php cost is negligible and ResourceLoader bundles it
 * with other style modules.
 *
 * @suppress PhanUnreferencedClass
 */
class ContentStylesHooks {

	/**
	 * Hook: BeforePageDisplay
	 *
	 * @suppress PhanUnreferencedPublicMethod
	 */
	public function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		$out->addModuleStyles( [ 'ext.semanticschemas.basecontent' ] );
	}

}
