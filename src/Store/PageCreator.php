<?php

namespace MediaWiki\Extension\StructureSync\Store;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * PageCreator
 * -----------
 * Safe creation, update, deletion, and reading of wiki pages.
 * All StructureSync writing operations flow through this class.
 */
class PageCreator {

    /** @var User */
    private User $user;

    /** @var WikiPageFactory */
    private WikiPageFactory $wikiPageFactory;

    public function __construct(?User $user = null) {
        $this->user = $user ?? User::newSystemUser( 'StructureSync', [ 'steal' => true ] );
        $services = MediaWikiServices::getInstance();
        $this->wikiPageFactory = $services->getWikiPageFactory();
    }

    /* =====================================================================
     * PAGE CREATION / UPDATE
     * ===================================================================== */

    /**
     * Create or update a wiki page with new content.
     *
     * @param Title  $title
     * @param string $content Wikitext
     * @param string $summary Edit summary
     * @param int    $flags PageUpdater flags
     *
     * @return bool
     */
    public function createOrUpdatePage(Title $title, string $content, string $summary, int $flags = 0): bool {
        try {
            $wikiPage = $this->wikiPageFactory->newFromTitle($title);
            $updater = $wikiPage->newPageUpdater($this->user);

            $contentObj = ContentHandler::makeContent($content, $title);
            $updater->setContent(SlotRecord::MAIN, $contentObj);

            $revRecord = $updater->saveRevision(
				CommentStoreComment::newUnsavedComment($summary),
				$flags
			);
			
			// MW 1.36+: saveRevision() throws on failure and returns RevisionRecord on success.
			return $revRecord !== null;
			

        } catch (\Exception $e) {
            wfLogWarning("StructureSync: Exception saving '{$title->getPrefixedText()}': " . $e->getMessage());
            return false;
        }
    }

    /* =====================================================================
     * PAGE EXISTENCE & READ
     * ===================================================================== */

    public function pageExists(Title $title): bool {
        return $title->exists();
    }

    public function getPageContent(Title $title): ?string {
        if (!$title->exists()) {
            return null;
        }

        try {
            $wikiPage = $this->wikiPageFactory->newFromTitle($title);
            $contentObj = $wikiPage->getContent();

            if ($contentObj instanceof TextContent) {
                return $contentObj->getText();
            }

            return $contentObj?->serialize() ?? null;

        } catch (\Exception $e) {
            wfLogWarning("StructureSync: Failed reading page '{$title->getPrefixedText()}': " . $e->getMessage());
            return null;
        }
    }

    /* =====================================================================
     * TITLE CREATION
     * ===================================================================== */

    /**
     * Construct a safe Title.
     */
    public function makeTitle(string $text, int $namespace): ?Title {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        try {
            return Title::makeTitleSafe($namespace, $text);
        } catch (\Exception $e) {
            wfLogWarning("StructureSync: Title creation failed for '$text': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse a prefixed page name (e.g., "Category:Name", "Property:Name", "Subobject:Name")
     * and return a Title object.
     *
     * @param string $pageName Prefixed page name (e.g., "Category:Something")
     * @return Title|null
     */
    public function titleFromPageName(string $pageName): ?Title {
        $pageName = trim($pageName);
        if ($pageName === '') {
            return null;
        }

        // Handle prefixed names like "Category:Name", "Property:Name", "Subobject:Name"
        if (preg_match('/^([^:]+):(.+)$/', $pageName, $matches)) {
            $prefix = $matches[1];
            $name = $matches[2];

            // Map prefix to namespace
            $namespace = null;
            switch (strtolower($prefix)) {
                case 'category':
                    $namespace = NS_CATEGORY;
                    break;
                case 'property':
                    $namespace = defined('SMW_NS_PROPERTY') ? constant('SMW_NS_PROPERTY') : NS_MAIN;
                    break;
                case 'subobject':
                    $namespace = defined('NS_SUBOBJECT') ? constant('NS_SUBOBJECT') : NS_MAIN;
                    break;
                default:
                    // Unknown prefix, try to parse as a regular title
                    return Title::newFromText($pageName);
            }

            return $this->makeTitle($name, $namespace);
        }

        // No prefix, try parsing as a regular title
        return Title::newFromText($pageName);
    }

    /* =====================================================================
     * DELETE
     * ===================================================================== */

    /**
     * Delete a page if it exists.
     */
    public function deletePage(Title $title, string $reason): bool {
        if (!$title->exists()) {
            return true;
        }

        try {
            $services = MediaWikiServices::getInstance();
            $deletePage = $services->getDeletePageFactory()->newDeletePage($title);

            $status = $deletePage->deleteUnsafe($reason, $this->user);

            if (!$status->isOK()) {
                wfLogWarning("StructureSync: Failed deleting '{$title->getPrefixedText()}': " . $status->getMessage(false));
                return false;
            }

            return true;

        } catch (\Exception $e) {
            wfLogWarning("StructureSync: Exception deleting '{$title->getPrefixedText()}': " . $e->getMessage());
            return false;
        }
    }

    /* =====================================================================
     * MARKER-BASED CONTENT UPDATES
     * ===================================================================== */

    public function updateWithinMarkers(
        string $content,
        string $newText,
        string $startMarker,
        string $endMarker
    ): string {

        $startPos = strpos($content, $startMarker);
        $endPos   = strpos($content, $endMarker);

        if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
            $before = substr($content, 0, $startPos + strlen($startMarker));
            $after  = substr($content, $endPos);

            return rtrim($before) . "\n\n" . trim($newText) . "\n\n" . ltrim($after);
        }

        // Append markers
        $out  = rtrim($content) . "\n\n";
        $out .= $startMarker . "\n";
        $out .= trim($newText) . "\n";
        $out .= $endMarker . "\n";

        return $out;
    }
}
