<?php

namespace MediaWiki\Extension\StructureSync\Generator;

use MediaWiki\Extension\StructureSync\Schema\CategoryModel;
use MediaWiki\Extension\StructureSync\Store\PageCreator;
use MediaWiki\Extension\StructureSync\Store\WikiPropertyStore;

/**
 * Generates static display templates for Categories.
 *
 * This generator produces the 'Template:<Category>/display' page, which contains
 * a purely static wikitext definition (e.g., a table) where property values are
 * passed into specific render templates (e.g., {{Template:Property/Email}}).
 *
 * This "Generation-Time Resolution" replaces the older dynamic runtime system,
 * ensuring reliability, cacheability, and compatibility with the standard MediaWiki parser.
 */
class DisplayStubGenerator
{

    private PageCreator $pageCreator;
    private WikiPropertyStore $propertyStore;

    public function __construct(
        ?PageCreator $pageCreator = null,
        ?WikiPropertyStore $propertyStore = null
    ) {
        $this->pageCreator = $pageCreator ?? new PageCreator();
        $this->propertyStore = $propertyStore ?? new WikiPropertyStore($this->pageCreator);
    }

    /**
     * Generate and save the display template stub.
     *
     * @param CategoryModel $category
     * @return array Result array with keys: 'created' (bool), 'updated' (bool), 'message' (string)
     */
    public function generateOrUpdateDisplayStub(CategoryModel $category): array
    {
        $titleText = $this->generateDisplayContent($category);
        if ($titleText === '') {
            return [
                'created' => false,
                'updated' => false,
                'message' => 'Failed to generate content or title.'
            ];
        }

        return [
            'created' => true,
            'updated' => true,
            'message' => "Display stub updated: $titleText"
        ];
    }

    /**
     * Internal generation logic.
     *
     * @param CategoryModel $category
     * @return string The prefixed title string of the generated page, or empty string on failure.
     */
    private function generateDisplayContent(CategoryModel $category): string
    {
        $categoryName = $category->getName();
        // Use NS_TEMPLATE constant
        $title = $this->pageCreator->makeTitle("$categoryName/display", NS_TEMPLATE);
        if (!$title) {
            return '';
        }

        $content = $this->buildWikitext($category);

        $this->pageCreator->createOrUpdatePage(
            $title,
            $content,
            "StructureSync: Update static display template for $categoryName"
        );

        return $title->getPrefixedText();
    }

    /**
     * Construct the wikitext content for the display template.
     *
     * @param CategoryModel $category
     * @return string
     */
    private function buildWikitext(CategoryModel $category): string
    {
        $content = "<includeonly>\n";
        $content .= "{| class=\"wikitable source-structuresync\"\n";
        $content .= "! Property !! Value\n";

        foreach ($category->getAllProperties() as $propName) {
            $property = $this->propertyStore->readProperty($propName);
            if ($property) {
                $label = $property->getLabel();
                $paramName = $property->getSnakeCaseName();

                // Resolve the specific render template (e.g. Template:Property/Email)
                // Defaults to Template:Property/Default if not specified/found.
                $renderTemplate = $property->getRenderTemplate();

                // Construct the template call:
                // {{ Template:Property/Email | value={{{email|}}} }}
                $valueCall = "{{" . $renderTemplate . " | value={{{" . $paramName . "|}}} }}";

                $content .= "|-\n";
                $content .= "| " . $label . " || " . $valueCall . "\n";
            }
        }

        $content .= "|}\n";
        $content .= "</includeonly><noinclude>[[Category:StructureSync-managed-display]]</noinclude>";

        return $content;
    }

    /**
     * Check if the display stub already exists.
     *
     * @param CategoryModel $category
     * @return bool
     */
    public function displayStubExists(CategoryModel $category): bool
    {
        $title = $this->pageCreator->makeTitle($category->getName() . "/display", NS_TEMPLATE);
        return $title && $this->pageCreator->pageExists($title);
    }
}
