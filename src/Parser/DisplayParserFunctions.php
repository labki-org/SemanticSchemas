<?php

namespace MediaWiki\Extension\StructureSync\Parser;

use MediaWiki\Extension\StructureSync\Display\DisplayRenderer;
use Parser;
use PPFrame;

/**
 * DisplayParserFunctions
 * ----------------------
 * Registers and handles parser functions:
 * - #StructureSyncRenderAllProperties
 * - #StructureSyncRenderSection
 */
class DisplayParserFunctions
{

    /** @var DisplayRenderer */
    private $renderer;

    public function __construct(DisplayRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new DisplayRenderer();
    }

    /**
     * Register parser functions.
     *
     * @param Parser $parser
     */
    public static function onParserFirstCallInit(Parser $parser)
    {
        $instance = new self();

        $parser->setFunctionHook(
            'StructureSyncRenderAllProperties',
            [$instance, 'renderAllProperties'],
            SFH_OBJECT_ARGS
        );

        $parser->setFunctionHook(
            'StructureSyncRenderSection',
            [$instance, 'renderSection'],
            SFH_OBJECT_ARGS
        );
    }

    /**
     * Handle #StructureSyncRenderAllProperties:CategoryName
     */
    public function renderAllProperties(Parser $parser, PPFrame $frame, array $args)
    {
        $categoryName = isset($args[0]) ? trim($frame->expand($args[0])) : '';

        if ($categoryName === '') {
            return '';
        }

        $html = $this->renderer->renderAllSections($categoryName, $frame);
        return [$html, 'noparse' => true, 'isHTML' => true];
    }

    /**
     * Handle #StructureSyncRenderSection:CategoryName|SectionName
     */
    public function renderSection(Parser $parser, PPFrame $frame, array $args)
    {
        $categoryName = isset($args[0]) ? trim($frame->expand($args[0])) : '';
        $sectionName = isset($args[1]) ? trim($frame->expand($args[1])) : '';

        if ($categoryName === '' || $sectionName === '') {
            return '';
        }

        $html = $this->renderer->renderSection($categoryName, $sectionName, $frame);
        return [$html, 'noparse' => true, 'isHTML' => true];
    }
}
